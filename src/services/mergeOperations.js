// SPDX-License-Identifier: EUPL-1.2
// SPDX-FileCopyrightText: 2026 Conduction B.V.
//
// The merges a record survived, and undoing one (clients-reversible-merge).
// OpenRegister keeps every merge as a `mergeOperation` object in its
// `merge-operation` register and reverses one through `merge#reverse`
// (POST /api/objects/merge/{id}/reverse). The reversal window is the
// merged schema's `x-openregister-merge.reversalWindowDays`.
//
// @spec openspec/changes/clients-reversible-merge

import axios from '@nextcloud/axios'
import { generateUrl } from '@nextcloud/router'

const DAY_MS = 24 * 60 * 60 * 1000

/**
 * The merges in which this record was the one that stayed, newest first.
 *
 * @param {string} recordId The surviving record's uuid.
 * @return {Promise<Array<object>>} The merge operations.
 * @spec openspec/changes/clients-reversible-merge
 */
export async function fetchMergesInto(recordId) {
	if (!recordId) {
		return []
	}
	const { data } = await axios.get(
		generateUrl('/apps/openregister/api/objects/merge-operation/mergeOperation'),
		{ params: { mergedIntoUuid: recordId, _limit: 100 } },
	)
	const rows = data?.results || data?.data || []
	return (Array.isArray(rows) ? rows : [])
		.filter((row) => row && row.mergedIntoUuid === recordId)
		.sort((a, b) => String(b.mergedAt || '').localeCompare(String(a.mergedAt || '')))
}

/**
 * Undo one merge.
 *
 * @param {string} operationId The merge operation's uuid.
 * @return {Promise<object>} The updated merge operation.
 * @spec openspec/changes/clients-reversible-merge
 */
export async function reverseMergeOperation(operationId) {
	const { data } = await axios.post(
		generateUrl('/apps/openregister/api/objects/merge/{id}/reverse', { id: operationId }),
	)
	return data && typeof data === 'object' ? data : {}
}

/**
 * The moment a merge becomes final: its merge time plus the window.
 *
 * @param {string} mergedAt The merge time (ISO).
 * @param {number} windowDays The reversal window in days.
 * @return {Date|null} The deadline, or null when the time cannot be read.
 * @spec openspec/changes/clients-reversible-merge
 */
export function mergeFinalAt(mergedAt, windowDays) {
	const merged = Date.parse(mergedAt || '')
	if (Number.isNaN(merged)) {
		return null
	}
	return new Date(merged + windowDays * DAY_MS)
}

/**
 * The state of a merge on a given moment.
 *
 * `reversed` when it was undone, `open` while it can still be undone,
 * `final` once the window has passed or OpenRegister no longer offers it.
 *
 * @param {object} operation The merge operation.
 * @param {number} windowDays The reversal window in days.
 * @param {Date} now The moment to judge.
 * @return {'open'|'final'|'reversed'} The state.
 * @spec openspec/changes/clients-reversible-merge
 */
export function mergeState(operation, windowDays, now) {
	if (operation.reversedAt) {
		return 'reversed'
	}
	const finalAt = mergeFinalAt(operation.mergedAt, windowDays)
	if (operation.reversible === false || finalAt === null || now.getTime() > finalAt.getTime()) {
		return 'final'
	}
	return 'open'
}

/**
 * The name of the record that was merged away, read from the snapshot
 * OpenRegister took before the merge.
 *
 * @param {object} operation The merge operation.
 * @return {string} Its name, or an empty string.
 * @spec openspec/changes/clients-reversible-merge
 */
export function mergedAwayName(operation) {
	const fromId = Array.isArray(operation.mergedFromUuids) ? operation.mergedFromUuids[0] : ''
	const record = operation.preMergeSnapshot?.objects?.[fromId]
	if (!record || typeof record !== 'object') {
		return ''
	}
	return String(record.name || record.title || '')
}
