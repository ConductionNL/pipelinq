// SPDX-License-Identifier: EUPL-1.2
// SPDX-FileCopyrightText: 2026 Conduction B.V.
//
// scorecardCriteria — the rules of the call scorecard editor (contact-moments-call-review
// D3, task 2.2): a criterion has a stable key, a label, a weight of 0 or more and a scale
// of 1 to 5. The key is what a review's scores point at, so an existing key never changes
// when its label is renamed; a new criterion gets one made from its label.

/** The highest score a criterion can have (design D3). */
export const MAX_SCALE = 5

/**
 * A key made from a label: lower case letters and digits joined by dashes, unique
 * among the keys already taken.
 *
 * @param {string} label The criterion's label.
 * @param {string[]} taken Keys already in use on the scorecard.
 * @return {string} The key.
 * @spec openspec/changes/contact-moments-call-review/specs/call-review/spec.md#requirement-a-reviewer-scores-a-call-and-writes-coaching-points-req-crv-002
 */
export function criterionKey(label, taken = []) {
	const base = String(label ?? '')
		.normalize('NFKD')
		.replace(/[̀-ͯ]/g, '')
		.toLowerCase()
		.replace(/[^a-z0-9]+/g, '-')
		.replace(/^-+|-+$/g, '') || 'criterion'
	let key = base
	let n = 2
	while (taken.includes(key)) {
		key = `${base}-${n}`
		n++
	}
	return key
}

/**
 * The criteria as stored: rows without a label are left out, a row without a key
 * gets one from its label, the weight is a number of 0 or more (1 when not given)
 * and the scale a whole number from 1 to 5 (5 when not given).
 *
 * @param {Array<object>} rows The editor's rows.
 * @return {Array<{key: string, label: string, weight: number, scale: number}>} The criteria.
 * @spec openspec/changes/contact-moments-call-review/specs/call-review/spec.md#requirement-a-reviewer-scores-a-call-and-writes-coaching-points-req-crv-002
 */
export function normaliseCriteria(rows) {
	const out = []
	for (const row of Array.isArray(rows) ? rows : []) {
		const label = String(row?.label ?? '').trim()
		if (label === '') {
			continue
		}
		const ownKey = String(row?.key ?? '').trim()
		const key = ownKey !== '' ? ownKey : criterionKey(label, out.map((c) => c.key))
		const weight = Number(row?.weight)
		const scale = Math.round(Number(row?.scale))
		out.push({
			key,
			label,
			weight: row?.weight === '' || row?.weight === undefined || row?.weight === null || Number.isNaN(weight) ? 1 : Math.max(0, weight),
			scale: Number.isNaN(scale) || row?.scale === '' || row?.scale === undefined || row?.scale === null ? MAX_SCALE : Math.min(MAX_SCALE, Math.max(1, scale)),
		})
	}
	return out
}

/**
 * What stops a scorecard from being saved, as English source texts to translate.
 *
 * @param {{title?: string, criteria?: Array<object>}} scorecard The scorecard, criteria normalised.
 * @return {string[]} The problems; empty when it can be saved.
 * @spec openspec/changes/contact-moments-call-review/specs/call-review/spec.md#requirement-a-reviewer-scores-a-call-and-writes-coaching-points-req-crv-002
 */
export function scorecardProblems(scorecard) {
	const problems = []
	if (String(scorecard?.title ?? '').trim() === '') {
		problems.push('Give the scorecard a title.')
	}
	const criteria = Array.isArray(scorecard?.criteria) ? scorecard.criteria : []
	if (criteria.length === 0) {
		problems.push('Add at least one criterion.')
	}
	const keys = criteria.map((c) => c.key)
	if (new Set(keys).size !== keys.length) {
		problems.push('Two criteria have the same key.')
	}
	if (criteria.length > 0 && criteria.every((c) => Number(c.weight) === 0)) {
		problems.push('At least one criterion needs a weight above 0.')
	}
	return problems
}
