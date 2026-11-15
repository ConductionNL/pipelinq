/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 * SPDX-License-Identifier: EUPL-1.2
 *
 * The tables that sit straight in a card on the POS boards are framed, as
 * the boards draw them (PqKassa "Aankoopgeschiedenis" and the payments under
 * Totalen, PqKassaRetour's lines, PqLoyaliteit's tier table): nc-vue's
 * CnDataTable `framed` (a 1px frame, 18px outer insets, ruled rows).
 *
 * @spec openspec/specs/pos-transactions/spec.md#requirement-the-sale-page-reads-as-the-board
 */

import { readFileSync } from 'fs'
import { resolve } from 'path'
import { describe, expect, it } from 'vitest'
import fragment from '../../src/manifest.d/70-loyalty-program.json'

/**
 * The opening tags of every CnDataTable in a view.
 *
 * @param {string} file The view, relative to src/views.
 * @return {Array<string>} The tags.
 */
function tableTags(file) {
	const source = readFileSync(resolve(__dirname, '../../src/views', file), 'utf8')
	return source.match(/<CnDataTable\b[^>]*>/g) || []
}

/**
 * Find a widget by id anywhere in the fragment.
 *
 * @param {*} node A manifest node.
 * @param {string} id The widget id.
 * @return {object|null} The widget.
 */
function findWidget(node, id) {
	if (!node || typeof node !== 'object') return null
	if (node.id === id) return node
	for (const value of Object.values(node)) {
		const hit = findWidget(value, id)
		if (hit) return hit
	}
	return null
}

describe('POS card tables are framed', () => {
	it('frames the purchase history and payments tables of a sale', () => {
		const tags = tableTags('pos/PosTransactionForm.vue')
		expect(tags).toHaveLength(2)
		for (const tag of tags) expect(tag).toMatch(/\sframed[\s>]/)
	})

	it('frames the lines table of a refund', () => {
		const tags = tableTags('pos/PosRefundForm.vue')
		expect(tags).toHaveLength(1)
		expect(tags[0]).toMatch(/\sframed[\s>]/)
	})

	it('frames the loyalty tier table', () => {
		expect(findWidget(fragment, 'loyalty-tier-table').content.framed).toBe(true)
	})
})
