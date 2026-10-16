/**
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * Barcode lookup for the till (PqKassa): a scan adds the product it names.
 *
 * @spec openspec/changes/screens-kassa-till-board/specs/pos-transaction-core/spec.md
 */

/**
 * The product a barcode names: its barcode, or else its SKU.
 *
 * @param {Array<object>} products The catalogue.
 * @param {string} code The scanned code.
 * @return {object|null} The product, or null.
 * @spec openspec/changes/screens-kassa-till-board/specs/pos-transaction-core/spec.md
 */
export function findProductByBarcode(products, code) {
	const wanted = String(code || '').trim()
	if (wanted === '') {
		return null
	}
	return (products || []).find((p) => String(p.barcode || '') === wanted)
		|| (products || []).find((p) => String(p.sku || '') === wanted)
		|| null
}
