// SPDX-License-Identifier: EUPL-1.2
// SPDX-FileCopyrightText: 2026 Conduction B.V.
//
// The active receipt templates a receipt modal offers.
//
// @spec openspec/specs/pos-receipt-engine/spec.md#REQ-PRE-003

import { useObjectStore } from '../store/modules/object.js'

/**
 * Read the active receipt templates. An unreadable list answers none, so the
 * modal still prints or mails with the default template.
 *
 * @return {Promise<Array<object>>} The active templates.
 * @spec openspec/specs/pos-receipt-engine/spec.md#REQ-PRE-003
 */
export async function loadActiveReceiptTemplates() {
	try {
		const store = useObjectStore()
		await store.fetchCollection('receiptTemplate', { status: 'active', _limit: 100 })
		const rows = store.getCollection('receiptTemplate')?.results || []
		return rows.filter((tpl) => (tpl.status || 'active') === 'active')
	} catch {
		return []
	}
}
