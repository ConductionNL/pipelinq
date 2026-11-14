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
 * @spec openspec/specs/bi-export-board/spec.md#requirement-a-job-shows-its-last-run-and-its-next-run
 */
export function exportMoment(value, now = new Date(), locale = userLocale()) {
	if (value === null || value === undefined || value === '') {
		return ''
	}
	const date = momentDate(value)
	if (date === null) {
		return String(value)
	}
	const options = { day: 'numeric', month: 'short' }
	if (date.getFullYear() !== now.getFullYear()) {
		options.year = 'numeric'
	}
	const day = new Intl.DateTimeFormat(locale, options).format(date).replace(/\./g, '')
	return `${day}, ${clockTime(date)}`
}

/**
 * @param {unknown} value A stored value.
 * @return {(Date|null)} The moment, when the value is a readable ISO moment; else null.
 */
function momentDate(value) {
	const date = new Date(String(value))
	if (!/^\d{4}-\d{2}-\d{2}T/.test(String(value)) || Number.isNaN(date.getTime())) {
		return null
	}
	return date
}

/**
 * @param {Date} date A moment.
 * @return {string} Its local time as HH:MM.
 */
function clockTime(date) {
	const pad = (v) => String(v).padStart(2, '0')
	return `${pad(date.getHours())}:${pad(date.getMinutes())}`
}

/**
 * @param {object} job The job row.
 * @return {string} Why the last run failed, or ''.
 */
function failedDetail(job) {
	return job.lastRunError || ''
}

/**
 * @param {object} job The job row.
 * @return {string} The files a partial run wrote, against the files expected when known.
 */
function partialDetail(job) {
	const files = Number(job.lastRunFiles) || 0
	const expected = Number(job.lastRunFilesExpected) || 0
	return expected > 0
		? t('pipelinq', '{files} of {expected} files', { files, expected })
		: n('pipelinq', '{count} file', '{count} files', files, { count: files })
}

/**
 * @param {object} job The job row.
 * @param {string} locale The locale, for the number.
 * @return {string} The rows the last run wrote.
 */
function rowsDetail(job, locale) {
	const rows = Number(job.lastRunRows) || 0
	return n('pipelinq', '{count} row', '{count} rows', rows, { count: rows.toLocaleString(locale) })
}

/**
 * The detail of a last run by its status; any other status reads its rows.
 */
const RUN_DETAILS = new Map([
	['failed', failedDetail],
	['partial', partialDetail],
])

/**
 * The line under the last run's status: when, then the rows it wrote, the
 * files of a partial run, or why it failed. Empty for a job that never ran.
 *
 * @param {object} job The job row.
 * @param {Date} [now] The reference moment.
 * @param {string} [locale] The locale.
 * @return {string} The line.
 * @spec openspec/specs/bi-export-board/spec.md#requirement-a-job-shows-its-last-run-and-its-next-run
 */
export function lastRunLine(job, now = new Date(), locale = userLocale()) {
	const when = exportMoment(job?.lastRunAt, now, locale)
	if (when === '') {
		return ''
	}
	const detail = (RUN_DETAILS.get(job.lastRunStatus) ?? rowsDetail)(job, locale)
	return detail === '' ? when : t('pipelinq', '{when} · {detail}', { when, detail })
}

/**
 * Why a job was switched off after failing runs in a row, for under its last
 * run. Empty while the job is on or was never switched off automatically.
 * The count comes from the job, so the line reads in the user's language; a
 * job without a usable count shows the reason as stored.
 *
 * @param {object} job The job row.
 * @return {string} The reason, or ''.
 * @spec openspec/changes/exports-switch-off-after-repeated-failure/specs/export-failure-switch-off/spec.md#requirement-the-bi-export-page-shows-a-switched-off-job-and-lets-an-administrator-switch-it-back-on-req-efs-003
 */
export function switchedOffLine(job) {
	const reason = typeof job?.pausedReason === 'string' ? job.pausedReason.trim() : ''
	if (reason === '' || job.enabled === true) {
		return ''
	}
	const count = Number(job.consecutiveFailures)
	if (!Number.isInteger(count) || count < 1) {
		return reason
	}
	return n('pipelinq', 'Switched off after {count} failed run in a row', 'Switched off after {count} failed runs in a row', count, { count })
}

