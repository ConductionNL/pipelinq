// SPDX-License-Identifier: EUPL-1.2
// Copyright (C) 2026 Conduction B.V.
//
// Dashboard date range → analytics API period mapping. Kept free of
// store/router imports so it stays unit-testable in isolation.

/** The periods a preset can name. */
const PERIODS = ['week', 'month', 'quarter', 'year']

/** The longest span, in days, each period below a year covers. */
const SPAN_PERIODS = [
	[10, 'week'],
	[45, 'month'],
	[180, 'quarter'],
]

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
	if (!range) return 'month'
	if (PERIODS.includes(range.preset)) return range.preset
	const days = spanInDays(range)
	return days === null ? 'month' : periodForSpan(days)
}

/**
 * @param {{from: string, to: string}} range A custom range.
 * @return {number|null} Its length in days, or null when it cannot be read or ends before it starts.
 */
function spanInDays(range) {
	const from = Date.parse(range.from)
	const to = Date.parse(range.to)
	if (Number.isNaN(from) || Number.isNaN(to) || to <= from) return null
	return (to - from) / 86400000
}

/**
 * @param {number} spanDays A span in days.
 * @return {string} The shortest period that covers it.
 */
function periodForSpan(spanDays) {
	const match = SPAN_PERIODS.find(([longest]) => spanDays <= longest)
	return match ? match[1] : 'year'
}
