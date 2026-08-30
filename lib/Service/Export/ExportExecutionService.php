<?php

/**
 * Pipelinq ExportExecutionService.
 *
 * Orchestrates one export run end-to-end: acquires a per-job distributed lock
 * (skipping overlapping runs), extracts + formats each source schema, captures
 * schema-drift snapshots, uploads to the destination with retries, and writes
 * the immutable run audit record with counts, manifest, watermark and error
 * state. Pulled out of the background job so it is unit-testable without the
 * job scheduler.
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
 * @spec openspec/changes/bi-export-and-data-warehouse-sink/specs.md#REQ-BIE-004
 */

declare(strict_types=1);

namespace OCA\Pipelinq\Service\Export;

use OCA\Pipelinq\AppInfo\Application;
use OCP\ICacheFactory;
use Psr\Log\LoggerInterface;

/**
 * End-to-end orchestration of a single export run.
 *
 * @SuppressWarnings(PHPMD.CouplingBetweenObjects) Coordinates the job / data /
 *  upload / run / evolution collaborators a run executor legitimately needs.
 *
 * @spec openspec/changes/bi-export-and-data-warehouse-sink/specs.md#REQ-BIE-004
 */
class ExportExecutionService {
	/**
	 * Distributed lock TTL (seconds) — a stuck run releases after this.
	 *
	 * @var int
	 */
	private const LOCK_TTL = 3600;

	/**
	 * Constructor.
	 *
	 * @param ExportJobService $jobs The job service.
	 * @param ExportRunService $runs The run service.
	 * @param ExportDataService $data The data extraction service.
	 * @param ExportUploadService $uploads The upload service.
	 * @param ExportDestinationService $destinations The destination service.
	 * @param SchemaEvolutionService $evolution The schema-evolution service.
	 * @param ICacheFactory $cacheFactory The distributed cache factory (lock).
	 * @param LoggerInterface $logger The logger.
	 */
	public function __construct(
		private ExportJobService $jobs,
		private ExportRunService $runs,
		private ExportDataService $data,
		private ExportUploadService $uploads,
		private ExportDestinationService $destinations,
		private SchemaEvolutionService $evolution,
		private ICacheFactory $cacheFactory,
		private LoggerInterface $logger,
	) {
	}//end __construct()

	/**
	 * Execute a single pending run.
	 *
	 * Acquires the per-job lock; if already held, marks the run
	 * skipped_overlap. Otherwise extracts every source schema, snapshots its
	 * structure (with drift vs. the previous snapshot), uploads, and completes
	 * the run with the aggregate outcome.
	 *
	 * @param array<string, mixed> $run The pending exportRun.
	 *
	 * @return array<string, mixed> The completed (or skipped) run.
	 *
	 * @spec openspec/changes/bi-export-and-data-warehouse-sink/specs.md#REQ-BIE-004-02
	 * @spec openspec/changes/bi-export-and-data-warehouse-sink/specs.md#REQ-BIE-004-03
	 */
	public function executeRun(array $run): array {
		$runId = (string)($run['id'] ?? $run['uuid'] ?? '');
		$jobId = (string)($run['jobId'] ?? '');

		if ($this->acquireLock(jobId: $jobId) === false) {
			$this->logger->info('Pipelinq: export run skipped (overlap)', ['runId' => $runId, 'jobId' => $jobId]);
			return $this->runs->markSkippedOverlap(runId: $runId, reason: 'A run for this job is already executing.');
		}

		try {
			return $this->runLocked(runId: $runId, jobId: $jobId, run: $run);
		} catch (\Throwable $e) {
			$this->logger->error('Pipelinq: export run failed', ['runId' => $runId, 'error' => $e->getMessage()]);
			return $this->runs->completeRun(
				runId: $runId,
				outcome: ['status' => 'failed', 'errorMessage' => $e->getMessage()]
			);
		} finally {
			$this->releaseLock(jobId: $jobId);
		}//end try
	}//end executeRun()

