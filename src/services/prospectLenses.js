/**
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * The prospects page's lenses, search and Filter menu (PqProspects), over the
 * list the prospects endpoint returns.
 *
 * @spec openspec/changes/prospects-as-drawn/specs/prospect-discovery/spec.md
 */

/** A score above this is a good fit (the score's high band). */
export const GOOD_FIT_SCORE = 70

const WEEK_MS = 7 * 24 * 60 * 60 * 1000

/**
 * Whether a company is in a lens: 0 every company, 1 a good fit, 2 registered
 * in the KVK register in the last seven days.
 *
 * @param {object} prospect The company.
 * @param {number} lens The lens index.
 * @param {Date} now The current moment.
 * @return {boolean}
 * @spec openspec/changes/prospects-as-drawn/specs/prospect-discovery/spec.md
 */
export function matchesProspectLens(prospect, lens, now) {
	if (lens === 1) {
		return Number(prospect.fitScore) > GOOD_FIT_SCORE
	}
	if (lens === 2) {
		const registered = prospect.registrationDate ? new Date(prospect.registrationDate) : null
		if (!registered || Number.isNaN(registered.getTime())) {
			return false
		}
		const age = now.getTime() - registered.getTime()
		return age >= 0 && age <= WEEK_MS
	}
	return true
}

/**
 * Whether a company matches the search (company, SBI code or place) and the
 * Filter menu's choices (`{ 'address.city': ['Utrecht'] }`).
 *
 * @param {object} prospect The company.
 * @param {string} search The search text.
 * @param {{[field: string]: string[]}} filters The chosen values per field.
 * @return {boolean}
 * @spec openspec/changes/prospects-as-drawn/specs/prospect-discovery/spec.md
 */
export function prospectMatches(prospect, search, filters) {
	for (const [field, values] of Object.entries(filters || {})) {
		if (!Array.isArray(values) || values.length === 0) {
			continue
		}
		const value = field.split('.').reduce((obj, key) => (obj == null ? obj : obj[key]), prospect)
		if (!values.includes(String(value ?? ''))) {
			return false
		}
	}
	const query = (search || '').trim().toLowerCase()
	if (query === '') {
		return true
	}
	return [prospect.tradeName, prospect.sbiCode, prospect.sbiDescription, prospect.address?.city]
		.some((text) => String(text ?? '').toLowerCase().includes(query))
}
