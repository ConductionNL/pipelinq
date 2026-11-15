<?php

/**
 * Contract of ExportExecutionService
 *
 * The public surface of ExportExecutionService that other components call, so they depend
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

/**
 * Contract of ExportExecutionService.
 *
 * @category Kernel
 * @package  OCA\Pipelinq\Kernel\Contracts
 * @author   Conduction b.v. <info@conduction.nl>
 * @license  EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link     https://github.com/ConductionNL/pipelinq
 *
 * @spec exclude Structural contract, no functional requirement.
 */
interface ExportExecutionServiceInterface {

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
	 * @spec openspec/changes/archive/2026-06-14-bi-export-and-data-warehouse-sink/specs.md#REQ-BIE-004-02
	 * @spec openspec/changes/archive/2026-06-14-bi-export-and-data-warehouse-sink/specs.md#REQ-BIE-004-03
	 */
	public function executeRun(array $run): array;
}//end interface
