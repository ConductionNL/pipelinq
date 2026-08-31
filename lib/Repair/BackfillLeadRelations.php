<?php

/**
 * Pipelinq BackfillLeadRelations.
 *
 * Repair step that gives every stored lead the pipeline and client the schema
 * now requires.
 *
 * A lead belongs to a pipeline and to a client; contact and products are
 * optional. Both references existed before this change but neither was
 * mandatory, so leads accumulated without them: measured on the development
 * instance, 11 of 17 leads had no pipeline and 8 had no client.
 *
 * 🔴 THIS STEP MUST RUN, AND IT MUST RUN AFTER InitializeSettings.
 * OpenRegister validates `required` on EVERY save, not only on create — a save
 * missing a required property is rejected whole, with
 * `The required property (x) is missing`. So the moment the new schema lands,
 * an un-backfilled lead cannot be edited AT ALL: the user gets a 400 on a
 * record they did not break, on a field the form may not even show. That is
 * why this ships in the same release as the constraint rather than after it.
 *
 * Writing the missing values is itself a valid save, so running after the
 * import is safe: this step supplies exactly the fields the new rule demands.
 *
 * Clients are MATCHED before they are created. A lead titled
 * "Gemeente Amsterdam - CRM implementatie 2026" almost always belongs to a
 * client that already exists, and minting a second one would split the
 * customer's history across two records — worse than the missing link. A
 * client is only created when no stored name occurs in the lead's title.
 *
 * Idempotent: a lead that already has both references is skipped, so a re-run
 * is a no-op.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @spec openspec/specs/lead-management/spec.md#requirement-lead-crud-mvp
 */

declare(strict_types=1);

namespace OCA\Pipelinq\Repair;

use OCA\Pipelinq\AppInfo\Application;
use OCA\Pipelinq\Service\LeadClientResolver;
use OCP\IAppConfig;
use OCP\IGroupManager;
use OCP\IUser;
use OCP\Migration\IOutput;
use OCP\Migration\IRepairStep;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Repair step: backfill lead.pipeline and lead.client before they are required.
 *
 * @spec openspec/specs/lead-management/spec.md#requirement-lead-crud-mvp
 */
class BackfillLeadRelations implements IRepairStep {

	/**
	 * Upper bound on rows fetched per schema.
	 *
	 * @var int
	 */
	private const BATCH_LIMIT = 10000;

	/**
	 * Constructor.
	 *
	 * @param IAppConfig         $appConfig   App config, source of the register and schema ids.
	 * @param ContainerInterface $container   Container, used to reach OpenRegister's ObjectService.
	 * @param LeadClientResolver $clientResolver Matches or creates the client a lead belongs to.
	 * @param IGroupManager      $groupManager Group manager, used to resolve an acting admin.
	 * @param LoggerInterface    $logger      PSR logger.
	 */
	public function __construct(
		private readonly IAppConfig $appConfig,
		private readonly ContainerInterface $container,
		private readonly LeadClientResolver $clientResolver,
		private readonly IGroupManager $groupManager,
		private readonly LoggerInterface $logger,
	) {
	}//end __construct()

	/**
	 * Get the repair step name.
	 *
	 * @return string Name.
	 *
	 * @spec openspec/specs/repair-steps/spec.md
	 */
	public function getName(): string {
		return 'Backfill the pipeline and client every lead now requires';
	}//end getName()

	/**
	 * Resolve an admin to act as while saving.
	 *
	 * A repair step has no session, so OpenRegister's folder ACL check has no
	 * acting user and denies the write for any object that owns a file folder.
	 * Saving as an admin gives that check someone to authorise, without
	 * loosening the check itself.
	 *
	 * @return IUser|null The first admin, or null when none exists.
	 */
	private function actingAdmin(): ?IUser {
		$admins = ($this->groupManager->get('admin')?->getUsers() ?? []);

		return (array_values($admins)[0] ?? null);
	}//end actingAdmin()

	/**
	 * Normalise an OpenRegister row into a plain array.
	 *
	 * @param mixed $row The row as returned by findAll().
	 *
	 * @return array<string,mixed>|null The array form, or null when unusable.
	 */
	private function toArray(mixed $row): ?array {
		if (is_array($row) === true) {
			return $row;
		}

		if (is_object($row) === true && method_exists($row, 'jsonSerialize') === true) {
			$data = $row->jsonSerialize();

			if (is_array($data) === true) {
				return $data;
			}

			return null;
		}

		return null;
	}//end toArray()

