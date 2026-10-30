/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Board PqTicketContactmomenten: the Contact moments tab draws a title row,
 * "Contactmomenten" with "2 bij dit ticket, nieuwste eerst" under it and the
 * outlined "Contact vastleggen" beside it.
 *
 * @spec openspec/changes/ticket-contacts-title-row/specs/unify-ticket-supertype/spec.md#requirement-the-contact-moments-tab-has-a-title-row
 */

import { readFileSync } from 'node:fs'
import { resolve } from 'node:path'
import { describe, expect, it } from 'vitest'

const layout = JSON.parse(readFileSync(resolve(__dirname, '../../src/menu-layout.simple.json'), 'utf8'))
const nl = JSON.parse(readFileSync(resolve(__dirname, '../../l10n/nl.json'), 'utf8')).translations

/**
 * Every object in the layout, depth first.
 *
 * @param {unknown} node A node.
 * @param {Array<object>} found The objects found.
 * @return {Array<object>} The objects.
 */
function objects(node, found = []) {
	if (Array.isArray(node)) {
		node.forEach((child) => objects(child, found))
	} else if (node && typeof node === 'object') {
		found.push(node)
		Object.values(node).forEach((child) => objects(child, found))
	}
	return found
}

describe('the ticket contact moments title row', () => {
	const all = objects(layout)
	const list = all.find((node) => node.id === 'ticket-contactmoments')
	const tab = all.find((node) => node.widgetId === 'ticket-contactmoments' && node.label === 'Contact moments')

	it('puts Log contact in the title row with the phone icon', () => {
		expect(list.content.addPlacement).toBe('header')
		expect(list.content.addIcon).toBe('Phone')
		expect(nl[list.content.addLabel]).toBe('Contact vastleggen')
	})

	it('says how many are on the ticket under the title, which the tab shows', () => {
		expect(tab.showTitle).toBe(true)
		expect(list.content.headerSubtitle).toBe('{total} on this ticket, newest first')
		expect(nl[list.content.headerSubtitle]).toBe('{total} bij dit ticket, nieuwste eerst')
		expect(list.content.sort).toEqual({ field: 'occurredAt', dir: 'desc' })
	})
})
