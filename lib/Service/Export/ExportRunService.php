<?php

/**
 * Pipelinq ExportRunService.
 *
 * Creates and maintains the immutable per-run audit record (exportRun) and its
 * per-schema structure snapshots (exportSchemaSnapshot): pending creation,
 * status transitions, row/byte/file counts, file manifest, watermark range and
 * error state (ADR-009 audit).
 *
 * @category Service
 * @package  OCA\Pipelinq\Service\Export
 *
 * @author    Conduction <info@conduction.nl>
 * @copyright 2024 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2024 Conduction B.V.
 *
 * @version GIT: <git_id>
 *
 * @link https://github.com/ConductionNL/pipelinq
 *
 * @spec openspec/changes/bi-export-and-data-warehouse-sink/specs.md#REQ-BIE-010
 */

declare(strict_types=1);

namespace OCA\Pipelinq\Service\Export;

use OCP\AppFramework\OCS\OCSNotFoundException;

/**
 * Lifecycle + audit management for export runs and schema snapshots.
 *
 * @spec openspec/changes/bi-export-and-data-warehouse-sink/specs.md#REQ-BIE-010
 */
class ExportRunService extends AbstractExportService {
	/**
	 * The exportRun schema config key.
	 *
	 * @var string
	 */
	private const RUN_SCHEMA_KEY = 'exportRun_schema';

	/**
	 * The exportSchemaSnapshot schema config key.
	 *
	 * @var string
	 */
	private const SNAPSHOT_SCHEMA_KEY = 'exportSchemaSnapshot_schema';

	/**
	 * Create a pending run for a job.
	 *
	 * @param array<string, mixed> $job The export job configuration.
	 * @param string|null $watermarkFrom The previous run's watermarkTo (incremental).
	 *
	 * @return array<string, mixed> The created exportRun.
	 *
	 * @spec openspec/changes/bi-export-and-data-warehouse-sink/specs.md#REQ-BIE-010
	 */
	public function createPendingRun(array $job, ?string $watermarkFrom = null): array {
		$run = [
			'jobId' => (string)($job['id'] ?? $job['uuid'] ?? ''),
			'status' => 'pending',
			'modeUsed' => (string)($job['mode'] ?? 'full'),
			'watermarkFrom' => $watermarkFrom,
			'fileManifestJson' => [],
		];

		return $this->saveObjectData(schemaKey: self::RUN_SCHEMA_KEY, data: $run, id: null);
	}//end createPendingRun()

	/**
	 * Find a run by id (scoped to this app's exportRun schema).
	 *
	 * @param string $runId The run UUID.
	 *
	 * @return array<string, mixed> The run.
	 *
	 * @throws OCSNotFoundException When the run does not exist.
	 */
	public function getRun(string $runId): array {
		$run = $this->findObjectById(schemaKey: self::RUN_SCHEMA_KEY, id: $runId);
		if ($run === null) {
			throw new OCSNotFoundException('Export run not found.');
		}

		return $run;
	}//end getRun()

	/**
	 * List runs with optional filters (job/status/date range), newest first.
	 *
	 * @param array<string, mixed> $filters job_id, status, date_from, date_to.
	 *
	 * @return array<int, array<string, mixed>> The matching runs.
	 *
	 * @spec openspec/changes/bi-export-and-data-warehouse-sink/specs.md#REQ-BIE-011-01
	 */
	public function listRuns(array $filters = []): array {
		$objectFilters = [];
		if (isset($filters['job_id']) === true && (string)$filters['job_id'] !== '') {
			$objectFilters['jobId'] = (string)$filters['job_id'];
		}

		if (isset($filters['status']) === true && (string)$filters['status'] !== '') {
			$objectFilters['status'] = (string)$filters['status'];
		}

		$runs = $this->findAllObjects(schemaKey: self::RUN_SCHEMA_KEY, filters: $objectFilters);

		$dateFrom = (string)($filters['date_from'] ?? '');
		$dateTo = (string)($filters['date_to'] ?? '');
		if ($dateFrom !== '' || $dateTo !== '') {
			$runs = $this->filterByDate(runs: $runs, dateFrom: $dateFrom, dateTo: $dateTo);
		}

		usort(
			$runs,
			static function (array $a, array $b): int {
				return strcmp((string)($b['startedAt'] ?? ''), (string)($a['startedAt'] ?? ''));
			}
		);

		return $runs;
	}//end listRuns()

	/**
	 * Mark a run as running and stamp startedAt.
	 *
	 * @param string $runId The run UUID.
	 *
	 * @return array<string, mixed> The updated run.
	 */
	public function markRunning(string $runId): array {
		$run = $this->getRun(runId: $runId);
		$run['status'] = 'running';
		$run['startedAt'] = $this->now();

		return $this->saveObjectData(schemaKey: self::RUN_SCHEMA_KEY, data: $run, id: $runId);
	}//end markRunning()

	/**
	 * Mark a run as skipped due to an overlapping run for the same job.
	 *
	 * @param string $runId The run UUID.
	 * @param string $reason The skip reason.
	 *
	 * @return array<string, mixed> The updated run.
	 *
	 * @spec openspec/changes/bi-export-and-data-warehouse-sink/specs.md#REQ-BIE-004-03
	 */
	public function markSkippedOverlap(string $runId, string $reason): array {
		$run = $this->getRun(runId: $runId);
		$run['status'] = 'skipped_overlap';
		$run['endedAt'] = $this->now();
		$run['errorMessage'] = $reason;

		return $this->saveObjectData(schemaKey: self::RUN_SCHEMA_KEY, data: $run, id: $runId);
	}//end markSkippedOverlap()

