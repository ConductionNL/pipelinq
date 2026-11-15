<?php

/**
 * Contract of CashShiftSummaryService
 *
 * The public surface of CashShiftSummaryService that other components call, so they depend
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
 * Contract of CashShiftSummaryService.
 *
 * @category Kernel
 * @package  OCA\Pipelinq\Kernel\Contracts
 * @author   Conduction b.v. <info@conduction.nl>
 * @license  EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link     https://github.com/ConductionNL/pipelinq
 *
 * @spec exclude Structural contract, no functional requirement.
 */
interface CashShiftSummaryServiceInterface {

	/**
	 * The summary of one shift for its page: the list summary plus the sales
	 * booked in the shift and the Z report of its till on its day.
	 *
	 * @param string $shiftId The shift UUID.
	 *
	 * @return array<string, mixed> The summary.
	 *
	 * @throws OCSNotFoundException If the shift does not exist.
	 *
	 * @spec openspec/specs/pos-cash-management/spec.md#requirement-a-shift-page-names-its-sales-and-z-report
	 */
	public function shiftSummary(string $shiftId): array;

	/**
	 * Read what the shifts list and the shift page show beside a shift: its
	 * drops, its newest cash difference and the outcome word the list draws
	 * as a pill. A shift that cannot be read is left out.
	 *
	 * @param array<int, string> $shiftIds The shift UUIDs.
	 *
	 * @return array<string, array<string, mixed>> The summary per shift UUID.
	 *
	 * @spec openspec/specs/pos-cash-management/spec.md#requirement-a-shift-summary-reads-its-drops-and-difference
	 */
	public function summarise(array $shiftIds): array;
}//end interface
