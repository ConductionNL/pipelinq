<?php

/**
 * Pipelinq SchemaExtractRun.
 *
 * @category Service
 * @package  OCA\Pipelinq\Service\Export
 * @author   Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license  EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @version  GIT: <git_id>
 * @link     https://pipelinq.app
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @spec openspec/changes/archive/2026-06-14-bi-export-and-data-warehouse-sink/specs.md#REQ-BIE-005
 */

declare(strict_types=1);

namespace OCA\Pipelinq\Service\Export;

/**
 * One schema extraction of an export job in progress: the job, the rows as
 * they are filtered and narrowed, the columns dropped and the new watermark.
 * Built and used only by ExportDataService::extractSchema.
 *
 * @spec openspec/changes/archive/2026-06-14-bi-export-and-data-warehouse-sink/specs.md#REQ-BIE-005
 */
final class SchemaExtractRun {
	/**
	 * The columns the allowlist dropped.
	 *
	 * @var array<int, string>
	 */
	public array $droppedColumns = [];

	/**
	 * The highest watermark value seen, on an incremental run.
	 *
	 * @var string|null
	 */
	public ?string $watermarkTo = null;

	/**
	 * Constructor.
	 *
	 * @param array<string, mixed>             $job  The export job configuration.
	 * @param array<int, array<string, mixed>> $rows The fetched rows.
	 */
	public function __construct(
		public readonly array $job,
		public array $rows,
	) {
	}//end __construct()

	/**
	 * Whether the job extracts incrementally from a watermark.
	 *
	 * @return bool True for an incremental job.
	 *
	 * @spec openspec/changes/archive/2026-06-14-bi-export-and-data-warehouse-sink/specs.md#REQ-BIE-006
	 */
	public function isIncremental(): bool {
		return ($this->job['mode'] ?? 'full') === 'incremental';
	}//end isIncremental()
}//end class
