/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Board PqTicket reads a ticket's codes as words and colours its pills:
 * channel "phone" reads "Telefoon", priority "normal" reads "Normaal", the
 * type pill is blue for a request and the status pill purple while in
 * progress (pl2, 27 October 2026). All of it is configuration on the
 * simple profile's ticket page.
 *
 * @spec openspec/changes/unify-ticket-supertype/specs/unify-ticket-supertype/spec.md#requirement-unified-tickets-workspace
 */

import fs from 'fs'
import path from 'path'
import { describe, expect, it } from 'vitest'
import { enumText } from '../../src/services/cellFormatters.js'

const ROOT = path.resolve(__dirname, '../..')
const simple = JSON.parse(fs.readFileSync(path.join(ROOT, 'src', 'menu-layout.simple.json'), 'utf8'))
const nl = JSON.parse(fs.readFileSync(path.join(ROOT, 'l10n', 'nl.json'), 'utf8')).translations
const page = simple.pages.find((p) => p.id === 'TicketDetail')

/**
 * The first object in a JSON tree keyed `key`.
 *
 * @param {unknown} node The tree.
 * @param {string} key The key.
 * @return {object|null} The object.
 */
function findKey(node, key) {
	if (node && typeof node === 'object') {
		if (!Array.isArray(node) && node[key] && typeof node[key] === 'object') {
			return node[key]
		}
		for (const child of Object.values(node)) {
			const found = findKey(child, key)
			if (found) {
				return found
			}
		}
	}
	return null
}

describe('ticket page values', () => {
	it('prints category, channel and priority as words', () => {
		const overrides = findKey(simple, 'ticket-data').content.overrides
		for (const field of ['category', 'channel', 'priority']) {
			expect(overrides[field], field).toEqual({ formatter: 'enumText' })
		}
		const side = Object.fromEntries(page.config.sideColumn.filter((w) => typeof w === 'object').map((w) => [w.id, w.content?.overrides || {}]))
		expect(side['side-customer'].channel).toEqual({ formatter: 'enumText' })
		expect(side['side-deadline'].priority).toEqual({ formatter: 'enumText' })
	})

	it('reads phone and normal the way the board does, in Dutch', () => {
		expect(nl[enumText('phone')]).toBe('Telefoon')
		expect(nl[enumText('normal')]).toBe('Normaal')
	})

	it('colours the type and status pills', () => {
		expect(page.config.typePill.colorMap.request).toBe('info')
		expect(page.config.statusPill.colorMap.in_progress).toBe('purple')
	})
})
