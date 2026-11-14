<?php

/**
 * Pipelinq ExportJobService.
 *
 * CRUD + validation + scheduling for export jobs, plus the pre-enablement test
 * run. Validates destination validity, incremental watermark, cron syntax,
 * source-schema existence and the column allowlist (PII minimisation). Jobs
 * default to disabled until tested and explicitly enabled.
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
 * @spec openspec/changes/archive/2026-06-14-bi-export-and-data-warehouse-sink/specs.md#REQ-BIE-002
 * @spec openspec/changes/archive/2026-06-14-bi-export-and-data-warehouse-sink/specs.md#REQ-BIE-003
 */

declare(strict_types=1);

namespace OCA\Pipelinq\Service\Export;

use DateTimeImmutable;
use OCA\OpenRegister\Contract\ObjectServiceInterface;
use OCA\Pipelinq\Kernel\AppIdentity;
use OCA\Pipelinq\Kernel\Contracts\ExportJobServiceInterface;
use OCP\AppFramework\OCS\OCSBadRequestException;
use OCP\AppFramework\OCS\OCSNotFoundException;
use OCP\IAppConfig;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;

/**
 * Export job configuration, validation, scheduling and test runs.
 *
 * @SuppressWarnings(PHPMD.CouplingBetweenObjects) Coordinates the export
 *  destination / data / run collaborators a job service legitimately needs.
 *
 * @spec openspec/changes/archive/2026-06-14-bi-export-and-data-warehouse-sink/specs.md#REQ-BIE-002
 */
class ExportJobService extends AbstractExportService implements ExportJobServiceInterface {
	/**
	 * The exportJob schema config key.
	 *
	 * @var string
	 */
	private const SCHEMA_KEY = 'exportJob_schema';

	/**
	 * The app config key of the failed runs in a row that switch a job off.
	 *
	 * @var string
	 */
	public const FAILURE_THRESHOLD_KEY = 'export_failure_threshold';

	/**
	 * The default number of failed runs in a row that switch a job off.
	 *
	 * @var int
	 */
	public const DEFAULT_FAILURE_THRESHOLD = 3;

	/**
	 * The lowest threshold an administrator may set.
	 *
	 * @var int
	 */
	public const MIN_FAILURE_THRESHOLD = 1;

	/**
	 * The highest threshold an administrator may set.
	 *
	 * @var int
	 */
	public const MAX_FAILURE_THRESHOLD = 10;

	/**
	 * Constructor.
	 *
	 * @param ContainerInterface $container The DI container.
	 * @param IAppConfig $appConfig The app config.
	 * @param ObjectServiceInterface $objectService The published OpenRegister contract.
	 * @param ExportDestinationService $destinations The destination service.
	 * @param ExportDataService $data The data extraction service.
	 * @param CronExpressionHelper $cron The cron helper.
	 * @param LoggerInterface $logger The logger.
	 * @param ExportRunSummary $summary A job's last and next run.
	 * @param ExportJobSwitchOffNotifier|null $switchOffNotifier Tells the administrators a job was switched off.
	 */
	public function __construct(
		ContainerInterface $container,
		IAppConfig $appConfig,
		ObjectServiceInterface $objectService,
		private ExportDestinationService $destinations,
		private ExportDataService $data,
		private CronExpressionHelper $cron,
		private LoggerInterface $logger,
		private ExportRunSummary $summary,
		private ?ExportJobSwitchOffNotifier $switchOffNotifier=null,
	) {
		parent::__construct(
			container: $container,
			appConfig: $appConfig,
			objectService: $objectService
		);
	}//end __construct()

	/**
	 * List all export jobs.
	 *
	 * @return array<int, array<string, mixed>> The jobs.
	 *
	 * @spec openspec/changes/archive/2026-06-14-bi-export-and-data-warehouse-sink/specs.md#REQ-BIE-002
	 */
	public function listJobs(): array {
		return $this->findAllObjects(schemaKey: self::SCHEMA_KEY);
	}//end listJobs()

