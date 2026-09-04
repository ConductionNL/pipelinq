<?php

/**
 * Pipelinq BackfillContactChannelArrays.
 *
 * Repair step that seeds the new typed `emails[]`/`phones[]` arrays on every
 * stored `client` and `contact` from the legacy single `email`/`phone`
 * fields, so an existing record shows a channel immediately instead of an
 * empty list the first time someone opens its detail page after the
 * contact-channel-details upgrade.
 *
 * Idempotent and non-destructive (ADR-069): a record whose `emails`/`phones`
 * array is already non-empty is left untouched — this step only ever ADDS
 * the single legacy value as entry 0 (`kind: "work"`, `primary: true`,
 * `verified: false`) when the array is missing or empty. No existing field
 * is deleted or overwritten. Safe to re-run: a second pass finds the arrays
 * already populated and skips every record.
 *
 * @category Repair
 * @package  OCA\Pipelinq\Repair
 *
 * @author    Conduction <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://pipelinq.nl
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @spec openspec/changes/contact-channel-details/specs/contacts-sync/spec.md#requirement-existing-records-backfill-channel-arrays-on-upgrade
 */

declare(strict_types=1);

namespace OCA\Pipelinq\Repair;

use OCA\Pipelinq\AppInfo\Application;
use OCP\IAppConfig;
use OCP\IGroupManager;
use OCP\IUser;
use OCP\Migration\IOutput;
use OCP\Migration\IRepairStep;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Repair step: backfill client/contact emails[]/phones[] from the legacy
 * scalar email/phone fields.
 *
 * @spec openspec/changes/contact-channel-details/specs/contacts-sync/spec.md#requirement-existing-records-backfill-channel-arrays-on-upgrade
 */
class BackfillContactChannelArrays implements IRepairStep {
	/**
	 * Upper bound on rows fetched per schema.
	 *
	 * @var int
	 */
	private const BATCH_LIMIT = 10000;

	/**
	 * The two schemas this step backfills.
	 *
	 * @var string[]
	 */
	private const OBJECT_TYPES = ['client', 'contact'];

	/**
	 * Constructor.
	 *
	 * @param IAppConfig $appConfig App config, source of the register and schema ids.
	 * @param ContainerInterface $container Container, used to reach OpenRegister's ObjectService.
	 * @param IGroupManager $groupManager Group manager, used to resolve an acting admin.
	 * @param LoggerInterface $logger PSR logger.
	 */
	public function __construct(
		private readonly IAppConfig $appConfig,
		private readonly ContainerInterface $container,
		private readonly IGroupManager $groupManager,
		private readonly LoggerInterface $logger,
	) {
	}//end __construct()

	/**
	 * Get the repair step name.
	 *
	 * @return string Name.
	 *
	 * @spec exclude repair-step display name accessor, no behavioural spec surface.
	 */
	public function getName(): string {
		return 'Backfill client/contact emails[] and phones[] from the legacy email/phone fields (idempotent, non-destructive)';
	}//end getName()

	/**
	 * Run the repair for both `client` and `contact`.
	 *
	 * @param IOutput $output Output.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/contact-channel-details/specs/contacts-sync/spec.md#requirement-existing-records-backfill-channel-arrays-on-upgrade
	 */
	public function run(IOutput $output): void {
		$register = $this->appConfig->getValueString(Application::APP_ID, 'register', '');
		if ($register === '') {
			$output->info('BackfillContactChannelArrays: pipelinq register not configured, skipping');
			return;
		}

		try {
			$objectService = $this->container->get('OCA\OpenRegister\Service\ObjectService');
		} catch (Throwable $e) {
			$output->warning('BackfillContactChannelArrays: OpenRegister ObjectService unavailable, skipping (' . $e->getMessage() . ')');
			return;
		}

		$actingAdmin = $this->actingAdmin();

		$totals = ['fixed' => 0, 'skipped' => 0, 'stuck' => 0];
		foreach (self::OBJECT_TYPES as $objectType) {
			$schema = $this->appConfig->getValueString(Application::APP_ID, "{$objectType}_schema", '');
			if ($schema === '') {
				continue;
			}

			$rows = $this->readAll(objectService: $objectService, register: $register, schema: $schema);
			foreach ($rows as $row) {
				$outcome = $this->backfillOne(
					objectService: $objectService,
					register: $register,
					schema: $schema,
					row: $row,
					actingAdmin: $actingAdmin
				);
				$totals[$outcome]++;
			}
		}

		$output->info(
			sprintf(
				'BackfillContactChannelArrays: %d record(s) backfilled, %d already had channel data, %d failed to save',
				$totals['fixed'],
				$totals['skipped'],
				$totals['stuck']
			)
		);
	}//end run()

	/**
	 * Compute and persist the patch for one client/contact row.
	 *
	 * @param object $objectService OpenRegister ObjectService.
	 * @param string $register Register id.
	 * @param string $schema Schema id.
	 * @param array<string,mixed> $row The stored row.
	 * @param IUser|null $actingAdmin User to save as (repair steps have no session).
	 *
	 * @return string One of 'fixed', 'skipped' or 'stuck'.
	 */
	private function backfillOne(object $objectService, string $register, string $schema, array $row, ?IUser $actingAdmin): string {
		$uuid = (string)($row['id'] ?? '');
		if ($uuid === '') {
			return 'skipped';
		}

		$patch = [];

		$existingEmails = $row['emails'] ?? [];
		if (is_array($existingEmails) === false || $existingEmails === []) {
			$email = trim((string)($row['email'] ?? ''));
			if ($email !== '') {
				$patch['emails'] = [
					['kind' => 'work', 'value' => $email, 'primary' => true, 'verified' => false],
				];
			}
		}

		$existingPhones = $row['phones'] ?? [];
		if (is_array($existingPhones) === false || $existingPhones === []) {
			$phone = trim((string)($row['phone'] ?? ''));
			if ($phone !== '') {
				$patch['phones'] = [
					['kind' => 'work', 'value' => $phone, 'primary' => true, 'verified' => false],
				];
			}
		}

		if ($patch === []) {
			return 'skipped';
		}

		try {
			$objectService->saveObject(
				object: array_merge($row, $patch),
				extend: [],
				register: $register,
				schema: $schema,
				uuid: $uuid,
				_rbac: false,
				_multitenancy: false,
				currentUser: $actingAdmin,
			);

			return 'fixed';
		} catch (Throwable $e) {
			$this->logger->warning(
				'BackfillContactChannelArrays: failed to save record',
				['uuid' => $uuid, 'schema' => $schema, 'error' => $e->getMessage()]
			);

			return 'stuck';
		}//end try
	}//end backfillOne()

	/**
	 * Resolve an admin to act as while saving.
	 *
	 * A repair step has no session, so OpenRegister's folder ACL check has no
	 * acting user and denies the write for any object that owns a file folder.
	 *
	 * @return IUser|null The first admin, or null when none exists.
	 */
	private function actingAdmin(): ?IUser {
		$admins = ($this->groupManager->get('admin')?->getUsers() ?? []);

		return (array_values($admins)[0] ?? null);
	}//end actingAdmin()

	/**
	 * Read every row of one schema.
	 *
	 * RBAC is off for the same reason the writes turn it off: on the CLI
	 * there is no session, so an RBAC-filtered read returns only what
	 * 'Anonymous' may see and the backfill would quietly cover a fraction
	 * of the rows.
	 *
	 * @param object $objectService OpenRegister ObjectService.
	 * @param string $register Register id.
	 * @param string $schema Schema id.
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
				'BackfillContactChannelArrays: findAll failed',
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
}//end class