/**
 * The whole line under a job's last run status: what the run did, then why
 * the job was switched off when it was.
 *
 * @param {object} job The job row.
 * @param {Date} [now] The reference moment.
 * @param {string} [locale] The locale.
 * @return {string} The line.
 * @spec openspec/changes/exports-switch-off-after-repeated-failure/specs/export-failure-switch-off/spec.md#requirement-the-bi-export-page-shows-a-switched-off-job-and-lets-an-administrator-switch-it-back-on-req-efs-003
 */
export function lastRunCell(job, now = new Date(), locale = userLocale()) {
	const run = lastRunLine(job, now, locale)
	const switchedOff = switchedOffLine(job)
	if (run === '' || switchedOff === '') {
		return run || switchedOff
	}
	return t('pipelinq', '{when} · {detail}', { when: run, detail: switchedOff })
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
 * @spec openspec/specs/bi-export-board/spec.md#requirement-a-job-reads-its-schedule-in-words
 */
export function exportSchedule(value, locale = userLocale()) {
	const written = writtenSchedule(value)
	if (written === '') {
		return ''
	}
	const fields = (SHORTCUTS[written.toLowerCase()] || written).split(/\s+/)
	if (fields.length !== 5) {
		return written
	}
	return scheduleWords(fields, locale) ?? written
}

/**
 * @param {unknown} value The schedule as stored.
 * @return {string} The schedule as written, trimmed; '' for anything that is not text.
 */
function writtenSchedule(value) {
	return typeof value === 'string' ? value.trim() : ''
}

/**
 * @param {string} field A cron field.
 * @return {(number|null)} The field as a number, or null when it is not a plain number.
 */
function cronNumber(field) {
	return /^\d+$/.test(field) ? Number(field) : null
}

/**
 * Five cron fields in words, when they have a shape people say.
 *
 * @param {Array<string>} fields Minute, hour, day, month and weekday.
 * @param {string} locale The locale, for the weekday.
 * @return {(string|null)} The schedule in words, or null.
 */
function scheduleWords(fields, locale) {
	if (fields.join(' ') === '0 * * * *') {
		return t('pipelinq', 'Every hour')
	}
	const [minute, hour, day, month, weekday] = fields
	const m = cronNumber(minute)
	const h = cronNumber(hour)
	if (m === null || h === null || month !== '*') {
		return null
	}
	const at = { hour: h, time: `${String(h).padStart(2, '0')}:${String(m).padStart(2, '0')}`, locale }
	return dailyWords({ day, weekday }, at) ?? weeklyWords({ day, weekday }, at) ?? monthlyWords({ day, weekday }, at)
}

/**
 * @param {{day: string, weekday: string}} date The day and weekday fields.
 * @param {{hour: number, time: string}} at The time of day.
 * @return {(string|null)} "Every night/day at …" for a schedule on every day, else null.
 */
function dailyWords(date, at) {
	if (date.day !== '*' || date.weekday !== '*') {
		return null
	}
	return at.hour < 6 ? t('pipelinq', 'Every night at {time}', { time: at.time }) : t('pipelinq', 'Every day at {time}', { time: at.time })
}

/**
 * @param {{day: string, weekday: string}} date The day and weekday fields.
 * @param {{time: string, locale: string}} at The time of day and the locale.
 * @return {(string|null)} "{weekday} at …" for a schedule on one weekday, else null.
 */
function weeklyWords(date, at) {
	const w = cronNumber(date.weekday)
	if (date.day !== '*' || w === null || w > 7) {
		return null
	}
	// 4 January 1970 was a Sunday, so day 4 + w is that weekday.
	const name = new Intl.DateTimeFormat(at.locale, { weekday: 'long', timeZone: 'UTC' }).format(new Date(Date.UTC(1970, 0, 4 + (w % 7))))
	return t('pipelinq', '{weekday} at {time}', { weekday: name.charAt(0).toLocaleUpperCase(at.locale) + name.slice(1), time: at.time })
}

/**
 * @param {{day: string, weekday: string}} date The day and weekday fields.
 * @param {{time: string}} at The time of day.
 * @return {(string|null)} "On day … of every month at …" for a schedule on one day of the month, else null.
 */
function monthlyWords(date, at) {
	const d = cronNumber(date.day)
	if (d === null || date.weekday !== '*') {
		return null
	}
	return t('pipelinq', 'On day {day} of every month at {time}', { day: d, time: at.time })
}
