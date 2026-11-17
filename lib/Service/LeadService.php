<?php

/**
 * Lead Service.
 *
 * Resolution point + read/write facade for the `lead` schema's MCP-facing
 * operations: creating a lead and computing the per-stage pipeline forecast.
 * Both public entry points are annotated `#[McpTool]` (OpenRegister ADR-063
 * chain 3/3, PR #363) so OpenRegister's AttributeToolScanner can discover
 * them once Pipelinq declares this class scannable via
 * `OCA\Pipelinq\Mcp\PipelinqScannableServices`.
 *
 * Migrated out of `OCA\Pipelinq\Mcp\PipelinqToolProvider` (deleted) by
 * `plq-mcp-provider-surgery` — the hand-written CRUD reads (`listLeads`,
 * `searchLeads`, `getLead`) are superseded by OpenRegister's schema-derived
 * `pipelinq.lead.{search,get}` tools and are NOT ported here; only the two
 * curated, non-CRUD tools survive.
 *
 * Auth design (OWASP A01:2021 / ADR-005), unchanged from the hand-written
 * provider:
 * - Argument validation runs first (cheap before expensive).
 * - The write in createLead() goes through OpenRegister's ObjectService with
 *   RBAC left at its default (enabled); a permission denial is surfaced as a
 *   `forbidden` error envelope, never swallowed into a false success.
 *
 * @category Service
 * @package  OCA\Pipelinq\Service
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @version GIT: <git-id>
 *
 * @link https://conduction.nl
 *
 * @spec openspec/specs/crm-mcp-tool-surface/spec.md
 */

declare(strict_types=1);

namespace OCA\Pipelinq\Service;

use OCA\OpenRegister\Kernel\Contracts\Value\ObjectFindOptions;
use OCA\OpenRegister\Kernel\Contracts\Value\ObjectFindTarget;
use OCA\OpenRegister\Kernel\Contracts\Value\ObjectSaveOptions;
use OCA\OpenRegister\Mcp\Attribute\McpTool;
use OCA\Pipelinq\Kernel\AppIdentity;
use OCA\Pipelinq\Mcp\McpAnswer;
use OCA\Pipelinq\Mcp\McpRecordUpdate;
use OCA\Pipelinq\Mcp\McpTransition;
use DateTimeImmutable;
use OCP\IAppConfig;
use Psr\Container\ContainerInterface;
use RuntimeException;
use Throwable;

/**
 * LeadService
 *
 * @spec openspec/specs/crm-mcp-tool-surface/spec.md
 *   (Requirement: MCP provider exposes RBAC-guarded CRM write tools)
 * @spec openspec/specs/crm-mcp-tool-surface/spec.md
 *   (Requirement: MCP provider exposes a CRM read tool surface)
 *
 * @SuppressWarnings(PHPMD.CouplingBetweenObjects) The curated lead tools read, save and transition
 *   through OpenRegister's value objects; each is one MCP tool the scanner reflects on this class.
 */
class LeadService {

	/**
	 * The OpenRegister ObjectService class name (resolved lazily via the container).
	 *
	 * @var string
	 */
	private const OR_OBJECT_SERVICE = 'OCA\\OpenRegister\\Service\\ObjectService';

	/**
	 * Fetch window for the pipeline-forecast aggregation — mirrors
	 * PipelinqToolProvider::AGGREGATION_FETCH_CAP prior to migration.
	 *
	 * @var int
	 */
	private const AGGREGATION_FETCH_CAP = 500;

	/**
	 * Constructor.
	 *
	 * @param ContainerInterface $container The DI container (for OR ObjectService).
	 * @param IAppConfig $appConfig The app config (lead schema resolution).
	 * @param McpAnswer $mcp Shapes what the MCP tools on this service answer.
	 * @param McpTransition|null $transitions Runs the lead's declared lifecycle transitions;
	 *                                        null answers `not_configured` from winLead/loseLead.
	 */
	public function __construct(
		private readonly ContainerInterface $container,
		private readonly IAppConfig $appConfig,
		private readonly McpAnswer $mcp,
		private readonly ?McpTransition $transitions = null,
	) {
	}//end __construct()

