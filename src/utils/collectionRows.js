// SPDX-License-Identifier: EUPL-1.2
// Copyright (C) 2026 Conduction B.V.

/**
 * Normalise an object-store collection into a plain rows array.
 *
 * useObjectStore().getCollection(type) returns the results ARRAY directly, but
 * older shapes wrapped them in a { results } envelope. Accept either so a lib
 * bump cannot silently empty the POS product / client / line lists again.
 *
 * @param {Array|object|null|undefined} collection The getCollection() value.
 * @return {Array} The rows array (never null/undefined).
 * @spec openspec/changes/screens-kassa-till-board/specs/pos-transaction-core/spec.md
 */
export function collectionRows(collection) {
	if (Array.isArray(collection)) {
		return collection
	}
	return (collection && collection.results) || []
}