	/**
	 * Get a job by id.
	 *
	 * @param string $id The job UUID.
	 *
	 * @return array<string, mixed> The job.
	 *
	 * @throws OCSNotFoundException When absent.
	 * @spec openspec/specs/bi-export-and-data-warehouse-sink/spec.md#requirement-destination-configuration-and-validation-req-bie-001
	 */
	public function getJob(string $id): array {
		$job = $this->findObjectById(schemaKey: self::SCHEMA_KEY, id: $id);
		if ($job === null) {
			throw new OCSNotFoundException('Export job not found.');
		}

		return $job;
	}//end getJob()

	/**
	 * Create a job (always disabled until tested + enabled).
	 *
	 * @param array<string, mixed> $data The job config.
	 * @param string $userId The creating user UID.
	 *
	 * @return array<string, mixed> The created job.
	 *
	 * @throws OCSBadRequestException On validation failure.
	 *
	 * @spec openspec/changes/archive/2026-06-14-bi-export-and-data-warehouse-sink/specs.md#REQ-BIE-002-01
	 */
	public function createJob(array $data, string $userId): array {
		$this->validateJob(data: $data);

		$data = $this->sanitize(data: $data);
		$data['enabled'] = false;
		$data['createdBy'] = $userId;
		$data['createdAt'] = $this->now();
		$data['scheduleCron'] = $this->cron->normalize(expression: (string)($data['scheduleCron'] ?? ''));
		$data['nextRunAt'] = null;

		return $this->saveObjectData(schemaKey: self::SCHEMA_KEY, data: $data, id: null);
	}//end createJob()

	/**
	 * Update a job (re-validates; disables it until re-tested).
	 *
	 * @param string $id The job UUID.
	 * @param array<string, mixed> $data The new config.
	 *
	 * @return array<string, mixed> The updated job.
	 *
	 * @throws OCSBadRequestException On validation failure.
	 * @throws OCSNotFoundException When absent.
	 * @spec openspec/specs/bi-export-and-data-warehouse-sink/spec.md#requirement-destination-configuration-and-validation-req-bie-001
	 */
	public function updateJob(string $id, array $data): array {
		$existing = $this->getJob(id: $id);
		$merged = array_merge($existing, $this->sanitize(data: $data));
		$this->validateJob(data: $merged);

		$merged['enabled'] = false;
		$merged['scheduleCron'] = $this->cron->normalize(expression: (string)($merged['scheduleCron'] ?? ''));
		$merged['nextRunAt'] = null;

		return $this->saveObjectData(schemaKey: self::SCHEMA_KEY, data: $merged, id: $id);
	}//end updateJob()

	/**
	 * Delete a job.
	 *
	 * @param string $id The job UUID.
	 *
	 * @return void
	 *
	 * @throws OCSNotFoundException When absent.
	 * @spec openspec/specs/bi-export-and-data-warehouse-sink/spec.md#requirement-destination-configuration-and-validation-req-bie-001
	 */
	public function deleteJob(string $id): void {
		$this->getJob(id: $id);
		$this->deleteObjectById(schemaKey: self::SCHEMA_KEY, id: $id);
	}//end deleteJob()

