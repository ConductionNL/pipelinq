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
	const date = readableDate(due)
	if (date === null) {
		return { state: null, daysLate: 0 }
	}
	const today = startOfDay(now)
	const day = startOfDay(date)
	const days = Math.round((day - today) / 86400000)
	if (days < 0) {
		return { state: 'overdue', daysLate: -days }
	}
	return { state: upcomingState(days, today), daysLate: 0 }
}

/**
 * @param {string|Date|null} due The due date.
 * @return {Date|null} It as a date, or null when there is none or it cannot be read.
 * @spec openspec/specs/dashboard/spec.md#requirement-my-work-widget
 */
function readableDate(due) {
	const date = due ? new Date(due) : null
	return date && !Number.isNaN(date.getTime()) ? date : null
}

/**
 * Whether a day still to come is today, this week or later.
 *
 * @param {number} days Whole days from today, 0 or more.
 * @param {Date} today The start of today.
 * @return {'today'|'week'|'later'} The state.
 * @spec openspec/specs/dashboard/spec.md#requirement-my-work-widget
 */
function upcomingState(days, today) {
	if (days === 0) {
		return 'today'
	}
	// The rest of this week: up to and including Sunday.
	const daysToSunday = (7 - today.getDay()) % 7
	return days <= daysToSunday ? 'week' : 'later'
}
