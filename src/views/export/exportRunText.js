/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 * SPDX-License-Identifier: EUPL-1.2
 *
 * The words of the BI export run columns (board PqBiExport): when a run
 * happened or happens next, and what the last run did.
 *
 * @module views/export/exportRunText
 */

import { translatePlural as n, translate as t } from '@nextcloud/l10n'

/**
 * The user's locale for dates and numbers.
 *
 * @return {string|undefined} A BCP 47 tag, or undefined for the browser's.
 */
function userLocale() {
	return typeof OC !== 'undefined' && OC.getLocale ? OC.getLocale().replace('_', '-') : undefined
}

/**
 * A moment as the board writes it: "7 okt, 02:04", with the year only outside
 * the current year. Text that is not a moment is returned as is, so an empty
 * cell's own words ("Not scheduled") pass through.
 *
 * @param {unknown} value An ISO moment.
 * @param {Date} [now] The reference moment.
 * @param {string} [locale] The locale.
 * @return {string} The moment, or the value as text.
 * @spec openspec/changes/bi-export-board/specs/bi-export-board/spec.md#requirement-a-job-shows-its-last-run-and-its-next-run
 */
export function exportMoment(value, now = new Date(), locale = userLocale()) {
	if (value === null || value === undefined || value === '') {
		return ''
	}
	const date = new Date(String(value))
	if (!/^\d{4}-\d{2}-\d{2}T/.test(String(value)) || Number.isNaN(date.getTime())) {
		return String(value)
	}
	const options = { day: 'numeric', month: 'short' }
	if (date.getFullYear() !== now.getFullYear()) {
		options.year = 'numeric'
	}
	const day = new Intl.DateTimeFormat(locale, options).format(date).replace(/\./g, '')
	const pad = (v) => String(v).padStart(2, '0')
	return `${day}, ${pad(date.getHours())}:${pad(date.getMinutes())}`
}

/**
 * The line under the last run's status: when, then the rows it wrote, the
 * files of a partial run, or why it failed. Empty for a job that never ran.
 *
 * @param {object} job The job row.
 * @param {Date} [now] The reference moment.
 * @param {string} [locale] The locale.
 * @return {string} The line.
 * @spec openspec/changes/bi-export-board/specs/bi-export-board/spec.md#requirement-a-job-shows-its-last-run-and-its-next-run
 */
export function lastRunLine(job, now = new Date(), locale = userLocale()) {
	const when = exportMoment(job?.lastRunAt, now, locale)
	if (when === '') {
		return ''
	}
	let detail
	if (job.lastRunStatus === 'failed') {
		detail = job.lastRunError || ''
	} else if (job.lastRunStatus === 'partial') {
		const files = Number(job.lastRunFiles) || 0
		const expected = Number(job.lastRunFilesExpected) || 0
		detail = expected > 0
			? t('pipelinq', '{files} of {expected} files', { files, expected })
			: n('pipelinq', '{count} file', '{count} files', files, { count: files })
	} else {
		const rows = Number(job.lastRunRows) || 0
		detail = n('pipelinq', '{count} row', '{count} rows', rows, { count: rows.toLocaleString(locale) })
	}
	return detail === '' ? when : t('pipelinq', '{when} · {detail}', { when, detail })
}

/** The @-shortcuts as their five fields. */
const SHORTCUTS = {
	'@hourly': '0 * * * *',
	'@daily': '0 0 * * *',
	'@weekly': '0 0 * * 0',
	'@monthly': '0 0 1 * *',
	'@yearly': '0 0 1 1 *',
}

/**
 * A schedule as people say it: "Every hour", "Every night at 02:00" (before
 * six in the morning), "Every day at 09:30", "Monday at 03:00", "On day 1 of
 * every month at 04:00". Any other expression is shown as written.
 *
 * @param {unknown} value A five-field cron expression or an @-shortcut.
 * @param {string} [locale] The locale, for the weekday.
 * @return {string} The schedule in words, or the value as written.
 * @spec openspec/changes/bi-export-board/specs/bi-export-board/spec.md#requirement-a-job-reads-its-schedule-in-words
 */
export function exportSchedule(value, locale = userLocale()) {
	const written = typeof value === 'string' ? value.trim() : ''
	if (written === '') {
		return ''
	}
	const fields = (SHORTCUTS[written.toLowerCase()] || written).split(/\s+/)
	if (fields.length !== 5) {
		return written
	}
	const [minute, hour, day, month, weekday] = fields
	const number = (field) => (/^\d+$/.test(field) ? Number(field) : null)
	if (minute === '0' && hour === '*' && day === '*' && month === '*' && weekday === '*') {
		return t('pipelinq', 'Every hour')
	}
	const m = number(minute)
	const h = number(hour)
	if (m === null || h === null || month !== '*') {
		return written
	}
	const time = `${String(h).padStart(2, '0')}:${String(m).padStart(2, '0')}`
	if (day === '*' && weekday === '*') {
		return h < 6 ? t('pipelinq', 'Every night at {time}', { time }) : t('pipelinq', 'Every day at {time}', { time })
	}
	const w = number(weekday)
	if (day === '*' && w !== null && w <= 7) {
		// 4 January 1970 was a Sunday, so day 4 + w is that weekday.
		const name = new Intl.DateTimeFormat(locale, { weekday: 'long', timeZone: 'UTC' }).format(new Date(Date.UTC(1970, 0, 4 + (w % 7))))
		return t('pipelinq', '{weekday} at {time}', { weekday: name.charAt(0).toLocaleUpperCase(locale) + name.slice(1), time })
	}
	const d = number(day)
	if (d !== null && weekday === '*') {
		return t('pipelinq', 'On day {day} of every month at {time}', { day: d, time })
	}
	return written
}
