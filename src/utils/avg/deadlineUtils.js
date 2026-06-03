// SPDX-License-Identifier: EUPL-1.2
// SPDX-FileCopyrightText: 2026 Conduction B.V.
//
// Deadline helpers for the AVG (GDPR) request workflow. The legal deadline is
// computed server-side; these helpers only present remaining-time and urgency
// in the handler UI.

/**
 * Base statutory term in days (AVG art. 12 lid 3).
 *
 * @type {number}
 */
export const BASE_TERM_DAYS = 30

/**
 * Parse a value into a Date, or return null when invalid.
 *
 * @param {string|Date} value The deadline value.
 * @return {Date|null} The parsed date or null.
 */
function toDate(value) {
	if (value instanceof Date) {
		return Number.isNaN(value.getTime()) ? null : value
	}
	if (typeof value !== 'string' || value === '') {
		return null
	}
	const parsed = new Date(value)
	return Number.isNaN(parsed.getTime()) ? null : parsed
}

/**
 * Compute the base legal deadline (intake + 30 days, end of day).
 *
 * @param {string|Date} submittedAt The intake timestamp.
 * @return {Date|null} The legal deadline.
 */
export function calculateDeadline(submittedAt) {
	const base = toDate(submittedAt)
	if (base === null) {
		return null
	}
	const deadline = new Date(base.getTime())
	deadline.setDate(deadline.getDate() + BASE_TERM_DAYS)
	deadline.setHours(23, 59, 59, 0)
	return deadline
}

/**
 * Whole days remaining until a deadline (negative when breached).
 *
 * @param {string|Date} deadline The legal deadline.
 * @param {Date} [now] The reference time (defaults to now).
 * @return {number|null} The whole days remaining, or null when invalid.
 */
export function daysRemaining(deadline, now = new Date()) {
	const due = toDate(deadline)
	if (due === null) {
		return null
	}
	return Math.floor((due.getTime() - now.getTime()) / 86400000)
}

/**
 * Urgency colour for a deadline: red (<3 days or breached), yellow (3–7 days),
 * green (>7 days).
 *
 * @param {number|null} days The days remaining.
 * @return {string} One of 'red', 'yellow', 'green', 'grey'.
 */
export function getUrgencyColor(days) {
	if (days === null || days === undefined) {
		return 'grey'
	}
	if (days < 3) {
		return 'red'
	}
	if (days <= 7) {
		return 'yellow'
	}
	return 'green'
}

/**
 * Human-readable deadline string (locale-aware date + time).
 *
 * @param {string|Date} deadline The legal deadline.
 * @return {string} The formatted string, or '' when invalid.
 */
export function deadlineString(deadline) {
	const due = toDate(deadline)
	if (due === null) {
		return ''
	}
	return due.toLocaleString('nl-NL', {
		day: '2-digit', month: '2-digit', year: 'numeric', hour: '2-digit', minute: '2-digit',
	})
}