	/**
	 * Complete a run with final outcome, counts, manifest and watermark.
	 *
	 * @param string $runId The run UUID.
	 * @param array<string, mixed> $outcome status, rowCount, byteCount, fileCount,
	 *                                      manifest, watermarkTo, destinationAck, errorMessage.
	 *
	 * @return array<string, mixed> The completed run.
	 *
	 * @spec openspec/changes/bi-export-and-data-warehouse-sink/specs.md#REQ-BIE-010-01
	 */
	public function completeRun(string $runId, array $outcome): array {
		$run = $this->getRun(runId: $runId);

		$run['status'] = (string)($outcome['status'] ?? 'failed');
		$run['endedAt'] = $this->now();
		$run['rowCount'] = (int)($outcome['rowCount'] ?? 0);
		$run['byteCount'] = (int)($outcome['byteCount'] ?? 0);
		$run['fileCount'] = (int)($outcome['fileCount'] ?? 0);
		$run['fileManifestJson'] = ($outcome['manifest'] ?? []);

		if (array_key_exists('watermarkTo', $outcome) === true) {
			$run['watermarkTo'] = $outcome['watermarkTo'];
		}

		if (array_key_exists('destinationAck', $outcome) === true) {
			$run['destinationAck'] = $outcome['destinationAck'];
		}

		if (array_key_exists('errorMessage', $outcome) === true) {
			$run['errorMessage'] = $outcome['errorMessage'];
		}

		return $this->saveObjectData(schemaKey: self::RUN_SCHEMA_KEY, data: $run, id: $runId);
	}//end completeRun()

	/**
	 * Persist a per-schema structure snapshot for a run.
	 *
	 * @param string $runId The run UUID.
	 * @param string $schemaName The pipelinq schema name.
	 * @param array<string, string> $columnDefinitions The column => type map.
	 * @param array<int, string> $comparedToPrevious The detected drift list.
	 *
	 * @return array<string, mixed> The created snapshot.
	 *
	 * @spec openspec/changes/bi-export-and-data-warehouse-sink/specs.md#REQ-BIE-009
	 */
	public function createSnapshot(
		string $runId,
		string $schemaName,
		array $columnDefinitions,
		array $comparedToPrevious = [],
	): array {
		$snapshot = [
			'runId' => $runId,
			'pipelinqSchemaName' => $schemaName,
			'columnDefinitionsJson' => $columnDefinitions,
			'comparedToPrevious' => $comparedToPrevious,
		];

		return $this->saveObjectData(schemaKey: self::SNAPSHOT_SCHEMA_KEY, data: $snapshot, id: null);
	}//end createSnapshot()

	/**
	 * List the schema snapshots for a run.
	 *
	 * @param string $runId The run UUID.
	 *
	 * @return array<int, array<string, mixed>> The snapshots.
	 */
	public function listSnapshots(string $runId): array {
		return $this->findAllObjects(
			schemaKey: self::SNAPSHOT_SCHEMA_KEY,
			filters: ['runId' => $runId]
		);
	}//end listSnapshots()

	/**
	 * The latest snapshot for a schema across this job's prior runs, if any.
	 *
	 * Used by the export worker to compute schema drift relative to the most
	 * recent capture of the same schema.
	 *
	 * @param string $jobId The job UUID.
	 * @param string $schemaName The schema name.
	 *
	 * @return array<string, mixed>|null The previous snapshot, or null.
	 *
	 * @spec openspec/changes/bi-export-and-data-warehouse-sink/specs.md#REQ-BIE-009
	 */
	public function findPreviousSnapshot(string $jobId, string $schemaName): ?array {
		$runs = $this->listRuns(filters: ['job_id' => $jobId]);

		foreach ($runs as $run) {
			$runId = (string)($run['id'] ?? $run['uuid'] ?? '');
			if ($runId === '') {
				continue;
			}

			foreach ($this->listSnapshots(runId: $runId) as $snapshot) {
				if ((string)($snapshot['pipelinqSchemaName'] ?? '') === $schemaName) {
					return $snapshot;
				}
			}
		}

		return null;
	}//end findPreviousSnapshot()

	/**
	 * Most recent succeeded run for a job (for the incremental watermark).
	 *
	 * @param string $jobId The job UUID.
	 *
	 * @return array<string, mixed>|null The last succeeded run, or null.
	 *
	 * @spec openspec/changes/bi-export-and-data-warehouse-sink/specs.md#REQ-BIE-006-01
	 */
	public function lastSucceededRun(string $jobId): ?array {
		$runs = $this->listRuns(filters: ['job_id' => $jobId, 'status' => 'succeeded']);
		if ($runs === []) {
			return null;
		}

		return $runs[0];
	}//end lastSucceededRun()

	/**
	 * Filter runs to a startedAt date range (inclusive bounds, ISO comparison).
	 *
	 * @param array<int, array<string, mixed>> $runs The runs.
	 * @param string $dateFrom The lower bound (ISO, optional).
	 * @param string $dateTo The upper bound (ISO, optional).
	 *
	 * @return array<int, array<string, mixed>> The filtered runs.
	 */
	private function filterByDate(array $runs, string $dateFrom, string $dateTo): array {
		$filtered = [];
		foreach ($runs as $run) {
			$started = (string)($run['startedAt'] ?? '');
			if ($dateFrom !== '' && ($started === '' || strcmp($started, $dateFrom) < 0)) {
				continue;
			}

			if ($dateTo !== '' && ($started === '' || strcmp($started, $dateTo) > 0)) {
				continue;
			}

			$filtered[] = $run;
		}

		return $filtered;
	}//end filterByDate()
}//end class
