// SPDX-License-Identifier: EUPL-1.2
// Copyright (C) 2026 Conduction B.V.

import { translate as t } from '@nextcloud/l10n'

/**
 * The Note column of the newsletters list: one line that says what is
 * worth knowing about a newsletter, read from the row itself
 * (`"formatter": "blastNote"`). A newsletter still sending says how far it
 * is; one that left contacts out for missing consent says how many; an A/B
 * test says its split; a scheduled one passed the wizard's checks.
 *
 * @param {unknown} _value The cell value (unused: the note reads the row).
 * @param {object} row The blast.
 * @return {string} The note, or '' when there is nothing to say.
 * @spec openspec/specs/marketing-analytics/spec.md#requirement-overview-metrics-table
 */
export function blastNote(_value, row) {
	const status = row?.status || ''
	if (status === 'sending') {
		return sendingNote(row?.totals || {})
	}
	const sendNote = sendSettingsNote(row)
	if (sendNote !== null) {
		return sendNote
	}
	return status === 'scheduled' ? t('pipelinq', 'Rules checked') : ''
}

/**
 * How far a sending newsletter is: every count but the queue is done.
 *
 * @param {object} totals The blast's counts per state.
 * @return {string} The note.
 * @spec openspec/specs/marketing-analytics/spec.md#requirement-overview-metrics-table
 */
function sendingNote(totals) {
	const done = Object.entries(totals)
		.filter(([key]) => key !== 'queued')
		.reduce((sum, [, count]) => sum + (Number(count) || 0), 0)
	const all = done + (Number(totals.queued) || 0)
	return t('pipelinq', '{done} of {total} sent', { done: done.toLocaleString(), total: all.toLocaleString() })
}

/**
 * What the send left out or split: contacts without consent, or the A/B
 * test's share.
 *
 * @param {object|null} row The blast.
 * @return {string|null} The note, or null when there is none.
 * @spec openspec/specs/marketing-analytics/spec.md#requirement-overview-metrics-table
 */
function sendSettingsNote(row) {
	const noConsent = Number(row?.skippedNoConsent) || 0
	if (noConsent > 0) {
		return t('pipelinq', '{count} without consent, not sent', { count: noConsent })
	}
	if (Number(row?.abSplitPercent) > 0) {
		return t('pipelinq', 'A/B test, variant A {percent}%', { percent: Number(row.abSplitPercent) })
	}
	return null
}
