// SPDX-License-Identifier: EUPL-1.2
// Copyright (C) 2026 Conduction B.V.

/**
 * Formatters that show a referenced object's name instead of its id.
 *
 * A booking stores `customerId` (a contact, or a client) and `serviceId` as
 * plain uuids, so the booking page's data block showed the raw ids. A
 * formatter from here looks the object up once, caches the name in a reactive
 * map, and returns it. The data widget calls formatters inside a computed, so
 * it re-renders by itself when the name arrives.
 *
 * @spec openspec/changes/review-finish/specs/appointment-booking/spec.md
 */

import { reactive } from 'vue'

/** Shown while a name is still being looked up; never the raw id. */
export const PENDING_LABEL = '…'

/**
 * The display name of an object.
 *
 * @param {object} object The object.
 * @return {string} Its name, or '' when it has none.
 * @spec openspec/changes/review-finish/specs/appointment-booking/spec.md
 */
export function objectLabel(object) {
	if (!object || typeof object !== 'object') {
		return ''
	}
	const person = [object.firstName, object.lastName].filter(Boolean).join(' ')
	return String(object.name || object.fullName || object.title || person || '')
}

/**
 * Build a formatter that resolves an id through the given lookups, in order.
 * The first lookup that returns an object with a name wins. When none does,
 * the id itself is shown, so a missing object stays visible.
 *
 * @param {Array<(id: string) => Promise<object|null>>} lookups Object fetchers.
 * @return {(value: unknown) => string} The formatter.
 * @spec openspec/changes/review-finish/specs/appointment-booking/spec.md
 */
export function createNameFormatter(lookups) {
	const names = reactive({})
	const started = new Set()

	const resolve = async (id) => {
		for (const lookup of lookups) {
			try {
				const label = objectLabel(await lookup(id))
				if (label) {
					return label
				}
			} catch {
				// Try the next lookup.
			}
		}
		return id
	}

	return (value) => {
		if (value === null || value === undefined || value === '') {
			return ''
		}
		const id = String(value)
		if (id in names) {
			return names[id]
		}
		if (!started.has(id)) {
			started.add(id)
			resolve(id).then((label) => {
				names[id] = label
			})
		}
		return PENDING_LABEL
	}
}
