/**
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * The ideal customer profile as the facts the prospects page shows above
 * its list (PqProspects): SBI codes, the employee range, provinces, legal
 * forms, whether inactive companies are left out, and the keywords.
 *
 * @spec openspec/specs/prospect-discovery/spec.md
 */

/**
 * @param {unknown} value A list criterion.
 * @return {string} Its filled-in values, comma separated, or ''.
 */
function joinedList(value) {
	return Array.isArray(value) ? value.filter(Boolean).join(', ') : ''
}

/**
 * @param {unknown} value A count criterion.
 * @return {string} The count when above zero, or ''.
 */
function positiveCount(value) {
	return Number(value) > 0 ? String(value) : ''
}

/**
 * The criteria the facts start with, in display order, and how each is read.
 */
const CRITERIA = [
	{ key: 'sbiCodes', label: 'SBI codes', read: joinedList },
	{ key: 'employeeCountMin', label: 'Min. employees', read: positiveCount },
	{ key: 'employeeCountMax', label: 'Max. employees', read: positiveCount },
	{ key: 'provinces', label: 'Provinces', read: joinedList },
	{ key: 'legalForms', label: 'Legal forms', read: joinedList },
]

/**
 * The filled-in criteria as facts, in display order.
 *
 * @param {object} icp The criteria.
 * @return {Array<{key: string, label: string, value: string}>} The facts.
 * @spec openspec/specs/prospect-discovery/spec.md
 */
function criteriaFacts(icp) {
	return CRITERIA
		.map((criterion) => ({ key: criterion.key, label: criterion.label, value: criterion.read(icp[criterion.key]) }))
		.filter((fact) => fact.value !== '')
}

/**
 * The facts that only follow a filled-in criterion: whether inactive
 * companies are left out, and the keywords.
 *
 * @param {object} icp The criteria.
 * @return {Array<{key: string, label: string, value: string}>} The facts.
 * @spec openspec/specs/prospect-discovery/spec.md
 */
function trailingFacts(icp) {
	const facts = []
	if (typeof icp.excludeInactive === 'boolean') {
		facts.push({
			key: 'excludeInactive',
			label: 'Exclude inactive companies',
			value: icp.excludeInactive ? 'Yes' : 'No',
		})
	}
	if (joinedList(icp.keywords) !== '') {
		facts.push({ key: 'keywords', label: 'Keywords', value: joinedList(icp.keywords) })
	}
	return facts
}

/**
 * Turn the profile's criteria into label and value pairs; an empty criterion
 * is left out.
 *
 * @param {object|null} icp The criteria from the prospects endpoint.
 * @param {(text: string) => string} translate Translates an English label.
 * @return {Array<{key: string, label: string, value: string}>}
 * @spec openspec/specs/prospect-discovery/spec.md
 */
export function icpFacts(icp, translate) {
	if (!icp || typeof icp !== 'object') {
		return []
	}
	const facts = criteriaFacts(icp)
	if (facts.length > 0) {
		facts.push(...trailingFacts(icp))
	}
	return facts.map((fact) => ({
		...fact,
		label: translate(fact.label),
		value: fact.key === 'excludeInactive' ? translate(fact.value) : fact.value,
	}))
}