	// The attribute sits ABOVE the docblock, which is the other way round
	// from everywhere else in this app. A multi-line PHP attribute breaks the
	// line-walk gate-16 uses to find a method's docblock, so with the usual
	// order the @spec tag below is invisible to it and the method reads as
	// unlinked. The tag is real either way; this makes it checkable.
	#[McpTool(
		name: 'createLead',
		subject: 'lead',
		action: 'create',
		description: 'Create a new sales lead. Only "title" is required; client, value, source and assignee are optional.',
		readOnlyHint: false,
		destructiveHint: false,
		idempotentHint: false,
		scope: 'create',
		reach: 'instance'
	)]
	/**
	 * Create a new sales lead. Only "title" is required; client, value,
	 * source and assignee are optional.
	 *
	 * Validates the required `title`, then writes through
	 * ObjectService->saveObject on the configured lead schema (RBAC `create`
	 * enforced). The saved lead is returned unmodified — the server-computed
	 * `qualificationScore` and the declarative `winProbability` calculation
	 * (x-openregister-calculations) flow through as materialised by
	 * OpenRegister; this method does not alias or recompute either
	 * (pipelinq #381).
	 *
	 * @param string $title A short description of the opportunity.
	 * @param string|null $client Optional client UUID this lead is linked to.
	 * @param float|null $value Optional estimated deal value.
	 * @param string|null $source Optional origin of the lead (e.g. website, referral, cold-call).
	 * @param string|null $assignee Optional Nextcloud user id of the lead owner.
	 *
	 * @return array<string, mixed>
	 *
	 * @spec openspec/specs/crm-mcp-tool-surface/spec.md#requirement-mcp-provider-exposes-rbac-guarded-crm-write-tools
	 */
	public function createLead(
		string $title,
		?string $client = null,
		?float $value = null,
		?string $source = null,
		?string $assignee = null,
	): array {
		$title = trim($title);
		$config = $this->leadContextFor(title: $title);
		if (isset($config['error']) === true) {
			return $config;
		}

		$optional = ['client' => $client, 'value' => $value, 'source' => $source, 'assignee' => $assignee];

		return $this->saveLead(payload: ['title' => $title] + $this->presentFields(fields: $optional), config: $config);
	}//end createLead()

	/**
	 * The lead context for a trimmed title: an invalid_arguments error when the title is empty.
	 *
	 * @param string $title The trimmed title.
	 *
	 * @return array<string, mixed> The register and schema, or an MCP error envelope.
	 *
	 * @spec openspec/specs/crm-mcp-tool-surface/spec.md#requirement-mcp-provider-exposes-rbac-guarded-crm-write-tools
	 */
	private function leadContextFor(string $title): array {
		if ($title === '') {
			return $this->mcp->error(code: 'invalid_arguments', message: 'Required argument title is missing.');
		}

		return $this->resolveLeadContext();
	}//end leadContextFor()

	/**
	 * The fields that carry a value: neither null nor an empty string.
	 *
	 * @param array<string, string|float|null> $fields The optional lead fields.
	 *
	 * @return array<string, string|float>
	 *
	 * @spec openspec/specs/crm-mcp-tool-surface/spec.md#requirement-mcp-provider-exposes-rbac-guarded-crm-write-tools
	 */
	private function presentFields(array $fields): array {
		$present = [];
		foreach ($fields as $key => $field) {
			if ($field !== null && $field !== '') {
				$present[$key] = $field;
			}
		}

		return $present;
	}//end presentFields()

	/**
	 * Save the lead and wrap the result.
	 *
	 * @param array<string, mixed> $payload The lead payload.
	 * @param array<string, mixed> $config The register and schema.
	 *
	 * @return array<string, mixed> The created lead, or an MCP error envelope.
	 *
	 * @spec openspec/specs/crm-mcp-tool-surface/spec.md#requirement-mcp-provider-exposes-rbac-guarded-crm-write-tools
	 */
	private function saveLead(array $payload, array $config): array {
		try {
			$objectService = $this->getObjectService();

			$saved = $objectService->saveObject(
				object: $payload,
				options: new ObjectSaveOptions(target: new ObjectFindTarget(register: $config['register'], schema: $config['schema']))
			);
		} catch (\Exception $e) {
			return $this->mcp->fromException(operation: 'create lead', exception: $e);
		}//end try

		return ['lead' => $this->mcp->toArray(item: $saved)];
	}//end saveLead()

	// The attribute sits ABOVE the docblock, which is the other way round
	// from everywhere else in this app; see createLead().
	#[McpTool(
		name: 'updateLead',
		subject: 'lead',
		action: 'update',
		description: 'Change fields of an existing lead by id: title, client, value, source, assignee, stage, '
			. 'expectedCloseDate, priority or notes. Moving the lead to another stage records when it '
			. 'entered that stage. The status cannot be set here: close a lead with pipelinq_winLead or '
			. 'pipelinq_loseLead.',
		readOnlyHint: false,
		destructiveHint: false,
		idempotentHint: true,
		scope: 'update',
		reach: 'instance'
	)]
	/**
	 * Change fields of an existing lead.
	 *
	 * Validates first (an id, at least one field, no `status`), then reads the
	 * lead through ObjectService (RBAC `read`, a missing lead is `not_found`),
	 * then saves the merged lead through ObjectService->saveObject (RBAC
	 * `update`). A stage that differs from the stored one stamps
	 * `stageEnteredAt` with the write time, so the declarative `daysInStage`
	 * stays truthful; an update that leaves the stage alone leaves the stamp
	 * alone.
	 *
	 * @param string      $id                The lead uuid.
	 * @param string|null $title             New title.
	 * @param string|null $client            New client uuid.
	 * @param float|null  $value             New estimated deal value.
	 * @param string|null $source            New origin of the lead.
	 * @param string|null $assignee          New Nextcloud user id of the lead owner.
	 * @param string|null $stage             New pipeline stage.
	 * @param string|null $expectedCloseDate New expected close date (YYYY-MM-DD).
	 * @param string|null $priority          New priority.
	 * @param string|null $notes             New notes.
	 * @param string|null $status            Refused: lifecycle state moves through winLead/loseLead.
	 *
	 * @return array<string, mixed> `['lead' => ...]`, or an MCP error envelope.
	 *
	 * @SuppressWarnings(PHPMD.ExcessiveParameterList) An MCP tool's arguments are its parameters.
	 *
	 * @spec openspec/changes/mcp-full-action-coverage/specs/crm-mcp-tool-surface/spec.md#requirement-mcp-provider-exposes-rbac-guarded-crm-write-tools
	 */
	public function updateLead(
		string $id,
		?string $title = null,
		?string $client = null,
		?float $value = null,
		?string $source = null,
		?string $assignee = null,
		?string $stage = null,
		?string $expectedCloseDate = null,
		?string $priority = null,
		?string $notes = null,
		?string $status = null,
	): array {
		$id = trim($id);
		$changes = $this->records()->filled(
			fields: [
				'title' => trim((string)$title),
				'client' => $client,
				'value' => $value,
				'source' => $source,
				'assignee' => $assignee,
				'stage' => $stage,
				'expectedCloseDate' => $expectedCloseDate,
				'priority' => $priority,
				'notes' => $notes,
			]
		);

		$refusal = $this->records()->refusal(
			id: $id,
			changes: $changes,
			status: $status,
			statusRefusal: 'The status of a lead cannot be set by updateLead: use winLead or loseLead.'
		);
		if ($refusal !== null) {
			return $refusal;
		}

		$config = $this->resolveLeadContext();
		if (isset($config['error']) === true) {
			return $config;
		}

		return $this->applyLeadUpdate(id: $id, changes: $changes, config: $config);
	}//end updateLead()

	/**
	 * Read the lead, merge the changes, stamp a stage change and save.
	 *
	 * @param string               $id      The lead uuid.
	 * @param array<string, mixed> $changes The fields to change.
	 * @param array<string, mixed> $config  The register and schema.
	 *
	 * @return array<string, mixed> `['lead' => ...]`, or an MCP error envelope.
	 *
	 * @spec openspec/changes/mcp-full-action-coverage/specs/crm-mcp-tool-surface/spec.md#requirement-mcp-provider-exposes-rbac-guarded-crm-write-tools
	 */
	private function applyLeadUpdate(string $id, array $changes, array $config): array {
		$objectService = $this->getObjectService();
		$lead = $this->records()->stored(
			find: static fn (): mixed => $objectService->find(
				id: $id,
				options: new ObjectFindOptions(register: $config['register'], schema: $config['schema'])
			),
			subject: 'lead'
		);
		if (isset($lead['error']) === true) {
			return $lead;
		}

		if (isset($changes['stage']) === true && (string)($lead['stage'] ?? '') !== (string)$changes['stage']) {
			$changes['stageEnteredAt'] = (new DateTimeImmutable())->format(DATE_ATOM);
		}

		$saved = $this->records()->saved(
			save: static fn (): mixed => $objectService->saveObject(
				object: array_merge($lead, $changes),
				options: new ObjectSaveOptions(
					target: new ObjectFindTarget(register: $config['register'], schema: $config['schema']),
					uuid: $id
				)
			),
			operation: 'update lead'
		);
		if (isset($saved['error']) === true) {
			return $saved;
		}

		return ['lead' => $saved];
	}//end applyLeadUpdate()

	/**
	 * The steps the update tool shares with the other curated update tools.
	 *
	 * @return McpRecordUpdate
	 */
	private function records(): McpRecordUpdate {
		return new McpRecordUpdate(mcp: $this->mcp);
	}//end records()

	// The attribute sits ABOVE the docblock, which is the other way round
	// from everywhere else in this app; see createLead().
	#[McpTool(
		name: 'winLead',
		subject: 'lead',
		action: 'win',
		description: 'Close an open lead as won, by id. Allowed for the lead\'s assignee or a member of the '
			. 'sales group. A lead that is already won or lost answers conflict.',
		readOnlyHint: false,
		destructiveHint: true,
		idempotentHint: true,
		scope: 'update',
		reach: 'instance'
	)]
	/**
	 * Close an open lead as won, through the lead's declared `win` transition.
	 *
	 * @param string $id The lead uuid.
	 *
	 * @return array<string, mixed> `['lead' => ...]`, or an MCP error envelope.
	 *
	 * @spec openspec/changes/mcp-full-action-coverage/specs/crm-mcp-tool-surface/spec.md#requirement-lifecycle-transitions-are-curated-tools-one-per-action
	 */
	public function winLead(string $id): array {
		return $this->transition(id: $id, action: 'win');
	}//end winLead()

	// The attribute sits ABOVE the docblock, which is the other way round
	// from everywhere else in this app; see createLead().
	#[McpTool(
		name: 'loseLead',
		subject: 'lead',
		action: 'lose',
		description: 'Close an open lead as lost, by id. Allowed for the lead\'s assignee or a member of the '
			. 'sales group. A lead that is already won or lost answers conflict.',
		readOnlyHint: false,
		destructiveHint: true,
		idempotentHint: true,
		scope: 'update',
		reach: 'instance'
	)]
	/**
	 * Close an open lead as lost, through the lead's declared `lose` transition.
	 *
	 * @param string $id The lead uuid.
	 *
	 * @return array<string, mixed> `['lead' => ...]`, or an MCP error envelope.
	 *
	 * @spec openspec/changes/mcp-full-action-coverage/specs/crm-mcp-tool-surface/spec.md#requirement-lifecycle-transitions-are-curated-tools-one-per-action
	 */
	public function loseLead(string $id): array {
		return $this->transition(id: $id, action: 'lose');
	}//end loseLead()

	/**
	 * Run one of the lead's declared transitions.
	 *
	 * @param string $id     The lead uuid.
	 * @param string $action The transition.
	 *
	 * @return array<string, mixed> `['lead' => ...]`, or an MCP error envelope.
	 *
	 * @spec openspec/changes/mcp-full-action-coverage/specs/crm-mcp-tool-surface/spec.md#requirement-lifecycle-transitions-are-curated-tools-one-per-action
	 */
	private function transition(string $id, string $action): array {
		if ($this->transitions === null) {
			return $this->mcp->error(code: 'not_configured', message: 'Lead transitions are not available on this instance.');
		}

		return $this->transitions->run(objectId: $id, action: $action, subject: 'lead');
	}//end transition()

	// The attribute sits ABOVE the docblock, which is the other way round
	// from everywhere else in this app. A multi-line PHP attribute breaks the
	// line-walk gate-16 uses to find a method's docblock, so with the usual
	// order the @spec tag below is invisible to it and the method reads as
	// unlinked. The tag is real either way; this makes it checkable.
	#[McpTool(
		name: 'pipelineForecast',
		// `forecast`, not `get`: it derives a projection across the whole
		// pipeline rather than returning one stored record, so it exposes
		// aggregate commercial data a single-record read does not.
		subject: 'pipeline',
		action: 'forecast',
		description: 'Per-stage totals over open leads: lead count, summed value, weighted value, plus a grand total.',
		readOnlyHint: true,
		destructiveHint: false,
		idempotentHint: true,
		scope: 'read',
		reach: 'user'
	)]
	/**
	 * Per-stage totals over open leads: lead count, summed value, weighted
	 * value, plus a grand total.
	 *
	 * Reads RBAC-visible open leads, buckets by pipeline stage, and sums
	 * `value` and the already-materialised `weightedValue` calculation
	 * (never recomputed). Rows are ordered by the lowest `stageOrder` seen
	 * in each bucket (ADR-031 exception (2): a per-request, caller-shaped
	 * aggregation, not a stored x-openregister-aggregations value).
	 *
	 * @return array<string, mixed>
	 *
	 * @spec openspec/specs/crm-mcp-tool-surface/spec.md#requirement-mcp-provider-exposes-a-crm-read-tool-surface
	 */
	public function pipelineForecast(): array {
		$config = $this->resolveLeadContext();
		if (isset($config['error']) === true) {
			return $config;
		}

		try {
			$objectService = $this->getObjectService();

			$rawLeads = $objectService->findAll(
				[
					'filters' => [
						'register' => $config['register'],
						'schema' => $config['schema'],
						'status' => 'open',
					],
					'limit' => self::AGGREGATION_FETCH_CAP,
				]
			);
		} catch (\Exception $e) {
			return $this->mcp->fromException(operation: 'compute pipeline forecast', exception: $e);
		}//end try

		return $this->buildForecastFromLeads(rawLeads: $rawLeads);
	}//end pipelineForecast()

	// =========================================================================
	// Private helpers
	// =========================================================================

	/**
	 * Reduce a set of raw open-lead rows into per-stage forecast rows plus a
	 * grand total.
	 *
	 * @param iterable<mixed> $rawLeads The raw open-lead rows.
	 *
	 * @return array<string, mixed>
	 */
	private function buildForecastFromLeads(iterable $rawLeads): array {
		$stages = [];
		foreach ($rawLeads as $raw) {
			$lead = $this->mcp->toArray(item: $raw);
			$stage = (string)($lead['stage'] ?? 'unspecified');
			$order = (int)($lead['stageOrder'] ?? PHP_INT_MAX);

			if (isset($stages[$stage]) === false) {
				$stages[$stage] = [
					'stage' => $stage,
					'order' => $order,
					'leadCount' => 0,
					'value' => 0.0,
					'weightedValue' => 0.0,
				];
			}

			$stages[$stage]['leadCount']++;
			$stages[$stage]['value'] += (float)($lead['value'] ?? 0);
			$stages[$stage]['weightedValue'] += (float)($lead['weightedValue'] ?? 0);
			$stages[$stage]['order'] = min($stages[$stage]['order'], $order);
		}//end foreach

		usort(
			$stages,
			static function (array $left, array $right): int {
				return $left['order'] <=> $right['order'];
			}
		);

		$rows = [];
		$totalCount = 0;
		$totalValue = 0.0;
		$totalWeight = 0.0;
		foreach ($stages as $row) {
			unset($row['order']);
			$rows[] = $row;
			$totalCount += $row['leadCount'];
			$totalValue += $row['value'];
			$totalWeight += $row['weightedValue'];
		}

		return [
			'stages' => $rows,
			'total' => [
				'leadCount' => $totalCount,
				'value' => $totalValue,
				'weightedValue' => $totalWeight,
			],
		];

	}//end buildForecastFromLeads()

	/**
	 * Resolve the configured OpenRegister register + lead schema.
	 *
	 * @return array<string, mixed> Either ['register' => ..., 'schema' => ...] or an error envelope.
	 */
	private function resolveLeadContext(): array {
		$register = $this->appConfig->getValueString(AppIdentity::APP_ID, 'register', '');
		$schema = $this->appConfig->getValueString(AppIdentity::APP_ID, 'lead_schema', '');

		if ($register === '' || $schema === '') {
			return $this->mcp->error(
				code: 'not_configured',
				message: 'Pipelinq is not fully configured: the OpenRegister register or lead schema is missing.'
			);
		}

		return ['register' => $register, 'schema' => $schema];
	}//end resolveLeadContext()

	/**
	 * Resolve the OpenRegister ObjectService via the DI container.
	 *
	 * @return object The OpenRegister ObjectService instance.
	 *
	 * @throws RuntimeException If OpenRegister is not available.
	 */
	private function getObjectService(): object {
		try {
			return $this->container->get(self::OR_OBJECT_SERVICE);
		} catch (Throwable $e) {
			throw new RuntimeException('OpenRegister service is not available.');
		}

	}//end getObjectService()

}//end class
