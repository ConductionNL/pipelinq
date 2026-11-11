// SPDX-License-Identifier: EUPL-1.2
// SPDX-FileCopyrightText: 2026 Conduction B.V.

import { useObjectStore } from '../store/modules/object.js'

const EMAIL = /^[^\s@]+@[^\s@]+\.[^\s@]+$/

/**
 * A client that already has this e-mail address or, failing that, exactly
 * this name (board PqKlantNieuw: "Bakkerij De Graaf heeft hetzelfde
 * e-mailadres"). An address is compared without case; a name only from three
 * characters on. A failed lookup finds nothing, so it never blocks a save.
 *
 * @param {object} input What was typed.
 * @param {string} [input.email] The e-mail address.
 * @param {string} [input.name] The name.
 * @return {Promise<{id: string, name: string, field: string}|null>} The client and what matched, or null.
 * @spec openspec/changes/2026-03-20-client-management/tasks.md#task-3.1
 */
export async function findClientDuplicate(input = {}) {
	const store = useObjectStore()
	for (const lookup of clientLookups(input)) {
		const [, filter] = lookup
		const rows = await store.fetchCollection('client', { ...filter, _limit: 5 }).catch(() => [])
		const match = matchingClient(rows, lookup)
		if (match) {
			return duplicateOf(match, lookup)
		}
	}
	return null
}

/**
 * The lookups worth making: the address when it is one, the name from three characters on.
 *
 * @param {{email: (string|undefined), name: (string|undefined)}} input What was typed.
 * @return {Array<Array>} `[field, filter]` pairs, address first.
 */
function clientLookups({ email = '', name = '' }) {
	const address = email.trim()
	const named = name.trim()
	const lookups = []
	if (EMAIL.test(address)) {
		lookups.push(['email', { email: address }])
	}
	if (named.length >= 3) {
		lookups.push(['name', { name: named }])
	}
	return lookups
}

/**
 * @param {?Array<object>} rows The clients the lookup found.
 * @param {Array} lookup The `[field, filter]` pair.
 * @return {(object|undefined)} The client whose field matches without case.
 */
function matchingClient(rows, lookup) {
	const [field, filter] = lookup
	return (rows || []).find((row) => String(row[field] || '').trim().toLowerCase() === filter[field].toLowerCase())
}

/**
 * @param {object} match The matching client.
 * @param {Array} lookup The `[field, filter]` pair.
 * @return {{id: string, name: string, field: string}} The client and what matched.
 */
function duplicateOf(match, lookup) {
	const [field, filter] = lookup
	return { id: match.id || match.uuid, name: match.name || filter[field], field }
}
