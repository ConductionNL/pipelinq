// SPDX-License-Identifier: EUPL-1.2
// Copyright (C) 2026 Conduction B.V.
//
// Formatting helpers shared by the commercial dashboard widgets. Kept
// free of store/Vue imports so they stay unit-testable in isolation.

/**
 * Format a number as a euro amount. Null/undefined/NaN render as an em
 * dash so empty metrics read cleanly.
 *
 * @param {number|null|undefined} value - Amount in euros.
 * @param {number} maximumFractionDigits - Decimal places (default 0).
 * @return {string} Formatted currency string.
 * @spec openspec/specs/commercial-dashboard/spec.md
 */
export function formatEur(value, maximumFractionDigits = 0) {
	if (value === null || value === undefined || Number.isNaN(Number(value))) {
		return '—'
	}
	return new Intl.NumberFormat(undefined, {
		style: 'currency',
		currency: 'EUR',
		maximumFractionDigits,
	}).format(Number(value))
}

/**
 * Compact euro axis label (e.g. €1.2k, €3M) for dense chart axes.
 *
 * @param {number|null|undefined} value - Amount in euros.
 * @return {string} Compact currency string.
 * @spec openspec/specs/commercial-dashboard/spec.md
 */
export function formatEurCompact(value) {
	if (value === null || value === undefined || Number.isNaN(Number(value))) {
		return '—'
	}
	return new Intl.NumberFormat(undefined, {
		style: 'currency',
		currency: 'EUR',
		notation: 'compact',
		maximumFractionDigits: 1,
	}).format(Number(value))
}
