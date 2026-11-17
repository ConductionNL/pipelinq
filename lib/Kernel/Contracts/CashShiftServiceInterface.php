<?php

/**
 * Contract of CashShiftService
 *
 * The public surface of CashShiftService that other components call, so they depend
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
 * Contract of CashShiftService.
 *
 * @category Kernel
 * @package  OCA\Pipelinq\Kernel\Contracts
 * @author   Conduction b.v. <info@conduction.nl>
 * @license  EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link     https://github.com/ConductionNL/pipelinq
 *
 * @spec exclude Structural contract, no functional requirement.
 */
interface CashShiftServiceInterface {

	/**
	 * Approve a pending variance (POS manager only) and emit the CloudEvent.
	 *
	 * Stamps approvedBy / approvedAt, flips the diff to `approved`, marks the
	 * shift `reconciled` / reconciliationStatus `approved`, then emits the
	 * pipelinq.CashDiff.confirmed CloudEvent (fire-and-forget) for Shillinq.
	 *
	 * @param string $diffId The cashDiff UUID.
	 * @param string $userId The acting manager UID (from the session).
	 *
	 * @return array<string, mixed> The approved diff.
	 *
	 * @throws OCSForbiddenException If the user is not a POS manager.
	 * @throws OCSNotFoundException If the diff or its shift does not exist.
	 * @throws OCSBadRequestException If the diff is not pending.
	 *
	 * @spec openspec/specs/pos-cash-management/spec.md
	 */
	public function approveDiff(string $diffId, string $userId): array;

	/**
	 * Open a new cash shift with a declared opening float (POS operator).
	 *
	 * The operator UID and floatAt timestamp are server-set from the session and
	 * the current time; the client only supplies the drawer and the declared
	 * float (which must be non-negative). The new shift starts in status `open`.
	 *
	 * @param string $drawer The drawer / register identifier.
	 * @param float $floatAmount The declared opening float.
	 * @param string $userId The acting operator UID (from the session).
	 * @param string $reference Optional human-readable reference.
	 * @param string $notes Optional opening notes.
	 *
	 * @return array<string, mixed> The created shift.
	 *
	 * @throws OCSForbiddenException If the user is not a POS operator.
	 * @throws OCSBadRequestException If the float is negative.
	 *
	 * @spec openspec/specs/pos-cash-management/spec.md
	 */
	public function openShift(
		string $drawer,
		float $floatAmount,
		string $userId,
		string $reference = '',
		string $notes = '',
	): array;

	/**
	 * The id of a shift's pending cash difference, so an action on the shift
	 * can approve or reject it without naming the difference.
	 *
	 * @param string $shiftId The shift UUID.
	 *
	 * @return string The pending diff UUID.
	 *
	 * @throws OCSNotFoundException If the shift has no pending difference.
	 *
	 * @spec openspec/specs/pos-cash-management/spec.md
	 */
	public function pendingDiffId(string $shiftId): string;

	/**
	 * Record a blind close count, close the shift and compute the variance.
	 *
	 * Creates an append-only posCashCount, flips the shift to `closed` with a
	 * server-set closedAt, then derives and persists a pending cashDiff via
	 * {@see self::calculateDiff()}. The counted amount must be non-negative; the
	 * countedBy UID and countedAt timestamp are server-set.
	 *
	 * @param string $shiftId The shift UUID.
	 * @param float $amount The counted cash amount.
	 * @param string $userId The acting counter UID (from the session).
	 * @param string $notes Optional counter notes.
	 *
	 * @return array{count: array<string, mixed>, diff: array<string, mixed>, shift: array<string, mixed>} The result.
	 *
	 * @throws OCSForbiddenException If the user is not a POS operator.
	 * @throws OCSNotFoundException If the shift does not exist.
	 * @throws OCSBadRequestException If the shift is not open or the amount is negative.
	 *
	 * @spec openspec/specs/pos-cash-management/spec.md
	 */
	public function recordCount(string $shiftId, float $amount, string $userId, string $notes = ''): array;

	/**
	 * Record a mid-shift cash drop (POS operator).
	 *
	 * The drop amount must be positive; the droppedBy UID and droppedAt timestamp
	 * are server-set. The shift must exist and be open. The drop is append-only.
	 *
	 * @param string $shiftId The parent shift UUID.
	 * @param float $amount The drop amount.
	 * @param string $reason The reason code / description.
	 * @param string $userId The acting operator UID (from the session).
	 *
	 * @return array<string, mixed> The created drop.
	 *
	 * @throws OCSForbiddenException If the user is not a POS operator.
	 * @throws OCSNotFoundException If the shift does not exist.
	 * @throws OCSBadRequestException If the shift is not open or the amount is not positive.
	 *
	 * @spec openspec/specs/pos-cash-management/spec.md
	 */
	public function recordDrop(string $shiftId, float $amount, string $reason, string $userId): array;

	/**
	 * Reject a pending variance (POS manager only) and reopen the shift.
	 *
	 * Requires a non-empty reason. Stamps approvedBy / approvedAt /
	 * rejectionReason, flips the diff to `rejected`, reverts the shift to `open`
	 * (status only; the rejected count is retained for the recount history) and
	 * creates an opvolgtaak for the operator to recount.
	 *
	 * @param string $diffId The cashDiff UUID.
	 * @param string $reason The rejection reason (required).
	 * @param string $userId The acting manager UID (from the session).
	 *
	 * @return array<string, mixed> The rejected diff.
	 *
	 * @throws OCSForbiddenException If the user is not a POS manager.
	 * @throws OCSNotFoundException If the diff or its shift does not exist.
	 * @throws OCSBadRequestException If the diff is not pending or the reason is empty.
	 *
	 * @spec openspec/specs/pos-cash-management/spec.md
	 */
	public function rejectDiff(string $diffId, string $reason, string $userId): array;
}//end interface