	/**
	 * Enable a job for scheduled execution.
	 *
	 * Refuses enablement when the destination is not valid (REQ-BIE-001-02).
	 *
	 * @param string $id The job UUID.
	 *
	 * @return array<string, mixed> The enabled job.
	 *
	 * @throws OCSBadRequestException When the destination is invalid.
	 * @throws OCSNotFoundException When the job is absent.
	 *
	 * @spec openspec/changes/archive/2026-06-14-bi-export-and-data-warehouse-sink/specs.md#REQ-BIE-002
	 * @spec openspec/changes/archive/2026-06-14-bi-export-and-data-warehouse-sink/specs.md#REQ-BIE-001-02
	 */
	public function enableJob(string $id): array {
		$job = $this->getJob(id: $id);
		$destinationId = (string)($job['destinationId'] ?? '');

		if ($this->destinations->isValid(id: $destinationId) === false) {
			throw new OCSBadRequestException('Destination is invalid. Test connection first.');
		}

		$job['enabled'] = true;
		$job['nextRunAt'] = $this->summary->nextRunAt(job: $job, now: new DateTimeImmutable());
		// Switching on starts the count again and clears an automatic switch-off.
		$job['consecutiveFailures'] = 0;
		$job['pausedReason'] = null;
		$job['pausedAt'] = null;

		return $this->saveObjectData(schemaKey: self::SCHEMA_KEY, data: $job, id: $id);
	}//end enableJob()

	/**
	 * Disable a job.
	 *
	 * @param string $id The job UUID.
	 *
	 * @return array<string, mixed> The disabled job.
	 *
	 * @throws OCSNotFoundException When absent.
	 * @spec openspec/specs/bi-export-and-data-warehouse-sink/spec.md#requirement-destination-configuration-and-validation-req-bie-001
	 */
	public function disableJob(string $id): array {
		$job = $this->getJob(id: $id);
		$job['enabled'] = false;
		$job['nextRunAt'] = null;

		return $this->saveObjectData(schemaKey: self::SCHEMA_KEY, data: $job, id: $id);
	}//end disableJob()

	/**
	 * Keep a finished run on its job: the last run's summary and the next run.
	 *
	 * @param string $jobId The job UUID.
	 * @param array<string, mixed> $run The finished run.
	 *
	 * @return array<string, mixed> The updated job.
	 *
	 * @throws OCSNotFoundException When the job is absent.
	 * A succeeded run sets the job's failed runs in a row back to 0, a failed
	 * run adds one and any other outcome leaves the count. An enabled job that
	 * reaches the threshold is switched off in the same save, with the reason
	 * and the time, and the administrators are told once.
	 *
	 * @spec openspec/specs/bi-export-board/spec.md#requirement-a-job-shows-its-last-run-and-its-next-run
	 * @spec openspec/changes/exports-switch-off-after-repeated-failure/specs/export-failure-switch-off/spec.md#requirement-an-export-job-switches-off-after-repeated-failed-runs-req-efs-001
	 */
	public function recordRun(string $jobId, array $run): array {
		$job = array_merge($this->getJob(id: $jobId), $this->summary->lastRunSummary(run: $run));
		$failures = $this->failuresAfter(job: $job, status: (string)($run['status'] ?? ''));
		$job['consecutiveFailures'] = $failures;

		$switchOff = (($job['enabled'] ?? false) === true
			&& ($run['status'] ?? '') === 'failed'
			&& $failures >= $this->failureThreshold());
		if ($switchOff === true) {
			$job['enabled'] = false;
			$job['nextRunAt'] = null;
			$job['pausedReason'] = sprintf('Switched off after %d failed runs in a row', $failures);
			$job['pausedAt'] = $this->now();
		} else {
			$job['nextRunAt'] = $this->summary->nextRunAt(job: $job, now: new DateTimeImmutable());
		}

		$saved = $this->saveObjectData(schemaKey: self::SCHEMA_KEY, data: $job, id: $jobId);
		if ($switchOff === true) {
			$this->switchOffNotifier?->notifySwitchedOff(
				job: array_merge($job, $saved),
				failures: $failures,
				lastError: (string)($run['errorMessage'] ?? '')
			);
		}

		return $saved;
	}//end recordRun()

