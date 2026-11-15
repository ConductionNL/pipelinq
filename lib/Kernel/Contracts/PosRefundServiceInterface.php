<?php

/**
 * Contract of PosRefundService
 *
 * The public surface of PosRefundService that other components call, so they depend
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
use OCP\AppFramework\OCS\OCSForbiddenException;
use OCP\AppFramework\OCS\OCSNotFoundException;

/**
 * Contract of PosRefundService.
 *
 * @category Kernel
 * @package  OCA\Pipelinq\Kernel\Contracts
 * @author   Conduction b.v. <info@conduction.nl>
 * @license  EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link     https://github.com/ConductionNL/pipelinq
 *
 * @spec exclude Structural contract, no functional requirement.
 */
interface PosRefundServiceInterface {

	/**
	 * Confirm a pending refund (manager only).
	 *
	 * Validates manager permission (fail closed), that the refund is pending and
	 * non-empty, that the original transaction exists, recomputes the
	 * server-authoritative totals, enforces the cumulative over-refund cap
	 * against the original transaction, then sets status=completed + confirmedAt,
	 * persists, and emits the reversal + stock-movement CloudEvents
	 * (fire-and-forget).
	 *
	 * @param string $id The refund UUID.
	 * @param string $userId The acting user UID.
	 *
	 * @return array<string, mixed> The completed refund.
	 *
	 * @throws OCSNotFoundException If the refund or its original transaction does not exist.
	 * @throws OCSForbiddenException If the user lacks manager permission.
	 * @throws OCSBadRequestException If the status is invalid, the cart is empty, or the cap is exceeded.
	 *
	 * @spec openspec/changes/archive/2026-05-31-pos-refund-return/tasks.md#2.1
	 */
	public function confirmRefund(string $id, string $userId): array;

	/**
	 * Reject a pending refund (manager only).
	 *
	 * Validates that a non-empty rejection reason was supplied, stamps
	 * rejectedAt + rejectionReason, then applies the pending→rejected transition
	 * through OpenRegister's TransitionEngine. Manager authorization is enforced
	 * by PosRefundManagerGuard inside the engine (fail closed). Emits no events
	 * and changes no stock.
	 *
	 * @param string $id The refund UUID.
	 * @param string $reason The rejection reason (required).
	 * @param string $userId The acting user UID.
	 *
	 * @return array<string, mixed> The rejected refund.
	 *
	 * @throws OCSNotFoundException If the refund does not exist.
	 * @throws OCSForbiddenException If the user lacks manager permission.
	 * @throws OCSBadRequestException If the status is invalid or the reason is empty.
	 *
	 * @spec openspec/changes/archive/2026-05-31-pos-refund-return/tasks.md#2.1
	 * @spec openspec/changes/archive/2026-06-01-pos-lifecycle-guard-adoption/tasks.md#3.2
	 */
	public function rejectRefund(string $id, string $reason, string $userId): array;
}//end interface
