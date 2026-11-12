<?php

/**
 * Pipelinq PendingExportRun.
 *
 * A pending exportRun as ExportExecutionService reads it: its id, its job and
 * the watermark it continues from.
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
 * @spec openspec/changes/archive/2026-06-14-bi-export-and-data-warehouse-sink/specs.md#REQ-BIE-004
 */

declare(strict_types=1);

namespace OCA\Pipelinq\Service\Export;

/**
 * A pending export run.
 *
 * @spec openspec/changes/archive/2026-06-14-bi-export-and-data-warehouse-sink/specs.md#REQ-BIE-004
 */
final class PendingExportRun {
	/**
	 * Constructor.
	 *
	 * @param array<string, mixed> $run The pending exportRun.
	 */
	public function __construct(
		public readonly array $run,
	) {
	}//end __construct()

	/**
	 * The run UUID ('' when the run carries none).
	 *
	 * @return string
	 *
	 * @spec openspec/changes/archive/2026-06-14-bi-export-and-data-warehouse-sink/specs.md#REQ-BIE-004
	 */
	public function runId(): string {
		return (string)($this->run['id'] ?? $this->run['uuid'] ?? '');
	}//end runId()

	/**
	 * The job UUID ('' when the run carries none).
	 *
	 * @return string
	 *
	 * @spec openspec/changes/archive/2026-06-14-bi-export-and-data-warehouse-sink/specs.md#REQ-BIE-004
	 */
	public function jobId(): string {
		return (string)($this->run['jobId'] ?? '');
	}//end jobId()

	/**
	 * The watermark to extract from, or null for a full extract.
	 *
	 * @return string|null
	 *
	 * @spec openspec/changes/archive/2026-06-14-bi-export-and-data-warehouse-sink/specs.md#REQ-BIE-004
	 */
	public function watermarkFrom(): ?string {
		$watermarkFrom = (string)($this->run['watermarkFrom'] ?? '');
		if ($watermarkFrom !== '') {
			return $watermarkFrom;
		}

		return null;
	}//end watermarkFrom()
}//end class
