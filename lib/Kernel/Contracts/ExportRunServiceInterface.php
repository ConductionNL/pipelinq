<?php

/**
 * Contract of ExportRunService
 *
 * The public surface of ExportRunService that other components call, so they depend
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

use OCP\AppFramework\OCS\OCSNotFoundException;

/**
 * Contract of ExportRunService.
 *
 * @category Kernel
 * @package  OCA\Pipelinq\Kernel\Contracts
 * @author   Conduction b.v. <info@conduction.nl>
 * @license  EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link     https://github.com/ConductionNL/pipelinq
 *
 * @spec exclude Structural contract, no functional requirement.
 */
interface ExportRunServiceInterface {

	/**
	 * Create a pending run for a job.
	 *
	 * @param array<string, mixed> $job The export job configuration.
	 * @param string|null $watermarkFrom The previous run's watermarkTo (incremental).
	 *
	 * @return array<string, mixed> The created exportRun.
	 *
	 * @spec openspec/changes/archive/2026-06-14-bi-export-and-data-warehouse-sink/specs.md#REQ-BIE-010
	 */
	public function createPendingRun(array $job, ?string $watermarkFrom = null): array;

	/**
	 * Find a run by id (scoped to this app's exportRun schema).
	 *
	 * @param string $runId The run UUID.
	 *
	 * @return array<string, mixed> The run.
	 *
	 * @throws OCSNotFoundException When the run does not exist.
	 * @spec openspec/specs/bi-export-and-data-warehouse-sink/spec.md#requirement-destination-configuration-and-validation-req-bie-001
	 */
	public function getRun(string $runId): array;

	/**
	 * Most recent succeeded run for a job (for the incremental watermark).
	 *
	 * @param string $jobId The job UUID.
	 *
	 * @return array<string, mixed>|null The last succeeded run, or null.
	 *
	 * @spec openspec/changes/archive/2026-06-14-bi-export-and-data-warehouse-sink/specs.md#REQ-BIE-006-01
	 */
	public function lastSucceededRun(string $jobId): ?array;

	/**
	 * List runs with optional filters (job/status/date range), newest first.
	 *
	 * @param array<string, mixed> $filters job_id, status, date_from, date_to.
	 *
	 * @return array<int, array<string, mixed>> The matching runs.
	 *
	 * @spec openspec/changes/archive/2026-06-14-bi-export-and-data-warehouse-sink/specs.md#REQ-BIE-011-01
	 */
	public function listRuns(array $filters = []): array;

	/**
	 * List the schema snapshots for a run.
	 *
	 * @param string $runId The run UUID.
	 *
	 * @return array<int, array<string, mixed>> The snapshots.
	 * @spec openspec/specs/bi-export-and-data-warehouse-sink/spec.md#requirement-destination-configuration-and-validation-req-bie-001
	 */
	public function listSnapshots(string $runId): array;
}//end interface
