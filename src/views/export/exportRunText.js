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
		detail = n('pipelinq', '{count} file', '{count} files', files, { count: files })
	} else {
		const rows = Number(job.lastRunRows) || 0
		detail = n('pipelinq', '{count} row', '{count} rows', rows, { count: rows.toLocaleString(locale) })
	}
	return detail === '' ? when : t('pipelinq', '{when} · {detail}', { when, detail })
}
