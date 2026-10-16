/**
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * How a sale's amount due is split over payment parts at checkout
 * (PqKassaAfrekenen): loyalty points first, then gift cards in the order
 * they were added, then the rest on the till's own payment method. Amounts
 * are euros, rounded to cents.
 *
 * @spec openspec/changes/screens-kassa-till-board/specs/pos-split-tender/spec.md
 */

/**
 * Round to cents.
 *
 * @param {number} value The amount.
 * @return {number} The amount in whole cents.
 */
function cents(value) {
	return Math.round((Number(value) || 0) * 100) / 100
}

/**
 * The euro value of a number of loyalty points.
 *
 * @param {number} points The points to use.
 * @param {number} pointsPerEuro How many points make one euro.
 * @return {number} The value, rounded down to cents.
 * @spec openspec/changes/screens-kassa-till-board/specs/pos-split-tender/spec.md
 */
export function loyaltyValue(points, pointsPerEuro) {
	const rate = Number(pointsPerEuro)
	const used = Math.max(0, Math.floor(Number(points) || 0))
	if (!Number.isFinite(rate) || rate <= 0) {
		return 0
	}
	return Math.floor((used / rate) * 100) / 100
}

/**
 * The most points that can be used: no more than the balance, and no more
 * than the amount due is worth.
 *
 * @param {number} balance The points on the account.
 * @param {number} pointsPerEuro How many points make one euro.
 * @param {number} due The amount due.
 * @return {number} The points.
 * @spec openspec/changes/screens-kassa-till-board/specs/pos-split-tender/spec.md
 */
export function maxLoyaltyPoints(balance, pointsPerEuro, due) {
	const rate = Number(pointsPerEuro)
	if (!Number.isFinite(rate) || rate <= 0) {
		return 0
	}
	const forDue = Math.floor(Math.max(0, Number(due) || 0) * rate)
	return Math.max(0, Math.min(Math.floor(Number(balance) || 0), forDue))
}

/**
 * Split an amount due over loyalty points, gift cards and the rest.
 *
 * @param {number} due The amount due.
 * @param {object} [options] The parts.
 * @param {{points: number, pointsPerEuro: number}|null} [options.loyalty] Points to use.
 * @param {Array<{serial: string, balance: number}>} [options.giftCards] Gift cards, in order.
 * @return {{parts: Array<object>, rest: number, covered: number, due: number}} The split:
 *   each part with its kind and amount (a gift card also with what is left on it), the
 *   rest for the till's payment method, and how much the parts before it cover.
 * @spec openspec/changes/screens-kassa-till-board/specs/pos-split-tender/spec.md
 */
export function splitPayment(due, { loyalty = null, giftCards = [] } = {}) {
	const total = cents(Math.max(0, Number(due) || 0))
	let open = total
	const parts = []
	if (loyalty && loyalty.points > 0) {
		const amount = Math.min(open, loyaltyValue(loyalty.points, loyalty.pointsPerEuro))
		if (amount > 0) {
			parts.push({ kind: 'loyalty', points: Math.floor(loyalty.points), amount: cents(amount) })
			open = cents(open - amount)
		}
	}
	for (const card of giftCards || []) {
		const amount = cents(Math.min(open, Math.max(0, Number(card.balance) || 0)))
		if (amount <= 0) {
			continue
		}
		parts.push({
			kind: 'giftCard',
			serial: card.serial,
			giftCardId: card.giftCardId,
			amount,
			balanceAfter: cents((Number(card.balance) || 0) - amount),
		})
		open = cents(open - amount)
	}
	return { parts, rest: open, covered: cents(total - open), due: total }
}

/**
 * The id of the active tender type with a code.
 *
 * @param {Array<object>} types The tender types.
 * @param {string} code The code ("CARD", "CASH", "VOUCHER").
 * @return {string} The id, or '' when no active type has that code.
 * @spec openspec/changes/screens-kassa-till-board/specs/pos-split-tender/spec.md
 */
export function tenderTypeId(types, code) {
	const wanted = String(code || '').toUpperCase()
	const type = (types || []).find((t) => String(t.code || '').toUpperCase() === wanted && t.isActive !== false)
	return type ? String(type.id || type['@self']?.id || '') : ''
}
