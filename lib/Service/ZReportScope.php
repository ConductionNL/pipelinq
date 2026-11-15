<?php

/**
 * Pipelinq ZReportScope.
 *
 * The day and terminal one Z-report covers.
 *
 * @category Service
 * @package  OCA\Pipelinq\Service
 *
 * @author    Conduction <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git_id>
 *
 * @link https://github.com/ConductionNL/pipelinq
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @spec openspec/specs/pipelinq-bookkeeping-to-shillinq/spec.md#REQ-PBTS-001
 */

declare(strict_types=1);

namespace OCA\Pipelinq\Service;

/**
 * The report date and optional terminal filter of a Z-report.
 *
 * @spec openspec/specs/pipelinq-bookkeeping-to-shillinq/spec.md#REQ-PBTS-001
 */
final class ZReportScope {
	/**
	 * Constructor.
	 *
	 * @param string      $reportDate The report date (YYYY-MM-DD).
	 * @param string|null $terminalId The terminal filter, or null for all terminals.
	 */
	public function __construct(
		public readonly string $reportDate,
		public readonly ?string $terminalId,
	) {
	}//end __construct()

	/**
	 * Whether the report is limited to one terminal.
	 *
	 * @return bool True when a non-empty terminal filter is set.
	 *
	 * @spec openspec/specs/pipelinq-bookkeeping-to-shillinq/spec.md#REQ-PBTS-001
	 */
	public function hasTerminal(): bool {
		return $this->terminalId !== null && $this->terminalId !== '';
	}//end hasTerminal()
}//end class
