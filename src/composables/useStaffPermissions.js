/**
 * useStaffPermissions — POS action-permission predicates.
 *
 * Thin wrapper around posSessionStore that exposes the four POS action
 * gates (void, discount, refund, no-sale) as easy-to-read helpers for
 * any POS view. Every check fails closed when no session is open — the
 * absence of a session is treated as zero permissions.
 *
 * @spec openspec/changes/pos-staff-pin-permissions/tasks.md#6.1
 */

import { usePosSessionStore } from '../store/modules/posSessionStore.js'

/**
 * Read POS action permissions for the currently logged-in staff member.
 *
 * @return {{
 *   canVoid: () => boolean,
 *   canApplyDiscount: (percent: number) => boolean,
 *   canRefund: () => boolean,
 *   canNoSale: () => boolean,
 *   maxDiscountPercent: () => number,
 *   isActive: () => boolean,
 * }} The permission predicates.
 */
export function useStaffPermissions() {
	const session = usePosSessionStore()

	const isActive = () => session.isSessionActive

	const canVoid = () => isActive() && !!session.permissions?.canVoid

	const canApplyDiscount = (percent) => {
		if (!isActive()) {
			return false
		}
		const max = Number(session.permissions?.maxDiscountPercent ?? 0)
		const requested = Number(percent ?? 0)
		if (Number.isNaN(requested) || requested < 0) {
			return false
		}
		return requested <= max
	}

	const canRefund = () => isActive() && !!session.permissions?.canRefund

	const canNoSale = () => isActive() && !!session.permissions?.canNoSale

	const maxDiscountPercent = () => {
		if (!isActive()) {
			return 0
		}
		return Number(session.permissions?.maxDiscountPercent ?? 0)
	}

	return {
		canVoid,
		canApplyDiscount,
		canRefund,
		canNoSale,
		maxDiscountPercent,
		isActive,
	}
}
