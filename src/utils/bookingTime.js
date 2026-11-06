// SPDX-License-Identifier: EUPL-1.2
// Copyright (C) 2026 Conduction B.V.

/**
 * How an appointment's time reads on the PqAfspraken and PqAfspraak boards:
 * "08:45 tot 09:00" in the list, "Donderdag 8 oktober, 14:00 tot 15:00" on
 * the page and in the reschedule dialog.
 *
 * @spec openspec/changes/screens-appointments-board/specs/appointment-booking/spec.md
 */

import { translate as t } from '@nextcloud/l10n'

/**
 * Parse an ISO date-time.
 *
 * @param {unknown} value The date-time.
 * @return {Date|null} The date, or null when the value is not one.
 */
function toDate(value) {
	if (value === null || value === undefined || value === '') {
		return null
	}
	const date = value instanceof Date ? value : new Date(String(value))
	return Number.isNaN(date.getTime()) ? null : date
}

/**
 * The time of day as HH:MM, in the reader's time zone.
 *
 * @param {unknown} value The date-time.
 * @return {string} "08:45", or '' when the value is not a date.
 * @spec openspec/changes/screens-appointments-board/specs/appointment-booking/spec.md
 */
export function timeOfDay(value) {
	const date = toDate(value)
	if (date === null) {
		return ''
	}
	return String(date.getHours()).padStart(2, '0') + ':' + String(date.getMinutes()).padStart(2, '0')
}

/**
 * The start and end time of day ("08:45 tot 09:00"); the start alone when
 * there is no end.
 *
 * @param {unknown} start The start.
 * @param {unknown} end The end.
 * @return {string} The range, or '' without a start.
 * @spec openspec/changes/screens-appointments-board/specs/appointment-booking/spec.md
 */
export function timeRange(start, end) {
	const from = timeOfDay(start)
	if (from === '') {
		return ''
	}
	const to = timeOfDay(end)
	return to === '' ? from : t('pipelinq', '{start} to {end}', { start: from, end: to })
}

/**
 * The day and the time range ("Donderdag 8 oktober, 14:00 tot 15:00"), in
 * the reader's locale.
 *
 * @param {unknown} start The start.
 * @param {unknown} end The end.
 * @param {string} [locale] The locale; the browser's when omitted.
 * @return {string} The slot, or '' without a start.
 * @spec openspec/changes/screens-appointments-board/specs/appointment-booking/spec.md
 */
export function slotLabel(start, end, locale) {
	const date = toDate(start)
	if (date === null) {
		return ''
	}
	const day = new Intl.DateTimeFormat(locale, { weekday: 'long', day: 'numeric', month: 'long' }).format(date)
	return day.charAt(0).toUpperCase() + day.slice(1) + ', ' + timeRange(start, end)
}