	/**
	 * The failed runs in a row once a run with this status has finished.
	 *
	 * @param array<string, mixed> $job The job.
	 * @param string $status The finished run's status.
	 *
	 * @return int The new count.
	 *
	 * @spec openspec/changes/exports-switch-off-after-repeated-failure/specs/export-failure-switch-off/spec.md#requirement-an-export-job-switches-off-after-repeated-failed-runs-req-efs-001
	 */
	private function failuresAfter(array $job, string $status): int {
		$count = max(0, (int)($job['consecutiveFailures'] ?? 0));

		return match ($status) {
			'succeeded' => 0,
			'failed' => $count + 1,
			default => $count,
		};
	}//end failuresAfter()

	/**
	 * The failed runs in a row that switch a job off: the app config value,
	 * kept within 1 to 10, else 3.
	 *
	 * @return int The threshold.
	 *
	 * @spec openspec/changes/exports-switch-off-after-repeated-failure/specs/export-failure-switch-off/spec.md#requirement-an-administrator-sets-the-threshold-req-efs-004
	 */
	public function failureThreshold(): int {
		$value = trim(
			$this->appConfig->getValueString(
				AppIdentity::APP_ID,
				self::FAILURE_THRESHOLD_KEY,
				(string)self::DEFAULT_FAILURE_THRESHOLD
			)
		);
		if (self::isValidThreshold(value: $value) === false) {
			return self::DEFAULT_FAILURE_THRESHOLD;
		}

		return (int)$value;
	}//end failureThreshold()

	/**
	 * Whether a value is a whole number from 1 to 10.
	 *
	 * @param string $value The value as entered or stored.
	 *
	 * @return bool
	 *
	 * @spec openspec/changes/exports-switch-off-after-repeated-failure/specs/export-failure-switch-off/spec.md#requirement-an-administrator-sets-the-threshold-req-efs-004
	 */
	public static function isValidThreshold(string $value): bool {
		if (preg_match('/^[0-9]+$/', $value) !== 1) {
			return false;
		}

		$number = (int)$value;

		return $number >= self::MIN_FAILURE_THRESHOLD && $number <= self::MAX_FAILURE_THRESHOLD;
	}//end isValidThreshold()

	/**
	 * Run a non-destructive test of a job: extract <=100 rows per schema and
	 * format them, reporting validation and the sample.
	 *
	 * Does NOT upload (REQ-BIE-003-01) — it validates the schema references and
	 * the format. The sample is held in the result for inspection.
	 *
	 * @param array<string, mixed> $job The job config (saved or unsaved).
	 *
	 * @return array<string, mixed> Result: success, sample_rows, errors, samples[].
	 *
	 * @spec openspec/changes/archive/2026-06-14-bi-export-and-data-warehouse-sink/specs.md#REQ-BIE-003-01
	 */
	public function testRun(array $job): array {
		$errors = [];
		$samples = [];
		$sampleRows = 0;

		$schemas = $job['sourceSchemas'] ?? [];
		if (is_array($schemas) === false || $schemas === []) {
			return ['success' => false, 'sample_rows' => 0, 'errors' => ['No source schemas selected.'], 'samples' => []];
		}

		foreach ($schemas as $schemaSlug) {
			$slug = (string)$schemaSlug;
			try {
				$file = $this->data->extractSchema(
					job: $job,
					schemaSlug: $slug,
					watermarkFrom: null,
					rowCap: ExportDataService::TEST_RUN_ROW_CAP
				);
				$sampleRows += (int)$file['rows'];
				$samples[] = [
					'schema' => $slug,
					'rows' => (int)$file['rows'],
					'format' => (string)($job['format'] ?? 'csv'),
					'dropped_columns' => $file['dropped_columns'],
					'preview' => $this->preview(contents: (string)$file['contents']),
				];
			} catch (\Throwable $e) {
				$errors[] = $e->getMessage();
			}//end try
		}//end foreach

		return [
			'success' => ($errors === []),
			'sample_rows' => $sampleRows,
			'errors' => $errors,
			'samples' => $samples,
		];
	}//end testRun()

