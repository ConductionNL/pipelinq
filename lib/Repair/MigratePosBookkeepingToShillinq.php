<?php

/**
 * Pipelinq MigratePosBookkeepingToShillinq.
 *
 * Idempotent, fail-safe repair step that drains the retired POS bookkeeping
 * surface onto the shillinq integration (cross-app contract #3,
 * pipelinq-bookkeeping-to-shillinq). It NEVER deletes data — neither the
 * retired posJournalEntryOutbound records nor the glAccountMapping profiles are
 * removed; they become inert once the delegated path is live.
 *
 * For each in-flight posJournalEntryOutbound (status pending / failed / staged /
 * draft — anything not already posted) it re-raises the journal entry through
 * the ADR-019 integration registry using the record's ORIGINAL
 * SHA256(zReport.uuid + reportDate) idempotency key, so shillinq de-duplicates
 * against any journal the old hard-coded /api/JournalEntry POST already created.
 * For records already posted it projects the shillinq journal id +
 * bookkeepingStatus=raised onto the parent posZReport and leaves the outbound
 * record untouched (read-only).
 *
 * The step is re-runnable: a Z-report whose projection is already `raised` is
 * skipped, and an unreachable shillinq leaves the projection `pending` without
 * failing the upgrade — the periodic PosRetryBackoffJob then drains the rest.
 *
 * @category Repair
 * @package  OCA\Pipelinq\Repair
 *
 * @author    Conduction <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git_id>
 *
 * @link https://github.com/ConductionNL/pipelinq
 *
 * @spec openspec/changes/pipelinq-bookkeeping-to-shillinq/specs/pipelinq-bookkeeping-to-shillinq/spec.md#REQ-PBTS-005
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Pipelinq\Repair;

use OCA\Pipelinq\AppInfo\Application;
use OCA\Pipelinq\Service\PosBookkeepingService;
use OCP\App\IAppManager;
use OCP\IAppConfig;
use OCP\Migration\IOutput;
use OCP\Migration\IRepairStep;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;

/**
 * One-shot, re-runnable repair step that migrates in-flight POS journal entries
 * onto the shillinq registry path and projects posted ones onto the Z-report.
 *
 * @spec openspec/changes/pipelinq-bookkeeping-to-shillinq/specs/pipelinq-bookkeeping-to-shillinq/spec.md#REQ-PBTS-005
 */
class MigratePosBookkeepingToShillinq implements IRepairStep {
	/**
	 * Schema slug of the retired outbound journal-entry record (read-only source).
	 *
	 * @var string
	 */
	private const OUTBOUND_SCHEMA = 'posJournalEntryOutbound';

	/**
	 * Schema slug of the Z-report (projection target).
	 *
	 * @var string
	 */
	private const ZREPORT_SCHEMA = 'posZReport';

	/**
	 * Constructor.
	 *
	 * @param IAppManager $appManager The app manager.
	 * @param IAppConfig $appConfig The app config.
	 * @param ContainerInterface $container The DI container (OR ObjectService).
	 * @param PosBookkeepingService $service The bookkeeping service (registry raise).
	 * @param LoggerInterface $logger The logger.
	 */
	public function __construct(
		private readonly IAppManager $appManager,
		private readonly IAppConfig $appConfig,
		private readonly ContainerInterface $container,
		private readonly PosBookkeepingService $service,
		private readonly LoggerInterface $logger,
	) {
	}//end __construct()

	/**
	 * Get the name of this repair step.
	 *
	 * @return string The step name.
	 */
	public function getName(): string {
		return 'Migrate POS bookkeeping (GL mapping + outbound journal) to the shillinq integration registry';
	}//end getName()

