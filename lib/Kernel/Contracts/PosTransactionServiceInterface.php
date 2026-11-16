<?php

/**
 * Contract of PosTransactionService
 *
 * The public surface of PosTransactionService that other components call, so they depend
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
 * Contract of PosTransactionService.
 *
 * @category Kernel
 * @package  OCA\Pipelinq\Kernel\Contracts
 * @author   Conduction b.v. <info@conduction.nl>
 * @license  EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link     https://github.com/ConductionNL/pipelinq
 *
 * @spec exclude Structural contract, no functional requirement.
 */
interface PosTransactionServiceInterface {

	/**
	 * Confirm a draft or parked transaction.
	 *
	 * Recomputes server-authoritative totals, validates the cart is non-empty,
	 * sets status=confirmed + confirmedAt, persists, then emits the
	 * pipelinq.PosTransaction.confirmed CloudEvent (fire-and-forget).
	 *
	 * @param string $id The transaction UUID.
	 * @param string $userId The acting user UID.
	 *
	 * @return array<string, mixed> The confirmed transaction.
	 *
	 * @throws OCSNotFoundException If the transaction does not exist.
	 * @throws OCSBadRequestException If status is invalid or the cart is empty.
	 *
	 * @spec openspec/changes/archive/2026-05-31-pos-transaction-core/tasks.md#2.1
	 */
	public function confirmTransaction(string $id, string $userId): array;

	/**
	 * Park a draft transaction so it can be resumed later.
	 *
	 * @param string $id The transaction UUID.
	 * @param string $userId The acting user UID.
	 *
	 * @return array<string, mixed> The parked transaction.
	 *
	 * @throws OCSNotFoundException If the transaction does not exist.
	 * @throws OCSForbiddenException If the caller may not access the transaction.
	 * @throws OCSBadRequestException If the transaction is not a draft.
	 *
	 * @spec openspec/changes/archive/2026-05-31-pos-transaction-core/tasks.md#2.1
	 * @spec openspec/changes/archive/2026-06-01-pos-lifecycle-guard-adoption/tasks.md#3.1
	 */
	public function parkTransaction(string $id, string $userId): array;

	/**
	 * Refund / void a confirmed or settled transaction (manager only).
	 *
	 * @param string $id The transaction UUID.
	 * @param string $reason The refund reason (required).
	 * @param string $userId The acting user UID.
	 *
	 * @return array<string, mixed> The refunded transaction.
	 *
	 * @throws OCSNotFoundException If the transaction does not exist.
	 * @throws OCSForbiddenException If the user lacks manager permission.
	 * @throws OCSBadRequestException If the status is invalid or the reason is empty.
	 *
	 * @spec openspec/changes/archive/2026-05-31-pos-transaction-core/tasks.md#2.1
	 * @spec openspec/changes/archive/2026-06-01-pos-lifecycle-guard-adoption/tasks.md#3.1
	 */
	public function refundTransaction(string $id, string $reason, string $userId): array;

	/**
	 * Resume a parked transaction back to draft.
	 *
	 * @param string $id The transaction UUID.
	 * @param string $userId The acting user UID.
	 *
	 * @return array<string, mixed> The resumed transaction.
	 *
	 * @throws OCSNotFoundException If the transaction does not exist.
	 * @throws OCSForbiddenException If the caller may not access the transaction.
	 * @throws OCSBadRequestException If the transaction is not parked.
	 *
	 * @spec openspec/changes/archive/2026-05-31-pos-transaction-core/tasks.md#2.1
	 * @spec openspec/changes/archive/2026-06-01-pos-lifecycle-guard-adoption/tasks.md#3.1
	 */
	public function resumeTransaction(string $id, string $userId): array;

	/**
	 * Settle a confirmed transaction (payment received).
	 *
	 * @param string $id The transaction UUID.
	 * @param string $userId The acting user UID.
	 *
	 * @return array<string, mixed> The settled transaction.
	 *
	 * @throws OCSNotFoundException If the transaction does not exist.
	 * @throws OCSForbiddenException If the caller may not access the transaction.
	 * @throws OCSBadRequestException If the transaction is not confirmed.
	 *
	 * @spec openspec/changes/archive/2026-05-31-pos-transaction-core/tasks.md#2.1
	 * @spec openspec/changes/archive/2026-06-01-pos-lifecycle-guard-adoption/tasks.md#3.1
	 */
	public function settleTransaction(string $id, string $userId): array;

	/**
	 * Build the per-rate BTW compliance report for every transaction in this
	 * app's register (optionally narrowed to a status).
	 *
	 * The cross-object BTW report aggregates every fiscally-final transaction in
	 * the register, so it is restricted to POS managers / admins (a cashier may
	 * only see their own sale, not the whole ledger). The gate fails closed.
	 *
	 * @param string|null $status Optional status filter (confirmed/settled/refunded).
	 * @param string $userId The acting user UID (must be a POS manager / admin).
	 *
	 * @return array<string, mixed> The aggregated report.
	 *
	 * @throws OCSForbiddenException If the caller is not a POS manager / admin.
	 *
	 * @spec openspec/specs/pos-nl-btw-engine/spec.md
	 * @spec openspec/changes/archive/2026-06-01-pos-lifecycle-guard-adoption/tasks.md#4.3
	 */
	public function taxReport(?string $status = null, string $userId = ''): array;
}//end interface
