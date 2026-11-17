// SPDX-License-Identifier: EUPL-1.2
// Copyright (C) 2026 Conduction B.V.

/**
 * Field helpers the lead and request forms share: an object read into a
 * form with a default per empty field, and a form emitted without its
 * empty fields.
 */

/**
 * The given fields of an object, each falling back to its default when empty.
 *
 * @param {object} source The stored object.
 * @param {object} defaults The fields to read, in order, with the value an empty one gets.
 * @return {object} The fields.
 */
export function fieldsOr(source, defaults) {
	const fields = {}
	for (const [key, fallback] of Object.entries(defaults)) {
		fields[key] = source[key] || fallback
	}
	return fields
}

/**
 * Take the empty ones of the given fields out of an object, in place.
 *
 * @param {object} data The object.
 * @param {Array<string>} keys The fields that are left out when empty.
 * @return {object} The same object.
 */
export function withoutEmptyFields(data, keys) {
	for (const key of keys) {
		if (!data[key]) delete data[key]
	}
	return data
}

/**
 * The watcher of a form's record prop: fill the form (`populateForm`) as soon
 * as the record arrives, and again whenever it changes, ignoring an empty one.
 *
 * @return {{immediate: boolean, handler: function(object): void}} The watcher definition.
 * @spec exclude form-filling plumbing shared by the record forms, no behaviour of its own
 */
export function populateFormWatcher() {
	return {
		immediate: true,
		handler(val) {
			if (val && Object.keys(val).length > 0) {
				this.populateForm(val)
			}
		},
	}
}
