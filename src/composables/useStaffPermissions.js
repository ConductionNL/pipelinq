/**
 * Staff permission composable for the Pipelinq POS terminal.
 *
 * Wraps the posSessionStore and exposes the four POS action gates as small
 * predicate functions. Every gate returns false when there is no active staff
 * session, so an unidentified terminal can perform no protected action. These
 * are convenience gates for UI affordances only — the server re-checks every
 * privileged operation; the client gate never grants an action on its own.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2024 Conduction B.V.
 *
 * @spec openspec/changes/pos-staff-pin-permissions/tasks.md#6
 */
import { usePosSessionStore } from '../store/modules/posSessionStore.js'

/**
 * Expose the POS staff permission gates for the active session.
 *
 * @return {object} The gate predicates.
 */
export function useStaffPermissions() {
	const session = usePosSessionStore()

	/**
	 * Whether the active staff member may void transactions.
	 *
	 * @return {boolean} True when permitted.
	 */
	function canVoid() {
		return session.hasActiveSession && session.permissions.canVoid === true
	}

	/**
	 * Whether the active staff member may apply a given discount percentage.
	 *
	 * @param {number} percent The requested discount percentage.
	 * @return {boolean} True when percent is within the role's ceiling.
	 */
	function canApplyDiscount(percent) {
		if (!session.hasActiveSession) {
			return false
		}
		const max = Number(session.permissions.maxDiscountPercent || 0)
		return Number(percent) <= max
	}

	/**
	 * Whether the active staff member may process refunds.
	 *
	 * @return {boolean} True when permitted.
	 */
	function canRefund() {
		return session.hasActiveSession && session.permissions.canRefund === true
	}

	/**
	 * Whether the active staff member may open the drawer without a sale.
	 *
	 * @return {boolean} True when permitted.
	 */
	function canNoSale() {
		return session.hasActiveSession && session.permissions.canNoSale === true
	}

	return { canVoid, canApplyDiscount, canRefund, canNoSale }
}
