// SPDX-License-Identifier: EUPL-1.2
// Copyright (C) 2024 Conduction B.V.

/**
 * POS total / tax calculation utilities.
 *
 * These mirror the server-authoritative formula in PHP
 * (`PosTransactionService::computeTotals` / `recalculateLine`). The frontend
 * uses them only for a real-time preview while editing a cart; the backend
 * always recomputes the persisted totals on confirm, so client-side figures are
 * never trusted.
 */

/**
 * Round a number to 2 decimals (cents).
 *
 * @param {number} value The value to round.
 * @return {number} The rounded value.
 */
function money(value) {
	return Math.round((Number(value) + Number.EPSILON) * 100) / 100
}

/**
 * Compute taxAmount and lineTotal for a single line.
 *
 * @param {object} line The raw line.
 * @return {object} The line with computed quantity, unitPrice, discount,
 *   taxRate, taxAmount and lineTotal.
 */
export function recalculateLine(line) {
	const quantity = Math.max(0, Number(line.quantity) || 0)
	const unitPrice = Math.max(0, Number(line.unitPrice) || 0)
	const discount = Math.min(100, Math.max(0, Number(line.discount) || 0))
	const taxRate = Math.min(100, Math.max(0, line.taxRate === undefined || line.taxRate === null ? 21 : Number(line.taxRate)))

	const net = quantity * unitPrice * (1 - discount / 100)
	const taxAmount = net * (taxRate / 100)

	return {
		...line,
		quantity,
		unitPrice,
		discount,
		taxRate,
		taxAmount: money(taxAmount),
		lineTotal: money(net + taxAmount),
	}
}

/**
 * Compute aggregate totals for a set of lines.
 *
 * @param {Array<object>} lines The line items.
 * @return {{subtotal: number, discountTotal: number, taxBreakdown: Array<object>, totalTax: number, total: number}} The totals.
 */
export function computeTotals(lines) {
	let subtotal = 0
	let discountTotal = 0
	let totalTax = 0
	const byRate = {}

	for (const raw of lines || []) {
		const line = recalculateLine(raw)
		const gross = line.quantity * line.unitPrice
		const net = gross * (1 - line.discount / 100)

		subtotal += net
		discountTotal += gross - net
		totalTax += line.taxAmount

		const key = String(line.taxRate)
		if (!byRate[key]) {
			byRate[key] = { rate: line.taxRate, base: 0, tax: 0 }
		}
		byRate[key].base += net
		byRate[key].tax += line.taxAmount
	}

	const taxBreakdown = Object.values(byRate)
		.sort((a, b) => a.rate - b.rate)
		.map(entry => ({ rate: entry.rate, base: money(entry.base), tax: money(entry.tax) }))

	subtotal = money(subtotal)
	totalTax = money(totalTax)

	return {
		subtotal,
		discountTotal: money(discountTotal),
		taxBreakdown,
		totalTax,
		total: money(subtotal + totalTax),
	}
}

/**
 * Format a number as a Dutch-locale EUR amount, e.g. "€ 1.234,56".
 *
 * @param {number} value The amount.
 * @return {string} The formatted amount.
 */
export function formatEur(value) {
	return new Intl.NumberFormat('nl-NL', {
		style: 'currency',
		currency: 'EUR',
		minimumFractionDigits: 2,
		maximumFractionDigits: 2,
	}).format(Number(value) || 0)
}