	/**
	 * Run the migration.
	 *
	 * Fail-safe: every failure is caught and reported as a warning; the upgrade
	 * is never failed, even when shillinq is unreachable. Re-runnable: already
	 * raised Z-reports are skipped and pending ones are left for the retry job.
	 *
	 * @param IOutput $output The output interface.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/pipelinq-bookkeeping-to-shillinq/specs/pipelinq-bookkeeping-to-shillinq/spec.md#REQ-PBTS-005
	 */
	public function run(IOutput $output): void {
		if (in_array('openregister', $this->appManager->getInstalledApps(), true) === false) {
			$output->warning('OpenRegister not installed — skipping POS bookkeeping migration.');
			return;
		}

		$register = $this->appConfig->getValueString(Application::APP_ID, 'register', '');
		if ($register === '') {
			$output->warning('Pipelinq register not configured — skipping POS bookkeeping migration.');
			return;
		}

		$objectService = $this->resolveObjectService();
		if ($objectService === null) {
			$output->warning('OpenRegister ObjectService unavailable — skipping POS bookkeeping migration.');
			return;
		}

		$outbounds = $this->readAll(objectService: $objectService, register: $register, schema: self::OUTBOUND_SCHEMA);
		if ($outbounds === []) {
			$output->info('POS bookkeeping migration: no outbound journal records to migrate.');
			return;
		}

		$zReportsById = $this->indexZReports(objectService: $objectService, register: $register);

		$raised = 0;
		$projected = 0;
		$pending = 0;
		foreach ($outbounds as $outbound) {
			$result = $this->migrateOne(
				objectService: $objectService,
				register: $register,
				outbound: $outbound,
				zReportsById: $zReportsById
			);
			if ($result === 'raised') {
				$raised++;
			} elseif ($result === 'projected') {
				$projected++;
			} elseif ($result === 'pending') {
				$pending++;
			}
		}

		$output->info(
			sprintf(
				'POS bookkeeping migration: %d re-raised, %d posted-projected, %d left pending for retry. '
				. 'No glAccountMapping / posJournalEntryOutbound data was deleted. '
				. 'One-off: export the glAccountMapping VAT->GL profiles into shillinq\'s GL configuration manually.',
				$raised,
				$projected,
				$pending
			)
		);
		$this->logger->info(
			'POS bookkeeping migration completed',
			['raised' => $raised, 'projected' => $projected, 'pending' => $pending]
		);
	}//end run()

	/**
	 * Migrate a single outbound record onto the Z-report projection / registry.
	 *
	 * @param object $objectService The OR object service.
	 * @param string $register The register id/slug.
	 * @param array<string, mixed> $outbound The outbound record.
	 * @param array<string, array> $zReportsById The Z-reports indexed by id + slug.
	 *
	 * @return string One of 'raised', 'projected', 'pending', 'skipped'.
	 */
	private function migrateOne(object $objectService, string $register, array $outbound, array $zReportsById): string {
		$zReportRef = (string)($outbound['zReport'] ?? '');
		$zReport = $zReportsById[$zReportRef] ?? null;
		if ($zReport === null) {
			$this->logger->warning(
				'POS bookkeeping migration: parent Z-report not found for outbound',
				['zReportRef' => $zReportRef]
			);
			return 'skipped';
		}

		// Already migrated on a prior run — never re-raise a raised projection.
		if ((string)($zReport['bookkeepingStatus'] ?? '') === 'raised') {
			return 'skipped';
		}

		$status = (string)($outbound['status'] ?? '');
		$idempotencyKey = (string)($outbound['idempotencyKey'] ?? '');

		if ($status === 'posted') {
			// Project the already-posted outcome onto the Z-report; leave the
			// outbound record read-only (never dropped).
			$journalId = (string)($outbound['shillinqJournalEntryId'] ?? $idempotencyKey);
			$this->projectPosted(
				objectService: $objectService,
				register: $register,
				zReport: $zReport,
				journalEntryId: $journalId
			);
			return 'projected';
		}

		// In-flight (pending / failed / staged / draft): re-raise through the
		// registry with the ORIGINAL idempotency key so shillinq de-duplicates.
		$result = $this->service->dispatchRaise(zReport: $zReport, idempotencyKey: $idempotencyKey);
		if ((string)($result['bookkeepingStatus'] ?? '') === 'raised') {
			return 'raised';
		}

		return 'pending';
	}//end migrateOne()