	/**
	 * Read every row of one schema.
	 *
	 * RBAC is off for the same reason the writes turn it off: on the CLI there
	 * is no session, so an RBAC-filtered read returns only what 'Anonymous' may
	 * see and the backfill would quietly cover a fraction of the rows.
	 *
	 * @param object $objectService OpenRegister ObjectService.
	 * @param string $register      Register id.
	 * @param string $schema        Schema id.
	 *
	 * @return array<int,array<string,mixed>> The rows.
	 */
	private function readAll(object $objectService, string $register, string $schema): array {
		try {
			$rows = $objectService->findAll(
				config: [
					'filters' => [
						'register' => $register,
						'schema' => $schema,
					],
					'limit' => self::BATCH_LIMIT,
				],
				_rbac: false,
				_multitenancy: false,
			);
		} catch (Throwable $e) {
			$this->logger->warning(
				'BackfillLeadRelations: findAll failed',
				['schema' => $schema, 'error' => $e->getMessage()]
			);

			return [];
		}//end try

		$out = [];
		foreach ($rows as $row) {
			$data = $this->toArray(row: $row);
			if ($data !== null) {
				$out[] = $data;
			}
		}

		return $out;
	}//end readAll()



	/**
	 * Pick the pipeline to put un-assigned leads on.
	 *
	 * Any pipeline beats none: an unassigned lead cannot be saved at all, while
	 * one on the wrong pipeline is visible and can be moved.
	 *
	 * @param array<int,array<string,mixed>> $pipelines Stored pipelines.
	 *
	 * @return string|null The pipeline uuid, or null when none exists.
	 */
	private function pickPipeline(array $pipelines): ?string {
		$fallback = null;
		foreach ($pipelines as $pipeline) {
			$id = (string)($pipeline['id'] ?? '');
			if ($id === '') {
				continue;
			}

			if (($pipeline['isDefault'] ?? false) === true) {
				return $id;
			}

			if ($fallback === null) {
				$fallback = $id;
			}
		}

		return $fallback;
	}//end pickPipeline()


	/**
	 * Execute an OpenRegister write under the scoped system-operation context.
	 *
	 * Repair steps run without a user session, so OpenRegister RBAC denies every
	 * write as 'Anonymous'. runAsSystem scopes trusted-system elevation to the
	 * callable; an older OpenRegister without it falls back to a direct call.
	 *
	 * @param callable $operation The write to perform.
	 *
	 * @return mixed The operation's result.
	 *
	 * @spec exclude system-context adoption: a back-compat elevation shim around an OR write, with no behavioural spec surface.
	 */
	private function execAsSystem(callable $operation): mixed {
		try {
			$objectService = $this->container->get('OCA\\OpenRegister\\Service\\ObjectService');
		} catch (Throwable $e) {
			return $operation();
		}

		if (method_exists($objectService, 'runAsSystem') === true) {
			return $objectService->runAsSystem($operation);
		}

		return $operation();
	}//end execAsSystem()

	/**
	 * Compute the fields one lead is missing.
	 *
	 * @param array<string,mixed>             $lead     The lead row.
	 * @param string|null                     $pipeline The pipeline to assign, or null when none exists.
	 * @param array<int,array<string,mixed>> $clients  Stored clients, extended in place.
	 *
	 * @return array<string,mixed>|null The fields to merge, [] when already complete,
	 *                                  or null when the lead cannot be resolved.
	 */
	private function missingFor(array $lead, ?string $pipeline, array &$clients): ?array {
		$patch = [];

		if (trim((string)($lead['pipeline'] ?? '')) === '') {
			if ($pipeline === null) {
				return null;
			}

			$patch['pipeline'] = $pipeline;
		}

		if (trim((string)($lead['client'] ?? '')) === '') {
			// Creating a client is an OpenRegister WRITE, and a repair step has
			// no session: OR's RBAC denies it as
			// "User 'Anonymous' does not have permission to 'create' objects in
			// schema 'Client'". Measured — 8 of 8 creations failed this way, and
			// impersonating an admin via IUserSession did NOT help, because OR
			// resolves the acting user itself. runAsSystem scopes the elevation
			// to this callable, the same shim InitSlaStatus and
			// UnifyClientContactIdentity use.
			$title = trim((string)($lead['title'] ?? ''));
			// A regular closure with `use (&$clients)`, NOT an arrow function:
			// `fn()` captures by VALUE, so the resolver's append of a
			// newly-created client would be thrown away and every lead for the
			// same company would mint its own duplicate.
			$client = $this->execAsSystem(
				operation: function () use ($title, &$clients): ?string {
					return $this->clientResolver->resolve(
						title: $title,
						clients: $clients
					);
				}
			);
			if ($client === null) {
				return null;
			}

			$patch['client'] = $client;
		}

		return $patch;
	}//end missingFor()

