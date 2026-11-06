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
export async function findClientDuplicate({ email = '', name = '' } = {}) {
	const store = useObjectStore()
	const address = email.trim()
	const named = name.trim()
	const lookups = []
	if (EMAIL.test(address)) {
		lookups.push(['email', { email: address }])
	}
	if (named.length >= 3) {
		lookups.push(['name', { name: named }])
	}
	for (const [field, filter] of lookups) {
		const rows = await store.fetchCollection('client', { ...filter, _limit: 5 }).catch(() => [])
		const match = (rows || []).find((row) => String(row[field] || '').trim().toLowerCase() === filter[field].toLowerCase())
		if (match) {
			return { id: match.id || match.uuid, name: match.name || filter[field], field }
		}
	}
	return null
}