	/**
	 * Project an already-posted outcome onto the parent Z-report.
	 *
	 * @param object $objectService The OR object service.
	 * @param string $register The register id/slug.
	 * @param array<string, mixed> $zReport The Z-report.
	 * @param string $journalEntryId The shillinq journal id.
	 *
	 * @return void
	 */
	private function projectPosted(object $objectService, string $register, array $zReport, string $journalEntryId): void {
		$zReport['bookkeepingStatus'] = 'raised';
		$zReport['shillinqJournalEntryId'] = $journalEntryId;
		unset($zReport['@self']);

		try {
			$objectService
				->setRegister($register)
				->setSchema(self::ZREPORT_SCHEMA)
				->saveObject(
					object: $zReport,
					extend: [],
					register: $register,
					schema: self::ZREPORT_SCHEMA,
					uuid: (string)($zReport['id'] ?? $zReport['uuid'] ?? '')
				);
		} catch (\Throwable $e) {
			$this->logger->warning(
				'POS bookkeeping migration: failed to project posted outcome onto Z-report',
				['exception' => $e->getMessage()]
			);
		}
	}//end projectPosted()

	/**
	 * Index every posZReport by both its id and its slug for FK resolution.
	 *
	 * @param object $objectService The OR object service.
	 * @param string $register The register id/slug.
	 *
	 * @return array<string, array<string, mixed>> Z-reports keyed by id and slug.
	 */
	private function indexZReports(object $objectService, string $register): array {
		$rows = $this->readAll(objectService: $objectService, register: $register, schema: self::ZREPORT_SCHEMA);
		$index = [];
		foreach ($rows as $row) {
			$id = (string)($row['id'] ?? $row['uuid'] ?? '');
			$slug = (string)($row['@self']['slug'] ?? '');

			if ($id !== '') {
				$index[$id] = $row;
			}

			if ($slug !== '') {
				$index[$slug] = $row;
			}
		}

		return $index;
	}//end indexZReports()

	/**
	 * Read every object of a schema in the register (setRegister->setSchema->findAll).
	 *
	 * @param object $objectService The OR object service.
	 * @param string $register The register id/slug.
	 * @param string $schema The schema slug.
	 *
	 * @return array<int, array<string, mixed>> The objects as plain arrays.
	 */
	private function readAll(object $objectService, string $register, string $schema): array {
		try {
			$rows = $objectService
				->setRegister($register)
				->setSchema($schema)
				->findAll([]);
		} catch (\Throwable $e) {
			$this->logger->warning(
				'POS bookkeeping migration: failed to read objects',
				['schema' => $schema, 'exception' => $e->getMessage()]
			);
			return [];
		}

		$out = [];
		foreach (($rows ?? []) as $row) {
			$out[] = $this->toArray(object: $row);
		}

		return $out;
	}//end readAll()

	/**
	 * Resolve the OpenRegister ObjectService, or null when unavailable.
	 *
	 * @return object|null The object service, or null.
	 */
	private function resolveObjectService(): ?object {
		try {
			return $this->container->get('OCA\OpenRegister\Service\ObjectService');
		} catch (\Throwable $e) {
			$this->logger->warning(
				'POS bookkeeping migration: OpenRegister ObjectService not resolvable',
				['exception' => $e->getMessage()]
			);
			return null;
		}
	}//end resolveObjectService()

	/**
	 * Normalise an OR object (entity or array) into a plain array.
	 *
	 * @param mixed $object The OR object.
	 *
	 * @return array<string, mixed> The object as an array.
	 */
	private function toArray(mixed $object): array {
		if (is_array($object) === true) {
			return $object;
		}

		if (is_object($object) === true && method_exists($object, 'jsonSerialize') === true) {
			$serialized = $object->jsonSerialize();
			if (is_array($serialized) === true) {
				return $serialized;
			}
		}

		if (is_object($object) === true && method_exists($object, 'getObject') === true) {
			$data = $object->getObject();
			if (is_array($data) === true) {
				return $data;
			}
		}

		return (array)$object;
	}//end toArray()
}//end class
