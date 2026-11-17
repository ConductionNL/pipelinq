<?php

/**
 * Contract of ExportJobService
 *
 * The public surface of ExportJobService that other components call, so they depend
 * on this contract instead of the implementation.
 *
 * @category  Kernel
 * @package   OCA\Pipelinq\Kernel\Contracts
 * @author    Conduction b.v. <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @version   GIT: <git_id>
 * @link      https://github.com/ConductionNL/pipelinq
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Pipelinq\Kernel\Contracts;

use OCP\AppFramework\OCS\OCSBadRequestException;
use OCP\AppFramework\OCS\OCSNotFoundException;

/**
 * Contract of ExportJobService.
 *
 * @category Kernel
 * @package  OCA\Pipelinq\Kernel\Contracts
 * @author   Conduction b.v. <info@conduction.nl>
 * @license  EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link     https://github.com/ConductionNL/pipelinq
 *
 * @spec exclude Structural contract, no functional requirement.
 */
interface ExportJobServiceInterface {

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
	public function createJob(array $data, string $userId): array;

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
	public function deleteJob(string $id): void;

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
	public function disableJob(string $id): array;

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
	public function enableJob(string $id): array;

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
	public function getJob(string $id): array;

	/**
	 * List all export jobs.
	 *
	 * @return array<int, array<string, mixed>> The jobs.
	 *
	 * @spec openspec/changes/archive/2026-06-14-bi-export-and-data-warehouse-sink/specs.md#REQ-BIE-002
	 */
	public function listJobs(): array;

	/**
	 * A job's schedule in words, for the schedule dialog.
	 *
	 * @param string $expression The cron expression.
	 * @param string $locale     'nl' or 'en'; any other falls back to English.
	 *
	 * @return string The schedule in words; the normalized expression when it has no wording; '' when it is not a valid expression.
	 *
	 * @spec openspec/changes/reports-compare-periods-and-scheduled-delivery/specs/report-periods-and-delivery/spec.md
	 */
	public function describeSchedule(string $expression, string $locale = 'en'): string;

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
	public function testRun(array $job): array;

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
	public function updateJob(string $id, array $data): array;
}//end interface
