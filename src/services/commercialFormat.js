// SPDX-License-Identifier: EUPL-1.2
// Copyright (C) 2026 Conduction B.V.
//
// Formatting helpers shared by the commercial dashboard widgets. Kept
// free of store/Vue imports so they stay unit-testable in isolation.
// Amounts show in the reporting currency chosen in setup.

import { reportingCurrency } from './reportingCurrency.js'

/**
 * Format a number as an amount in the reporting currency. Null/undefined/NaN
 * render as an em dash so empty metrics read cleanly. The name predates the
 * currency setting and is kept so callers do not churn.
 *
 * @param {number|null|undefined} value - Amount in the reporting currency.
 * @param {number} maximumFractionDigits - Decimal places (default 0).
 * @param {string} [currency] - Currency code (defaults to the reporting currency).
 * @return {string} Formatted currency string.
 * @spec openspec/specs/commercial-dashboard/spec.md
 * @spec openspec/changes/review-finish/specs/commercial-dashboard/spec.md
 */
export function formatEur(value, maximumFractionDigits = 0, currency = reportingCurrency()) {
	if (value === null || value === undefined || Number.isNaN(Number(value))) {
		return '—'
	}
	return new Intl.NumberFormat(undefined, {
		style: 'currency',
		currency,
		maximumFractionDigits,
	}).format(Number(value))
}

/**
 * Compact axis label (e.g. €1.2k, $3M) for dense chart axes, in the
 * reporting currency.
 *
 * @param {number|null|undefined} value - Amount in the reporting currency.
 * @param {string} [currency] - Currency code (defaults to the reporting currency).
 * @return {string} Compact currency string.
 * @spec openspec/specs/commercial-dashboard/spec.md
 * @spec openspec/changes/review-finish/specs/commercial-dashboard/spec.md
 */
export function formatEurCompact(value, currency = reportingCurrency()) {
	if (value === null || value === undefined || Number.isNaN(Number(value))) {
		return '—'
	}
	return new Intl.NumberFormat(undefined, {
		style: 'currency',
		currency,
		notation: 'compact',
		maximumFractionDigits: 1,
	}).format(Number(value))
}
