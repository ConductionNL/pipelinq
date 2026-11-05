// SPDX-License-Identifier: EUPL-1.2
// Copyright (C) 2024 Conduction B.V.

/**
 * POS display utilities: amounts in the setup currency and the user's locale,
 * the translated VAT rate names, and the refund preview.
 *
 * A sale's line totals, VAT per rate and totals are never worked out here:
 * the server prices each stored line and the open sale
 * (till-server-priced-lines), and the till shows its figures.
 */

import { translate as t } from '@nextcloud/l10n'
import { getUserLocale } from './localeUtils.js'
import { reportingCurrency } from './reportingCurrency.js'

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
 * The translated name of a VAT rate, for the screen.
 *
 * English reads "Standard rate (21%)", Dutch keeps "Standaardtarief (21%)".
 * Rates without a name read "6% VAT".
 *
 * @param {number} rate The VAT rate percentage.
 * @return {string} The translated rate name.
 * @spec openspec/changes/pipelinq-audit-admin-forms-pos/specs/pos-display/spec.md#requirement-pos-amounts-and-labels-follow-the-user
 */
export function rateLabel(rate) {
	const intRate = Math.round(Number(rate) || 0)
	if (intRate === 0) {
		return t('pipelinq', 'Zero rate (0%)')
	}
	if (intRate === 9) {
		return t('pipelinq', 'Reduced rate (9%)')
	}
	if (intRate === 21) {
		return t('pipelinq', 'Standard rate (21%)')
	}
	return t('pipelinq', '{rate}% VAT', { rate: String(rate) })
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
 * Format an amount in the setup currency and the user's locale.
 *
 * The name predates the currency setting and is kept so callers do not churn.
 *
 * @param {number} value The amount.
 * @param {string} [currency] Currency code (defaults to the setup currency).
 * @param {string} [locale] BCP 47 locale (defaults to the user's locale).
 * @return {string} The formatted amount.
 * @spec openspec/changes/pipelinq-audit-admin-forms-pos/specs/pos-display/spec.md#requirement-pos-amounts-and-labels-follow-the-user
 */
export function formatEur(
	value,
	currency = reportingCurrency(),
	locale = getUserLocale(),
) {
	return new Intl.NumberFormat(locale, {
		style: 'currency',
		currency,
		minimumFractionDigits: 2,
		maximumFractionDigits: 2,
	}).format(Number(value) || 0)
}
