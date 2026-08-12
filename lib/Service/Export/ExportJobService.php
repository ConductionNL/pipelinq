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
 * @spec openspec/changes/bi-export-and-data-warehouse-sink/specs.md#REQ-BIE-002
 * @spec openspec/changes/bi-export-and-data-warehouse-sink/specs.md#REQ-BIE-003
 */

declare(strict_types=1);

namespace OCA\Pipelinq\Service\Export;

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
 * @spec openspec/changes/bi-export-and-data-warehouse-sink/specs.md#REQ-BIE-002
 */
class ExportJobService extends AbstractExportService {
	/**
	 * The exportJob schema config key.
	 *
	 * @var string
	 */
	private const SCHEMA_KEY = 'exportJob_schema';

	/**
	 * Constructor.
	 *
	 * @param ContainerInterface $container The DI container.
	 * @param IAppConfig $appConfig The app config.
	 * @param ExportDestinationService $destinations The destination service.
	 * @param ExportDataService $data The data extraction service.
	 * @param CronExpressionHelper $cron The cron helper.
	 * @param LoggerInterface $logger The logger.
	 */
	public function __construct(
		ContainerInterface $container,
		IAppConfig $appConfig,
		private ExportDestinationService $destinations,
		private ExportDataService $data,
		private CronExpressionHelper $cron,
		private LoggerInterface $logger,
	) {
		parent::__construct(container: $container, appConfig: $appConfig);
	}//end __construct()

	/**
	 * List all export jobs.
	 *
	 * @return array<int, array<string, mixed>> The jobs.
	 *
	 * @spec openspec/changes/bi-export-and-data-warehouse-sink/specs.md#REQ-BIE-002
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
	 * @spec openspec/changes/bi-export-and-data-warehouse-sink/specs.md#REQ-BIE-002-01
	 */
	public function createJob(array $data, string $userId): array {
		$this->validateJob(data: $data);

		$data = $this->sanitize(data: $data);
		$data['enabled'] = false;
		$data['createdBy'] = $userId;
		$data['createdAt'] = $this->now();
		$data['scheduleCron'] = $this->cron->normalize(expression: (string)($data['scheduleCron'] ?? ''));

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
	 */
	public function updateJob(string $id, array $data): array {
		$existing = $this->getJob(id: $id);
		$merged = array_merge($existing, $this->sanitize(data: $data));
		$this->validateJob(data: $merged);

		$merged['enabled'] = false;
		$merged['scheduleCron'] = $this->cron->normalize(expression: (string)($merged['scheduleCron'] ?? ''));

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
	 * @spec openspec/changes/bi-export-and-data-warehouse-sink/specs.md#REQ-BIE-002
	 * @spec openspec/changes/bi-export-and-data-warehouse-sink/specs.md#REQ-BIE-001-02
	 */
	public function enableJob(string $id): array {
		$job = $this->getJob(id: $id);
		$destinationId = (string)($job['destinationId'] ?? '');

		if ($this->destinations->isValid(id: $destinationId) === false) {
			throw new OCSBadRequestException('Destination is invalid. Test connection first.');
		}

		$job['enabled'] = true;

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
	 */
	public function disableJob(string $id): array {
		$job = $this->getJob(id: $id);
		$job['enabled'] = false;

		return $this->saveObjectData(schemaKey: self::SCHEMA_KEY, data: $job, id: $id);
	}//end disableJob()

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
	 * @spec openspec/changes/bi-export-and-data-warehouse-sink/specs.md#REQ-BIE-003-01
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
	 * @spec openspec/changes/bi-export-and-data-warehouse-sink/specs.md#REQ-BIE-002-03
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
	 * @SuppressWarnings(PHPMD.CyclomaticComplexity) A flat list of independent
	 *  required-field / enum / cron / watermark guards, each a single condition.
	 * @SuppressWarnings(PHPMD.NPathComplexity)      See note — sequential guards only.
	 *
	 * @spec openspec/changes/bi-export-and-data-warehouse-sink/specs.md#REQ-BIE-002
	 */
	private function validateJob(array $data): void {
		if (trim((string)($data['name'] ?? '')) === '') {
			throw new OCSBadRequestException('Job name is required.');
		}

		$schemas = $data['sourceSchemas'] ?? [];
		if (is_array($schemas) === false || $schemas === []) {
			throw new OCSBadRequestException('At least one source schema is required.');
		}

		foreach ($schemas as $schemaSlug) {
			if ($this->data->schemaExists(slug: (string)$schemaSlug) === false) {
				throw new OCSBadRequestException("Schema '{$schemaSlug}' does not exist.");
			}
		}

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

		if ($this->cron->isValid(expression: (string)($data['scheduleCron'] ?? '')) === false) {
			throw new OCSBadRequestException('Invalid cron expression.');
		}

		if (trim((string)($data['destinationId'] ?? '')) === '') {
			throw new OCSBadRequestException('A destination is required.');
		}
	}//end validateJob()

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
