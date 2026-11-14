<?php

/**
 * Contract of PosStaffReportService
 *
 * The public surface of PosStaffReportService that other components call, so they depend
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
 * Contract of PosStaffReportService.
 *
 * @category Kernel
 * @package  OCA\Pipelinq\Kernel\Contracts
 * @author   Conduction b.v. <info@conduction.nl>
 * @license  EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link     https://github.com/ConductionNL/pipelinq
 *
 * @spec exclude Structural contract, no functional requirement.
 */
interface PosStaffReportServiceInterface {

	/**
	 * Build the per-staff sales aggregation report.
	 *
	 * @return array<int, array{staffMemberId: string, displayName: string, transactionCount: int, total: float, totalTax: float}> Per-staff rows.
	 *
	 * @spec openspec/changes/archive/2026-06-14-pos-staff-pin-permissions/tasks.md#10.2
	 */
	public function staffSalesReport(): array;
}//end interface
