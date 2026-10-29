// SPDX-License-Identifier: EUPL-1.2
// Copyright (C) 2026 Conduction B.V.

/**
 * The tenant's ticket categories (ticketCategory objects): a ticket stores a
 * category's key, people read its name.
 */

import axios from '@nextcloud/axios'
import { generateUrl } from '@nextcloud/router'

/**
 * Read the tenant's ticketCategory objects. A failed read answers an empty
 * list, so the form falls back to its free-text field.
 *
 * @return {Promise<Array<object>>} The category objects.
 * @spec openspec/changes/request-category-from-vocabulary/specs/request-management/spec.md#requirement-a-requests-category-is-picked-from-the-tenants-category-list
 */
export async function fetchTicketCategories() {
	try {
		const response = await axios.get(
			generateUrl('/apps/openregister/api/objects/pipelinq/ticketCategory?_limit=200'),
		)
		const rows = response?.data?.results
		return Array.isArray(rows) ? rows : []
	} catch {
		return []
	}
}

/**
 * The select options for the active categories, sorted by name, the key as
 * the value. A stored category the list does not name is kept as its own
 * option, so saving never drops it. No active category means no options.
 *
 * @param {Array<object>} rows The ticketCategory objects.
 * @param {string} [current] The category the ticket stores now.
 * @return {Array<{value: string, label: string}>} The options.
 * @spec openspec/changes/request-category-from-vocabulary/specs/request-management/spec.md#requirement-a-requests-category-is-picked-from-the-tenants-category-list
 */
export function categoryOptions(rows, current = '') {
	const options = (Array.isArray(rows) ? rows : [])
		.filter((row) => row && row.key && row.active !== false)
		.map((row) => ({ value: String(row.key), label: String(row.name || row.key) }))
		.sort((a, b) => a.label.localeCompare(b.label))
	if (options.length > 0 && current && !options.some((option) => option.value === current)) {
		options.push({ value: current, label: current })
	}
	return options
}
