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
	const list = (value) =>
		Array.isArray(value) ? value.filter(Boolean).join(', ') : ''
	const count = (value) => (Number(value) > 0 ? String(value) : '')
	const facts = [
		{ key: 'sbiCodes', label: 'SBI codes', value: list(icp.sbiCodes) },
		{
			key: 'employeeCountMin',
			label: 'Min. employees',
			value: count(icp.employeeCountMin),
		},
		{
			key: 'employeeCountMax',
			label: 'Max. employees',
			value: count(icp.employeeCountMax),
		},
		{ key: 'provinces', label: 'Provinces', value: list(icp.provinces) },
		{ key: 'legalForms', label: 'Legal forms', value: list(icp.legalForms) },
	].filter((fact) => fact.value !== '')
	if (facts.length > 0 && typeof icp.excludeInactive === 'boolean') {
		facts.push({
			key: 'excludeInactive',
			label: 'Exclude inactive companies',
			value: icp.excludeInactive ? 'Yes' : 'No',
		})
	}
	if (facts.length > 0 && list(icp.keywords) !== '') {
		facts.push({ key: 'keywords', label: 'Keywords', value: list(icp.keywords) })
	}
	return facts.map((fact) => ({
		...fact,
		label: translate(fact.label),
		value: fact.key === 'excludeInactive' ? translate(fact.value) : fact.value,
	}))
}
