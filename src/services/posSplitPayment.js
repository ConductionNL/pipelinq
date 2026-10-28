/**
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * How a sale's payment splits at the cash register: loyalty points first,
 * then gift cards, and the rest at the chosen payment method.
 *
 * @spec openspec/changes/pos-sale-board-look/specs/pos-transactions/spec.md#requirement-a-payment-can-be-split
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
 * What a number of points is worth.
 *
 * @param {number} points The points.
 * @param {number} pointValue What one point is worth.
 * @return {number} The amount.
 */
export function pointsAmount(points, pointValue) {
	return cents(Math.max(0, Math.floor(Number(points) || 0)) * (Number(pointValue) || 0))
}

/**
 * The most points that can be spent: the balance, but no more than pays
 * what is still due.
 *
 * @param {number} balance The points on the account.
 * @param {number} pointValue What one point is worth.
 * @param {number} due What is still to pay.
 * @return {number} The points.
 */
export function maxPoints(balance, pointValue, due) {
	const value = Number(pointValue) || 0
	if (value <= 0) {
		return 0
	}
	return Math.max(0, Math.min(Math.floor(Number(balance) || 0), Math.floor(cents(due) / value + 1e-9)))
}

/**
 * Split a total over points, gift cards and the rest. A gift card pays no
 * more than is still due after the parts before it.
 *
 * @param {number} total What the sale costs, incl. VAT.
 * @param {{pointsValue?: number, giftCards?: Array<{amount: number}>}} split The points' value and the gift cards.
 * @return {{giftCards: Array<object>, rest: number, parts: Array<number>, closes: boolean}} The split.
 */
export function splitPayment(total, { pointsValue = 0, giftCards = [] } = {}) {
	let due = cents(total)
	const points = Math.min(cents(pointsValue), due)
	due = cents(due - points)
	const cards = giftCards.map((card) => {
		const amount = Math.min(cents(card.amount), due)
		due = cents(due - amount)
		return { ...card, amount }
	})
	const rest = due
	const parts = [points, ...cards.map((card) => card.amount), rest].filter((amount) => amount > 0)
	return {
		giftCards: cards,
		rest,
		parts,
		closes: cents(parts.reduce((sum, amount) => sum + amount, 0)) === cents(total),
	}
}

const TENDER_CODES = {
	cash: ['CASH', 'CONTANT'],
	card: ['CARD', 'PIN'],
	giftCard: ['VOUCHER', 'BON', 'GIFTCARD', 'CADEAUKAART'],
	points: ['LOYALTY', 'PUNTEN', 'SPAARPUNTEN'],
	onAccount: ['ACCOUNT', 'REKENING', 'OPREKENING'],
}

/**
 * The active tender type a payment part is booked under, found by its code.
 *
 * @param {string} kind The part: cash, card, giftCard, points or onAccount.
 * @param {Array<object>} types The tender types.
 * @return {object|null} The tender type, or null when none is set up.
 */
export function tenderTypeFor(kind, types) {
	const codes = TENDER_CODES[kind] || []
	return (types || []).find((type) => codes.includes(String(type.code || '').toUpperCase()) && type.isActive !== false) || null
}
