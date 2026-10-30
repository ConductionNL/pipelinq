/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Board PqTicket draws "Set the term" on a request's Time limit card as
 * the light-blue tonal pill. A facts card's actions take
 * `variant: "tonal"` through the library's CnActionButtons.
 *
 * @spec openspec/changes/request-term-and-case-link/specs/request-management/spec.md
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

describe('the request Time limit card', () => {
	it('draws "Set the term" as the tonal pill', () => {
		const cards = widgetsWithId('side-deadline')
		expect(cards.length).toBeGreaterThan(0)
		for (const card of cards) {
			const setTerm = card.content.actions.find((action) => action.id === 'set-term')
			expect(setTerm).toBeDefined()
			expect(setTerm.variant).toBe('tonal')
		}
	})
})