	/**
	 * A human-readable cron preview for a job's schedule.
	 *
	 * @param string $expression The cron expression.
	 * @param string $locale 'nl' or 'en'.
	 *
	 * @return string The preview text.
	 *
	 * @spec openspec/changes/archive/2026-06-14-bi-export-and-data-warehouse-sink/specs.md#REQ-BIE-002-03
	 */
	public function describeSchedule(string $expression, string $locale = 'en'): string {
		return $this->cron->describe(expression: $expression, locale: $locale);
	}//end describeSchedule()

	/**
	 * Validate a job configuration.
	 *
	 * @param array<string, mixed> $data The config.
	 *
	 * @return void
	 *
	 * @throws OCSBadRequestException On any validation failure.
	 *
	 * @spec openspec/changes/archive/2026-06-14-bi-export-and-data-warehouse-sink/specs.md#REQ-BIE-002
	 */
	private function validateJob(array $data): void {
		if (trim((string)($data['name'] ?? '')) === '') {
			throw new OCSBadRequestException('Job name is required.');
		}

		$this->requireSourceSchemas(data: $data);
		$this->requireFormatAndMode(data: $data);
		if ($this->cron->isValid(expression: (string)($data['scheduleCron'] ?? '')) === false) {
			throw new OCSBadRequestException('Invalid cron expression.');
		}

		if (trim((string)($data['destinationId'] ?? '')) === '') {
			throw new OCSBadRequestException('A destination is required.');
		}
	}//end validateJob()

	/**
	 * Require at least one source schema, each of which exists.
	 *
	 * @param array<string, mixed> $data The config.
	 *
	 * @return void
	 *
	 * @throws OCSBadRequestException When there is none, or one does not exist.
	 */
	private function requireSourceSchemas(array $data): void {
		$schemas = $data['sourceSchemas'] ?? [];
		if (is_array($schemas) === false || $schemas === []) {
			throw new OCSBadRequestException('At least one source schema is required.');
		}

		foreach ($schemas as $schemaSlug) {
			if ($this->data->schemaExists(slug: (string)$schemaSlug) === false) {
				throw new OCSBadRequestException("Schema '{$schemaSlug}' does not exist.");
			}
		}
	}//end requireSourceSchemas()

	/**
	 * Require a supported format and mode, and a watermark column for incremental mode.
	 *
	 * @param array<string, mixed> $data The config.
	 *
	 * @return void
	 *
	 * @throws OCSBadRequestException On an unsupported format or mode, or a missing watermark column.
	 */
	private function requireFormatAndMode(array $data): void {
		$format = (string)($data['format'] ?? '');
		if (in_array($format, ['csv', 'parquet', 'jsonl'], true) === false) {
			throw new OCSBadRequestException("Unsupported format '{$format}'.");
		}

		$mode = (string)($data['mode'] ?? '');
		if (in_array($mode, ['full', 'incremental'], true) === false) {
			throw new OCSBadRequestException("Unsupported mode '{$mode}'.");
		}

		if ($mode === 'incremental' && trim((string)($data['incrementalWatermarkColumn'] ?? '')) === '') {
			throw new OCSBadRequestException('Watermark column is required for incremental mode.');
		}
	}//end requireFormatAndMode()

	/**
	 * Strip never-trusted fields from a job payload.
	 *
	 * @param array<string, mixed> $data The raw input.
	 *
	 * @return array<string, mixed> The sanitised payload.
	 */
	private function sanitize(array $data): array {
		unset($data['@self'], $data['id'], $data['uuid']);

		return $data;
	}//end sanitize()

	/**
	 * First ~500 bytes of a formatted sample for inspection.
	 *
	 * @param string $contents The formatted payload.
	 *
	 * @return string The preview.
	 */
	private function preview(string $contents): string {
		if (strlen($contents) <= 500) {
			return $contents;
		}

		return substr($contents, 0, 500) . '…';
	}//end preview()
}//end class
