/**
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * The prospects page's lenses, search and Filter menu (PqProspects), over the
 * list the prospects endpoint returns.
 *
 * @spec openspec/specs/prospect-discovery/spec.md
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
 * @spec openspec/specs/prospect-discovery/spec.md
 */
export function matchesProspectLens(prospect, lens, now) {
	if (lens === 1) {
		return Number(prospect.fitScore) > GOOD_FIT_SCORE
	}
	if (lens === 2) {
		return registeredWithinWeek(prospect, now)
	}
	return true
}

/**
 * Whether a company was registered in the seven days up to now.
 *
 * @param {object} prospect The company.
 * @param {Date} now The current moment.
 * @return {boolean}
 * @spec openspec/specs/prospect-discovery/spec.md
 */
function registeredWithinWeek(prospect, now) {
	const registered = prospect.registrationDate ? new Date(prospect.registrationDate) : null
	if (!registered || Number.isNaN(registered.getTime())) {
		return false
	}
	const age = now.getTime() - registered.getTime()
	return age >= 0 && age <= WEEK_MS
}

/**
 * Whether a company matches the search (company, SBI code or place) and the
 * Filter menu's choices (`{ 'address.city': ['Utrecht'] }`).
 *
 * @param {object} prospect The company.
 * @param {string} search The search text.
 * @param {{[field: string]: string[]}} filters The chosen values per field.
 * @return {boolean}
 * @spec openspec/specs/prospect-discovery/spec.md
 */
export function prospectMatches(prospect, search, filters) {
	if (!matchesFilters(prospect, filters)) {
		return false
	}
	return matchesSearch(prospect, search)
}

/**
 * Whether a company has one of the chosen values for every field with a
 * choice.
 *
 * @param {object} prospect The company.
 * @param {{[field: string]: string[]}} filters The chosen values per field.
 * @return {boolean}
 * @spec openspec/specs/prospect-discovery/spec.md
 */
function matchesFilters(prospect, filters) {
	for (const [field, values] of Object.entries(filters || {})) {
		if (isChosen(values) && !values.includes(String(fieldValue(prospect, field) ?? ''))) {
			return false
		}
	}
	return true
}

/**
 * @param {unknown} values A filter's chosen values.
 * @return {boolean} Whether any value was chosen.
 */
function isChosen(values) {
	return Array.isArray(values) && values.length > 0
}

/**
 * @param {object} prospect The company.
 * @param {string} field A dotted path, such as `address.city`.
 * @return {unknown} The value at that path.
 */
function fieldValue(prospect, field) {
	return field.split('.').reduce((obj, key) => (obj == null ? obj : obj[key]), prospect)
}

/**
 * Whether the company, SBI code, SBI description or place contains the
 * search text; an empty search matches every company.
 *
 * @param {object} prospect The company.
 * @param {string} search The search text.
 * @return {boolean}
 * @spec openspec/specs/prospect-discovery/spec.md
 */
function matchesSearch(prospect, search) {
	const query = (search || '').trim().toLowerCase()
	if (query === '') {
		return true
	}
	return [prospect.tradeName, prospect.sbiCode, prospect.sbiDescription, prospect.address?.city]
		.some((text) => String(text ?? '').toLowerCase().includes(query))
}
