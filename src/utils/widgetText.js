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
 * @param {(string|number|object|Array|null|undefined)} value the raw property value.
 * @return {string} a display string ('' when nothing usable is found).
 */
export function toText(value) {
	if (isAbsent(value)) {
		return ''
	}
	if (typeof value === 'string') {
		return value
	}
	if (Array.isArray(value)) {
		return value.map(toText).filter(Boolean).join(', ')
	}
	if (typeof value === 'object') {
		return objectText(value)
	}
	return String(value)
}

/**
 * The fields an expanded relation or wrapper map is shown by, in order.
 */
const DISPLAY_KEYS = ['title', 'name', 'label', 'value', 'nl']

/**
 * @param {(string|number|boolean|object|null|undefined)} value A value.
 * @return {boolean} Whether it is null or undefined.
 */
function isAbsent(value) {
	return value === null || value === undefined
}

/**
 * An expanded relation or wrapper map as text.
 *
 * @param {object} value The object.
 * @return {string} Its display field as text, or ''.
 */
function objectText(value) {
	// Expanded relation or wrapper map ({"nl":"test"} — the key carries no
	// meaning): prefer an explicit display field, then the `nl` wrapper key.
	const display =
		DISPLAY_KEYS.map((key) => value[key]).find((field) => !isAbsent(field))
		?? value['@self']?.title
		?? value['@self']?.name
	if (!isAbsent(display)) {
		return toText(display)
	}
	return ''
}
