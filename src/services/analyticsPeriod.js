// SPDX-License-Identifier: EUPL-1.2
// Copyright (C) 2026 Conduction B.V.
//
// Dashboard date range → analytics API period mapping. Kept free of
// store/router imports so it stays unit-testable in isolation.

/**
 * Map the dashboard-level date range (`cnDashboardDateRange` value,
 * `{ from, to, preset }` or null) onto the analytics API `period`
 * selector. Preset ids win; a custom range falls back to the nearest
 * trailing window by day span; no range at all means the backend
 * default (month).
 *
 * @param {{ from: string, to: string, preset: string }|null} range - Injected range.
 * @return {string} week | month | quarter | year.
 * @spec openspec/specs/dashboard/spec.md
 */
export function rangeToPeriod(range) {
	const periods = ['week', 'month', 'quarter', 'year']
	if (!range) return 'month'
	if (periods.includes(range.preset)) return range.preset
	const from = Date.parse(range.from)
	const to = Date.parse(range.to)
	if (Number.isNaN(from) || Number.isNaN(to) || to <= from) return 'month'
	const spanDays = (to - from) / 86400000
	if (spanDays <= 10) return 'week'
	if (spanDays <= 45) return 'month'
	if (spanDays <= 180) return 'quarter'
	return 'year'
}
