// SPDX-License-Identifier: EUPL-1.2
// Copyright (C) 2026 Conduction B.V.

/**
 * Helpers for the hand-written create client form (pipelinq-forms-review).
 */

/**
 * Sectors offered by the industry picker. The field also takes any sector
 * typed in, so this is a suggestion list, not a closed set (D6).
 */
export const INDUSTRY_SECTORS = [
	'Agriculture',
	'Construction',
	'Consultancy',
	'Education',
	'Energy',
	'Finance and insurance',
	'Healthcare',
	'Hospitality',
	'ICT',
	'Logistics',
	'Manufacturing',
	'Non-profit',
	'Public sector',
	'Real estate',
	'Retail',
	'Wholesale',
]

/**
 * A stored industry as a list: a string from before industry became a
 * list is wrapped, an empty value becomes an empty list.
 *
 * @param {unknown} value The stored industry.
 * @return {string[]} The sectors.
 * @spec openspec/changes/pipelinq-forms-review/specs/client-management/spec.md
 */
export function industryList(value) {
	if (Array.isArray(value)) {
		return value.filter((item) => typeof item === 'string' && item !== '')
	}
	return typeof value === 'string' && value.trim() !== '' ? [value.trim()] : []
}

/**
 * The language to preselect: the user's own when the instance can write it,
 * else the instance default.
 *
 * @param {string[]} available The tags the instance can render.
 * @param {string} instanceDefault The instance default language.
 * @param {string} userLanguage The current user's language.
 * @return {string} The tag, or ''.
 * @spec openspec/changes/pipelinq-forms-review/specs/client-management/spec.md
 */
export function defaultLanguage(available, instanceDefault, userLanguage) {
	if (available.includes(userLanguage)) {
		return userLanguage
	}
	const base = (userLanguage || '').split(/[-_]/)[0]
	if (available.includes(base)) {
		return base
	}
	return available.includes(instanceDefault) ? instanceDefault : ''
}
