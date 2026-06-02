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

const PRICE_MODES = ['excl', 'incl']

/**
 * Dutch GL descriptions per common BTW rate, mirroring the PHP
 * PosTransactionService::RATE_DESCRIPTIONS map.
 */
const RATE_DESCRIPTIONS = {
	0: 'Nultarief (0%)',
	9: 'Verlaagd tarief (9%)',
	21: 'Standaardtarief (21%)',
}

/**
 * Normalise a price mode to a known value ('excl' default).
 *
 * @param {*} mode The raw price mode.
 * @return {string} Either 'excl' or 'incl'.
 */
export function normalizePriceMode(mode) {
	const value = typeof mode === 'string' ? mode.trim().toLowerCase() : ''
	return PRICE_MODES.includes(value) ? value : 'excl'
}

/**
 * Human-readable Dutch GL description for a BTW rate.
 *
 * @param {number} rate The BTW rate percentage.
 * @return {string} The description.
 */
export function rateDescription(rate) {
	const intRate = Math.round(Number(rate) || 0)
	if (RATE_DESCRIPTIONS[intRate] !== undefined) {
		return RATE_DESCRIPTIONS[intRate]
	}
	return `${rate}% BTW`
}

/**
 * Compute net, taxAmount and lineTotal for a single line.
 *
 * In 'excl' mode the entered unitPrice is the net base and BTW is added on top.
 * In 'incl' mode the entered unitPrice already contains BTW and the net base is
 * extracted out of it. The persisted `net` is always the tax-exclusive base, so
 * the per-rate breakdown is identical regardless of entry mode. Mirrors
 * PosTransactionService::recalculateLine().
 *
 * @param {object} line The raw line.
 * @param {string|null} priceMode The transaction price mode ('excl'|'incl').
 * @return {object} The line with computed quantity, unitPrice, discount,
 *   taxRate, net, taxAmount and lineTotal.
 */
export function recalculateLine(line, priceMode = null) {
	const quantity = Math.max(0, Number(line.quantity) || 0)
	const unitPrice = Math.max(0, Number(line.unitPrice) || 0)
	const discount = Math.min(100, Math.max(0, Number(line.discount) || 0))
	const taxRate = Math.min(100, Math.max(0, line.taxRate === undefined || line.taxRate === null ? 21 : Number(line.taxRate)))
	const mode = normalizePriceMode(priceMode ?? line.priceMode ?? null)

	const amount = quantity * unitPrice * (1 - discount / 100)
	let net
	let taxAmount
	if (mode === 'incl') {
		net = amount / (1 + taxRate / 100)
		taxAmount = amount - net
	} else {
		net = amount
		taxAmount = net * (taxRate / 100)
	}

	return {
		...line,
		quantity,
		unitPrice,
		discount,
		taxRate,
		net: money(net),
		taxAmount: money(taxAmount),
		lineTotal: money(net + taxAmount),
	}
}

/**
 * Compute aggregate totals for a set of lines.
 *
 * Mirrors PosTransactionService::computeTotals(): groups tax by rate into a
 * taxBreakdown array and a GL-oriented invoiceBreakdown array (with Dutch
 * descriptions). The optional priceMode is threaded into recalculateLine so the
 * per-line net base is extracted correctly whether prices were entered incl. or
 * excl. BTW. Used only for a real-time preview; the backend recomputes the
 * persisted figures, so client-side values are never trusted.
 *
 * @param {Array<object>} lines The line items.
 * @param {string|null} priceMode The transaction price mode ('excl'|'incl').
 * @return {{priceMode: string, subtotal: number, discountTotal: number, taxBreakdown: Array<object>, invoiceBreakdown: Array<object>, totalTax: number, total: number}} The totals.
 */
export function computeTotals(lines, priceMode = null) {
	const mode = normalizePriceMode(priceMode)
	let subtotal = 0
	let discountTotal = 0
	let totalTax = 0
	const byRate = {}

	for (const raw of lines || []) {
		const line = recalculateLine(raw, mode)
		const grossNoDisc = line.quantity * line.unitPrice
		const netNoDisc = mode === 'incl' ? grossNoDisc / (1 + line.taxRate / 100) : grossNoDisc

		subtotal += line.net
		discountTotal += netNoDisc - line.net
		totalTax += line.taxAmount

		const key = String(line.taxRate)
		if (!byRate[key]) {
			byRate[key] = { rate: line.taxRate, base: 0, tax: 0 }
		}
		byRate[key].base += line.net
		byRate[key].tax += line.taxAmount
	}

	const sorted = Object.values(byRate).sort((a, b) => a.rate - b.rate)
	const taxBreakdown = sorted.map(entry => ({ rate: entry.rate, base: money(entry.base), tax: money(entry.tax) }))
	const invoiceBreakdown = sorted.map(entry => ({
		rate: entry.rate,
		base: money(entry.base),
		tax: money(entry.tax),
		description: rateDescription(entry.rate),
	}))

	subtotal = money(subtotal)
	totalTax = money(totalTax)

	return {
		priceMode: mode,
		subtotal,
		discountTotal: money(discountTotal),
		taxBreakdown,
		invoiceBreakdown,
		totalTax,
		total: money(subtotal + totalTax),
	}
}

/**
 * Compute the proportional refund taxAmount and lineTotal for a single returned
 * line. Mirrors PosRefundService::recalculateLine(): the proportion of the
 * original line's persisted tax and total, clamped to [0, 1] so an over-quantity
 * return can never inflate the refund. Used for the real-time refund preview;
 * the backend recomputes the persisted figures on confirm.
 *
 * @param {object} originalLine The original posTransactionLine (with persisted
 *   quantity, taxAmount and lineTotal).
 * @param {number} returnedQty The quantity being returned.
 * @return {{ratio: number, taxAmount: number, lineTotal: number}} The amounts.
 */
export function refundLineAmounts(originalLine, returnedQty) {
	const originalQty = Number(originalLine?.quantity) || 0
	const returned = Math.max(0, Number(returnedQty) || 0)
	const ratio = originalQty > 0 ? Math.min(1, returned / originalQty) : 0
	const origTax = Number(originalLine?.taxAmount) || 0
	const origTotal = Number(originalLine?.lineTotal) || 0

	return {
		ratio,
		taxAmount: money(origTax * ratio),
		lineTotal: money(origTotal * ratio),
	}
}

/**
 * Aggregate a set of refund lines into refundAmount (excl. tax), totalTax and
 * grand total. Each line carries its own computed taxAmount and lineTotal (incl.
 * tax). Mirrors PosRefundService::recalculateTotals().
 *
 * @param {Array<object>} lines The refund lines with computed taxAmount /
 *   lineTotal fields.
 * @return {{refundAmount: number, totalTax: number, total: number}} The totals.
 */
export function computeRefundTotals(lines) {
	let totalTax = 0
	let total = 0
	for (const line of lines || []) {
		totalTax += Number(line.taxAmount) || 0
		total += Number(line.lineTotal) || 0
	}
	totalTax = money(totalTax)
	total = money(total)

	return {
		refundAmount: money(total - totalTax),
		totalTax,
		total,
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
