<?php

/**
 * Pipelinq ExportExtraction.
 *
 * One export run's extraction: the run, the job it extracts for, and the
 * files extracted so far, with their total row count and newest watermark.
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

/**
 * The extracted files of one run, in progress; built and filled by ExportExecutionService.
 *
 * @spec openspec/changes/bi-export-and-data-warehouse-sink/specs.md#REQ-BIE-004
 */
final class ExportExtraction {
	/**
	 * Constructor.
	 *
	 * @param PendingExportRun     $pending The run being executed.
	 * @param array<string, mixed> $job     The job, with the destination compression threaded on.
	 */
	public function __construct(
		public readonly PendingExportRun $pending,
		public readonly array $job,
	) {
	}//end __construct()

	/**
	 * The extracted file descriptors, in extraction order.
	 *
	 * @var array<int, array<string, mixed>>
	 */
	public array $files = [];

	/**
	 * The summed row count of the files.
	 *
	 * @var integer
	 */
	public int $totalRows = 0;

	/**
	 * The newest watermark among the files, or null.
	 *
	 * @var mixed
	 */
	public mixed $watermarkTo = null;
}//end class
