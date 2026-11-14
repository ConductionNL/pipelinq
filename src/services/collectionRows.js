// SPDX-License-Identifier: EUPL-1.2
// SPDX-FileCopyrightText: 2026 Conduction B.V.

/**
 * The rows of an object-store collection. The nc-vue object store keeps a
 * collection as a plain array; a `{ results }` page is read too.
 *
 * @param {Array|object|null} collection The collection.
 * @return {Array<object>} The rows.
 * @spec openspec/specs/pos-refund-return/spec.md#requirement-a-refund-reads-the-sales-and-lines-the-store-holds
 */
export function collectionRows(collection) {
	if (Array.isArray(collection)) {
		return collection
	}
	return (collection && collection.results) || []
}
