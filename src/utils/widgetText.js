/**
 * SPDX-FileCopyrightText: 2026 Pipelinq Contributors
 * SPDX-License-Identifier: EUPL-1.2
 */

/**
 * Coerce an OpenRegister property value to a display string.
 *
 * Nextcloud's `NcDashboardWidgetItem` declares `mainText`/`subText` as String
 * props, but some properties come back as objects: an expanded relation
 * (`{ title|name|… }`) or a wrapper map such as `{ "nl": "test" }` (the key is
 * NOT meaningful — it's just how the value is stored). Pull the string out,
 * recursing for nested values, and return '' when nothing usable is found so
 * callers can apply their own default.
 *
 * @param {*} value the raw property value.
 * @return {string} a display string ('' when nothing usable is found).
 */
export function toText(value) {
	if (value === null || value === undefined) {
		return ''
	}
	if (typeof value === 'string') {
		return value
	}
	if (typeof value === 'number' || typeof value === 'boolean') {
		return String(value)
	}
	if (Array.isArray(value)) {
		return value.map(toText).filter(Boolean).join(', ')
	}
	if (typeof value === 'object') {
		// Expanded relation or wrapper map ({"nl":"test"} — the key carries no
		// meaning): prefer an explicit display field, then the `nl` wrapper key.
		const display =
			value.title
			?? value.name
			?? value.label
			?? value.value
			?? value.nl
			?? value['@self']?.title
			?? value['@self']?.name
		if (display !== null && display !== undefined) {
			return toText(display)
		}
		return ''
	}
	return String(value)
}