	/**
	 * Run the repair.
	 *
	 * @param IOutput $output Output.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/lead-management/spec.md#requirement-lead-crud-mvp
	 */
	public function run(IOutput $output): void {
		$register = $this->appConfig->getValueString(Application::APP_ID, 'register', '');
		$leadSchema = $this->appConfig->getValueString(Application::APP_ID, 'lead_schema', '');
		$clientSchema = $this->appConfig->getValueString(Application::APP_ID, 'client_schema', '');
		$pipelineSchema = $this->appConfig->getValueString(Application::APP_ID, 'pipeline_schema', '');

		if ($register === '' || $leadSchema === '') {
			$output->info('BackfillLeadRelations: pipelinq register or lead schema not configured, skipping');
			return;
		}

		try {
			$objectService = $this->container->get('OCA\OpenRegister\Service\ObjectService');
		} catch (Throwable $e) {
			$output->warning('BackfillLeadRelations: OpenRegister ObjectService unavailable, skipping (' . $e->getMessage() . ')');
			return;
		}

		$leads = $this->readAll(objectService: $objectService, register: $register, schema: $leadSchema);
		if ($leads === []) {
			$output->info('BackfillLeadRelations: no leads to inspect');
			return;
		}

		$clients = [];
		if ($clientSchema !== '') {
			$clients = $this->readAll(
				objectService: $objectService,
				register: $register,
				schema: $clientSchema
			);
		}

		$pipelines = [];
		if ($pipelineSchema !== '') {
			$pipelines = $this->readAll(
				objectService: $objectService,
				register: $register,
				schema: $pipelineSchema
			);
		}

		$pipeline = $this->pickPipeline(pipelines: $pipelines);
		$actingAdmin = $this->actingAdmin();

		$context = [
			'register' => $register,
			'schema' => $leadSchema,
		];
		$counts = [
			'fixed' => 0,
			'skipped' => 0,
			'stuck' => 0,
		];

		foreach ($leads as $lead) {
			$outcome = $this->backfillOne(
				lead: $lead,
				pipeline: $pipeline,
				clients: $clients,
				context: $context,
				actingAdmin: $actingAdmin,
				output: $output
			);
			$counts[$outcome]++;
		}

		$this->report(counts: $counts, output: $output);
	}//end run()

	/**
	 * Backfill one lead.
	 *
	 * @param array<string,mixed>             $lead        The lead row.
	 * @param string|null                     $pipeline    Pipeline to assign when missing.
	 * @param array<int,array<string,mixed>> $clients     Stored clients, extended in place.
	 * @param array<string,string>            $context     Register and schema ids.
	 * @param mixed                           $actingAdmin User to save as.
	 * @param IOutput                         $output      Output.
	 *
	 * @return string One of 'fixed', 'skipped' or 'stuck'.
	 */
	private function backfillOne(
		array $lead,
		?string $pipeline,
		array &$clients,
		array $context,
		mixed $actingAdmin,
		IOutput $output,
	): string {
		$uuid = (string)($lead['id'] ?? '');
		if ($uuid === '') {
			return 'skipped';
		}

		$patch = $this->missingFor(lead: $lead, pipeline: $pipeline, clients: $clients);
		if ($patch === null) {
			$output->warning(
				sprintf(
					'BackfillLeadRelations: could not resolve a pipeline or client for lead "%s"',
					(string)($lead['title'] ?? $uuid)
				)
			);

			return 'stuck';
		}

		if ($patch === []) {
			return 'skipped';
		}

		try {
			$this->container->get('OCA\\OpenRegister\\Service\\ObjectService')->saveObject(
				object: array_merge($lead, $patch),
				extend: [],
				register: $context['register'],
				schema: $context['schema'],
				uuid: $uuid,
				_rbac: false,
				_multitenancy: false,
				currentUser: $actingAdmin,
			);

			return 'fixed';
		} catch (Throwable $e) {
			$this->logger->error(
				'BackfillLeadRelations: failed to save lead',
				[
					'uuid' => $uuid,
					'error' => $e->getMessage(),
				]
			);

			return 'stuck';
		}//end try
	}//end backfillOne()

	/**
	 * Report the outcome.
	 *
	 * @param array<string,int> $counts Outcome tallies.
	 * @param IOutput           $output Output.
	 *
	 * @return void
	 */
	private function report(array $counts, IOutput $output): void {
		$output->info(
			sprintf(
				'BackfillLeadRelations: %d lead(s) backfilled, %d already complete, %d still unresolved',
				$counts['fixed'],
				$counts['skipped'],
				$counts['stuck']
			)
		);

		if ($counts['stuck'] > 0) {
			$output->warning(
				sprintf(
					'BackfillLeadRelations: %d lead(s) still lack a pipeline or client and CANNOT BE EDITED until fixed by hand.',
					$counts['stuck']
				)
			);
		}
	}//end report()
}//end class
