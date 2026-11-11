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
		$pending = new PendingExportRun(run: $run);
		$runId = $pending->runId();
		$jobId = $pending->jobId();

		if ($this->acquireLock(jobId: $jobId) === false) {
			$this->logger->info('Pipelinq: export run skipped (overlap)', ['runId' => $runId, 'jobId' => $jobId]);
			return $this->runs->markSkippedOverlap(runId: $runId, reason: 'A run for this job is already executing.');
		}

		try {
			return $this->runLocked(pending: $pending);
		} catch (\Throwable $e) {
			return $this->failRun(pending: $pending, error: $e);
		} finally {
			$this->releaseLock(jobId: $jobId);
		}//end try
	}//end executeRun()

	/**
	 * Complete a run that threw as failed, and keep it on its job.
	 *
	 * @param PendingExportRun $pending The run.
	 * @param \Throwable       $error   What went wrong.
	 *
	 * @return array<string, mixed> The completed run.
	 */
	private function failRun(PendingExportRun $pending, \Throwable $error): array {
		$runId = $pending->runId();
		$this->logger->error('Pipelinq: export run failed', ['runId' => $runId, 'error' => $error->getMessage()]);
		return $this->recordOnJob(
			jobId: $pending->jobId(),
			run: $this->runs->completeRun(
				runId: $runId,
				outcome: ['status' => 'failed', 'errorMessage' => $error->getMessage()]
			)
		);
	}//end failRun()

	/**
	 * Execute the locked body of a run.
	 *
	 * @param PendingExportRun $pending The pending run.
	 *
	 * @return array<string, mixed> The completed run.
	 */
	private function runLocked(PendingExportRun $pending): array {
		$job = $this->jobs->getJob(id: $pending->jobId());
		$this->runs->markRunning(runId: $pending->runId());
		$destination = $this->destinationOf(job: $job);

		// Thread the destination compression onto the job for extraction.
		$extraction = new ExportExtraction(
			pending: $pending,
			job: array_merge($job, ['compression' => $destination['compression']])
		);
		$this->extractSources(extraction: $extraction);

		$upload = $this->uploads->uploadFiles(
			destination: $destination,
			files: $extraction->files,
			context: self::uploadContext(pending: $pending, job: $job)
		);
		$completed = $this->runs->completeRun(
			runId: $pending->runId(),
			outcome: $this->outcomeOf(extraction: $extraction, upload: $upload)
		);

		return $this->recordOnJob(jobId: $pending->jobId(), run: $completed);
	}//end runLocked()

	/**
	 * The job's destination, with its compression defaulted to 'none'.
	 *
	 * @param array<string, mixed> $job The export job.
	 *
	 * @return array<string, mixed>
	 */
	private function destinationOf(array $job): array {
		$destination = $this->destinations->getDestination(id: (string)($job['destinationId'] ?? ''));
		$destination['compression'] = ($destination['compression'] ?? 'none');

		return $destination;
	}//end destinationOf()

	/**
	 * Extract every source schema of the job, snapshotting each one.
	 *
	 * @param ExportExtraction $extraction The extraction to fill.
	 *
	 * @return void
	 */
	private function extractSources(ExportExtraction $extraction): void {
		foreach (($extraction->job['sourceSchemas'] ?? []) as $schemaSlug) {
			$this->extractSource(extraction: $extraction, slug: (string)$schemaSlug);
		}
	}//end extractSources()

	/**
	 * Extract one source schema, add its file and snapshot its structure.
	 *
	 * @param ExportExtraction $extraction The extraction to fill.
	 * @param string           $slug       The schema slug.
	 *
	 * @return void
	 */
	private function extractSource(ExportExtraction $extraction, string $slug): void {
		$pending = $extraction->pending;
		$file = $this->data->extractSchema(
			job: $extraction->job,
			schemaSlug: $slug,
			watermarkFrom: $pending->watermarkFrom(),
			rowCap: null
		);

		self::addFile(extraction: $extraction, file: $file);
		$this->snapshotSchema(runId: $pending->runId(), jobId: $pending->jobId(), slug: $slug, file: $file);
	}//end extractSource()

	/**
	 * Add an extracted file: its rows to the total and a newer watermark.
	 *
	 * @param ExportExtraction     $extraction The extraction to fill.
	 * @param array<string, mixed> $file       The extracted file descriptor.
	 *
	 * @return void
	 */
	private static function addFile(ExportExtraction $extraction, array $file): void {
		$extraction->files[] = $file;
		$extraction->totalRows += (int)$file['rows'];
		if (self::isNewerWatermark(candidate: $file['watermark_to'], current: $extraction->watermarkTo) === true) {
			$extraction->watermarkTo = $file['watermark_to'];
		}
	}//end addFile()

	/**
	 * Whether a file's watermark is set and newer than the newest so far.
	 *
	 * @param mixed $candidate The file's watermark_to.
	 * @param mixed $current   The newest watermark so far, or null.
	 *
	 * @return bool
	 */
	private static function isNewerWatermark(mixed $candidate, mixed $current): bool {
		return $candidate !== null
			&& ($current === null || strcmp((string)$candidate, (string)$current) > 0);
	}//end isNewerWatermark()

	/**
	 * The upload context: run id, timestamp and the job's partitioning.
	 *
	 * @param PendingExportRun     $pending The run.
	 * @param array<string, mixed> $job     The export job.
	 *
	 * @return array<string, string>
	 */
	private static function uploadContext(PendingExportRun $pending, array $job): array {
		return [
			'run_id' => $pending->runId(),
			'timestamp' => gmdate('Ymd\THis\Z'),
			'partition' => (string)($job['partitionBy'] ?? ''),
		];
	}//end uploadContext()

	/**
	 * The run outcome from the extraction and the upload result.
	 *
	 * @param ExportExtraction     $extraction The finished extraction.
	 * @param array<string, mixed> $upload     The upload result.
	 *
	 * @return array<string, mixed>
	 */
	private function outcomeOf(ExportExtraction $extraction, array $upload): array {
		return [
			'status' => $this->mapUploadStatus(uploadStatus: (string)$upload['status']),
			'rowCount' => $extraction->totalRows,
			'byteCount' => (int)$upload['byte_count'],
			'fileCount' => (int)$upload['file_count'],
			'manifest' => $upload['manifest'],
			'watermarkTo' => $extraction->watermarkTo,
			'destinationAck' => $upload['destination_ack'],
			'errorMessage' => $upload['error_message'],
		];
	}//end outcomeOf()

	/**
	 * Keep a finished run's summary and the next run on its job. A failure
	 * here is logged and never fails the run itself.
	 *
	 * @param string $jobId The job UUID.
	 * @param array<string, mixed> $run The finished run.
	 *
	 * @return array<string, mixed> The run, unchanged.
	 *
	 * @spec openspec/specs/bi-export-board/spec.md#requirement-a-job-shows-its-last-run-and-its-next-run
	 */
	private function recordOnJob(string $jobId, array $run): array {
		try {
			$this->jobs->recordRun(jobId: $jobId, run: $run);
		} catch (\Throwable $e) {
			$this->logger->warning('Pipelinq: could not keep the last run on its export job', ['jobId' => $jobId, 'error' => $e->getMessage()]);
		}

		return $run;
	}//end recordOnJob()

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
