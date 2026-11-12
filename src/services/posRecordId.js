// SPDX-License-Identifier: EUPL-1.2
// Copyright (C) 2026 Conduction B.V.

/**
 * The id of a POS record the tender screens read from OpenRegister (a
 * tender, a tender type): its `@self.id` first, then `id`, then `uuid`.
 *
 * @param {?object} record The record.
 * @return {string} Its id, or an empty string.
 * @spec openspec/changes/archive/2026-06-14-pos-split-tender/specs.md#req-pst-002-add-tender-to-transaction-mvp
 */
export function posRecordId(record) {
	if (record?.['@self']?.id) {
		return record['@self'].id
	}
	return record?.id || record?.uuid || ''
}
