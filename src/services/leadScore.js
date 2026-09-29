// SPDX-License-Identifier: EUPL-1.2
// Copyright (C) 2026 Conduction B.V.

/**
 * Lead score helpers: the band a stored `qualificationScore` falls in, and
 * the criteria that explain it.
 *
 * The score itself is computed by OpenRegister on every save, from the
 * `x-openregister-calculations.qualificationScore` expression on the `lead`
 * schema (lib/Settings/pipelinq_register.json). This module never replaces
 * that number. It mirrors the eight criteria so a person can see which ones
 * added points; tests/vitest/leadScore.spec.js evaluates the real expression
 * from the register file and fails when the two drift apart.
 *
 * Imports nothing, so it runs in the node test environment. Labels are
 * translated by the component that shows them.
 *
 * @spec openspec/changes/pipeline-lead-score-call-first/specs/lead-management/spec.md#requirement-a-person-can-see-why-a-lead-has-its-score-req-lscore-003
 */

/** Lowest score in the high band. */
export const HIGH_FROM = 70

/** Lowest score in the medium band. */
export const MEDIUM_FROM = 40

/**
 * True when a value counts as set, the way the calculation's `ne null`
 * reads it: an absent property resolves to null.
 *
 * @param {*} value The property value.
 * @return {boolean}
 */
function isSet(value) {
	return value !== null && value !== undefined
}

/**
 * The eight criteria of the qualification score, in the order the
 * calculation lists them. `label` is the untranslated English string.
 *
 * @type {Array<{id: string, label: string, points: number, test: function(object): boolean}>}
 */
export const SCORE_CRITERIA = [
	{ id: 'value', label: 'Value present', points: 10, test: (l) => Number(l.value) > 0 },
	{ id: 'largeValue', label: 'Value above 10,000', points: 20, test: (l) => Number(l.value) > 10000 },
	{ id: 'client', label: 'Client linked', points: 15, test: (l) => isSet(l.client) },
	{ id: 'contact', label: 'Contact linked', points: 10, test: (l) => isSet(l.contact) },
	{ id: 'source', label: 'Came in through a referral or partner', points: 15, test: (l) => l.source === 'referral' || l.source === 'partner' },
	{ id: 'closeDate', label: 'Expected close date set', points: 10, test: (l) => isSet(l.expectedCloseDate) },
	{ id: 'priority', label: 'Priority high or urgent', points: 10, test: (l) => l.priority === 'high' || l.priority === 'urgent' },
	{ id: 'description', label: 'Description written', points: 5, test: (l) => isSet(l.description) },
]

/**
 * Read a stored score as a whole number, or null when the lead has none.
 *
 * @param {*} value The raw `qualificationScore`.
 * @return {number|null}
 * @spec openspec/changes/pipeline-lead-score-call-first/specs/lead-management/spec.md#requirement-the-lead-list-shows-and-sorts-by-score-req-lscore-001
 */
export function normaliseScore(value) {
	if (value === null || value === undefined || value === '') {
		return null
	}
	const n = Number(value)
	return Number.isFinite(n) ? Math.round(n) : null
}

/**
 * The band a score falls in: `high` from 70, `medium` from 40, else `low`.
 * Null for a lead without a score.
 *
 * @param {*} value The raw `qualificationScore`.
 * @return {('high'|'medium'|'low'|null)}
 * @spec openspec/changes/pipeline-lead-score-call-first/specs/lead-management/spec.md#requirement-the-lead-list-shows-and-sorts-by-score-req-lscore-001
 */
export function scoreBand(value) {
	const score = normaliseScore(value)
	if (score === null) {
		return null
	}
	if (score >= HIGH_FROM) {
		return 'high'
	}
	return score >= MEDIUM_FROM ? 'medium' : 'low'
}

/**
 * The criteria a lead meets, with the points each adds, and their total.
 *
 * @param {object} lead The lead as the list or board holds it.
 * @return {{matched: Array<{id: string, label: string, points: number}>, total: number}}
 * @spec openspec/changes/pipeline-lead-score-call-first/specs/lead-management/spec.md#requirement-a-person-can-see-why-a-lead-has-its-score-req-lscore-003
 */
export function explainScore(lead) {
	const matched = SCORE_CRITERIA.filter((c) => c.test(lead || {})).map(
		({ id, label, points }) => ({ id, label, points }),
	)
	return { matched, total: matched.reduce((sum, c) => sum + c.points, 0) }
}

/**
 * When a lead was last updated, in milliseconds; 0 when unknown.
 *
 * @param {object} lead The lead.
 * @return {number}
 */
function updatedAt(lead) {
	const raw = lead?.['@self']?.updated || lead?.updated || null
	const time = raw ? new Date(raw).getTime() : 0
	return Number.isFinite(time) ? time : 0
}

/**
 * Array.sort comparator for "call first": highest score first, leads
 * without a score last, ties broken by the lead updated longest ago.
 *
 * @param {object} a A lead.
 * @param {object} b Another lead.
 * @return {number}
 * @spec openspec/changes/pipeline-lead-score-call-first/specs/lead-management/spec.md#requirement-the-board-card-shows-the-score-req-lscore-002
 */
export function compareCallFirst(a, b) {
	const sa = normaliseScore(a?.qualificationScore)
	const sb = normaliseScore(b?.qualificationScore)
	if (sa !== sb) {
		if (sa === null) return 1
		if (sb === null) return -1
		return sb - sa
	}
	return updatedAt(a) - updatedAt(b)
}

/**
 * The sort "Call first" applies: highest score first, ties broken by the
 * lead that was updated longest ago. In OpenRegister's `_order` shape.
 *
 * @type {Array<{key: string, order: string}>}
 */
export const CALL_FIRST_SORT = [
	{ key: 'qualificationScore', order: 'desc' },
	{ key: '@self.updated', order: 'asc' },
]
