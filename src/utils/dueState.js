/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Where a due date stands against today: late (and by how many days), today,
 * later this week, or later. The work lists use it for their due pills.
 *
 * @module utils/dueState
 */

/**
 * Midnight (local time) of a date.
 *
 * @param {Date} date The date.
 * @return {Date}
 */
function startOfDay(date) {
	return new Date(date.getFullYear(), date.getMonth(), date.getDate())
}

/**
 * The state of a due date.
 *
 * @param {string|null|undefined} due The due date or date-time.
 * @param {Date} now Now.
 * @return {{state: ('overdue'|'today'|'week'|'later'|null), daysLate: number}} `state` is null without a due date.
 * @spec openspec/specs/dashboard/spec.md#requirement-my-work-widget
 */
export function dueState(due, now = new Date()) {
	const date = due ? new Date(due) : null
	if (!date || Number.isNaN(date.getTime())) {
		return { state: null, daysLate: 0 }
	}
	const today = startOfDay(now)
	const day = startOfDay(date)
	const days = Math.round((day - today) / 86400000)
	if (days < 0) {
		return { state: 'overdue', daysLate: -days }
	}
	if (days === 0) {
		return { state: 'today', daysLate: 0 }
	}
	// The rest of this week: up to and including Sunday.
	const daysToSunday = (7 - today.getDay()) % 7
	return { state: days <= daysToSunday ? 'week' : 'later', daysLate: 0 }
}
