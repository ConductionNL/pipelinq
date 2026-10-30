/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Board PqWerkplekKlant draws "Look up in the BRP" on the werkplek's
 * "Who is it" card as the light-blue tonal pill. A facts card's actions
 * take `variant: "tonal"` through the library's CnActionButtons.
 *
 * @spec openspec/changes/brp-verifies-the-client/specs/brp-lookup/spec.md
 */
import fs from 'node:fs'
import path from 'node:path'
import { describe, expect, it } from 'vitest'

const layout = JSON.parse(fs.readFileSync(path.resolve(__dirname, '../../src/menu-layout.simple.json'), 'utf8'))

/**
 * Every object in the layout with the given id.
 *
 * @param {string} id The widget id.
 * @return {object[]} The matching widgets.
 */
function widgetsWithId(id) {
	const found = []
	JSON.stringify(layout, (key, value) => {
		if (value && value.id === id) {
			found.push(value)
		}
		return value
	})
	return found
}

describe('the werkplek Who is it card', () => {
	it('draws "Look up in the BRP" as the tonal pill', () => {
		const cards = widgetsWithId('simple-call-who')
		expect(cards.length).toBeGreaterThan(0)
		for (const card of cards) {
			const lookup = card.content.actions.find((action) => action.label === 'Look up in the BRP')
			expect(lookup).toBeDefined()
			expect(lookup.variant).toBe('tonal')
		}
	})
})