	/**
	 * Execute the locked body of a run.
	 *
	 * @param string $runId The run UUID.
	 * @param string $jobId The job UUID.
	 * @param array<string, mixed> $run The pending run.
	 *
	 * @return array<string, mixed> The completed run.
	 */
	private function runLocked(string $runId, string $jobId, array $run): array {
		$job = $this->jobs->getJob(id: $jobId);
		$this->runs->markRunning(runId: $runId);

		$destination = $this->destinations->getDestination(id: (string)($job['destinationId'] ?? ''));
		$destination['compression'] = ($destination['compression'] ?? 'none');

		$watermarkFrom = (string)($run['watermarkFrom'] ?? '');
		$files = [];
		$totalRows = 0;
		$watermarkTo = null;

		foreach (($job['sourceSchemas'] ?? []) as $schemaSlug) {
			$slug = (string)$schemaSlug;

			// Thread the destination compression onto the job for extraction.
			$jobForExtract = array_merge($job, ['compression' => $destination['compression']]);

			$extractWatermark = null;
			if ($watermarkFrom !== '') {
				$extractWatermark = $watermarkFrom;
			}

			$file = $this->data->extractSchema(
				job: $jobForExtract,
				schemaSlug: $slug,
				watermarkFrom: $extractWatermark,
				rowCap: null
			);

			$files[] = $file;
			$totalRows += (int)$file['rows'];
			if ($file['watermark_to'] !== null
				&& ($watermarkTo === null || strcmp((string)$file['watermark_to'], (string)$watermarkTo) > 0)
			) {
				$watermarkTo = $file['watermark_to'];
			}

			$this->snapshotSchema(runId: $runId, jobId: $jobId, slug: $slug, file: $file);
		}//end foreach

		$context = [
			'run_id' => $runId,
			'timestamp' => gmdate('Ymd\THis\Z'),
			'partition' => (string)($job['partitionBy'] ?? ''),
		];

		$upload = $this->uploads->uploadFiles(destination: $destination, files: $files, context: $context);
		$status = $this->mapUploadStatus(uploadStatus: (string)$upload['status']);

		return $this->runs->completeRun(
			runId: $runId,
			outcome: [
				'status' => $status,
				'rowCount' => $totalRows,
				'byteCount' => (int)$upload['byte_count'],
				'fileCount' => (int)$upload['file_count'],
				'manifest' => $upload['manifest'],
				'watermarkTo' => $watermarkTo,
				'destinationAck' => $upload['destination_ack'],
				'errorMessage' => $upload['error_message'],
			]
		);
	}//end runLocked()

	/**
	 * Capture the structure snapshot + drift for one schema of a run.
	 *
	 * @param string $runId The run UUID.
	 * @param string $jobId The job UUID.
	 * @param string $slug The schema slug.
	 * @param array<string, mixed> $file The extracted file descriptor.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/bi-export-and-data-warehouse-sink/specs.md#REQ-BIE-009
	 */
	private function snapshotSchema(string $runId, string $jobId, string $slug, array $file): void {
		$columns = ($file['column_definitions'] ?? []);
		$previous = $this->runs->findPreviousSnapshot(jobId: $jobId, schemaName: $slug);
		$changes = $this->evolution->compareToSnapshot(current: $columns, previous: $previous);

		if ($changes !== []) {
			$this->logger->info('Pipelinq: export schema drift detected', ['schema' => $slug, 'changes' => $changes]);
		}

		$this->runs->createSnapshot(
			runId: $runId,
			schemaName: $slug,
			columnDefinitions: $columns,
			comparedToPrevious: $changes
		);
	}//end snapshotSchema()

	/**
	 * Map the upload service status onto the run status vocabulary.
	 *
	 * @param string $uploadStatus all_succeeded|partial|all_failed.
	 *
	 * @return string The run status.
	 */
	private function mapUploadStatus(string $uploadStatus): string {
		switch ($uploadStatus) {
			case 'all_succeeded':
				return 'succeeded';
			case 'partial':
				return 'partial';
			case 'all_failed':
			default:
				return 'failed';
		}
	}//end mapUploadStatus()

	/**
	 * Acquire the per-job distributed lock (add-if-absent).
	 *
	 * @param string $jobId The job UUID.
	 *
	 * @return bool True when the lock was acquired.
	 */
	private function acquireLock(string $jobId): bool {
		if ($jobId === '') {
			return true;
		}

		$cache = $this->cacheFactory->createDistributed(Application::APP_ID . '-export-lock-');
		return $cache->add($jobId, '1', self::LOCK_TTL);
	}//end acquireLock()

	/**
	 * Release the per-job distributed lock.
	 *
	 * @param string $jobId The job UUID.
	 *
	 * @return void
	 */
	private function releaseLock(string $jobId): void {
		if ($jobId === '') {
			return;
		}

		$cache = $this->cacheFactory->createDistributed(Application::APP_ID . '-export-lock-');
		$cache->remove($jobId);
	}//end releaseLock()
}//end class
