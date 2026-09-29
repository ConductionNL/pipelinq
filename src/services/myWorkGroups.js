// SPDX-License-Identifier: EUPL-1.2
// Copyright (C) 2026 Conduction B.V.

/**
 * The buckets of the My work page, in the order they are shown. What is due
 * today comes first, so on a phone the day's calls and follow-ups are at the
 * top without scrolling; then what is overdue, this week, later, undated.
 *
 * Imports nothing, so it runs in the node test environment.
 *
 * @spec openspec/changes/platform-phone-on-the-road/specs/mobile-experience/spec.md#requirement-my-work-works-as-a-phone-list-req-mob-002
 */

/** Group keys in display order. */
export const GROUP_ORDER = [
	'due-today',
	'overdue',
	'due-this-week',
	'upcoming',
	'no-due-date',
]

/**
 * The group an item lands in.
 *
 * @param {Date|null} due The item's due moment, or null.
 * @param {Date} today Start of today (local midnight).
 * @param {Date} weekEnd End of this week.
 * @param {boolean} isClosed A closed item never counts as due.
 * @return {string} One of GROUP_ORDER.
 * @spec openspec/changes/platform-phone-on-the-road/specs/mobile-experience/spec.md#requirement-my-work-works-as-a-phone-list-req-mob-002
 */
export function workGroup(due, today, weekEnd, isClosed) {
	if (!due || isClosed) return 'no-due-date'
	if (due < today) return 'overdue'
	const tomorrow = new Date(
		today.getFullYear(),
		today.getMonth(),
		today.getDate() + 1,
	)
	if (due < tomorrow) return 'due-today'
	if (due <= weekEnd) return 'due-this-week'
	return 'upcoming'
}
