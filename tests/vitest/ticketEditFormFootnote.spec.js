/**
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * Board PqTicketBewerken closes the ticket edit form on a note: the status is
 * not edited there, it moves through the ticket's steps. The simple profile's
 * ticket page gives its edit form that note (nextcloud-vue `editFootnote`).
 *
 * @spec openspec/changes/ticket-edit-form-footnote/specs/detail-pages/spec.md#requirement-the-ticket-edit-form-says-where-the-status-changes
 */
import { buildManifest } from '@conduction/nextcloud-vue/src/utils/buildManifest.js'
import fs from 'fs'
import path from 'path'
import { describe, expect, it } from 'vitest'
import { applyMenuModules } from '../../src/utils/menuModules.js'
import { buildProfiledManifest } from '../../src/utils/structureProfile.js'

const ROOT = path.resolve(__dirname, '../..')
const readJson = (...parts) => JSON.parse(fs.readFileSync(path.join(ROOT, ...parts), 'utf8'))
const fragments = fs.readdirSync(path.join(ROOT, 'src', 'manifest.d'))
	.filter((name) => name.endsWith('.json')).sort()
	.map((name) => readJson('src', 'manifest.d', name))
const build = (layout) => buildProfiledManifest(buildManifest, readJson('src', 'manifest.json'), fragments, applyMenuModules(readJson('src', layout), []))
const ticketConfig = (layout) => build(layout).pages.find((page) => page.id === 'TicketDetail').config
const NOTE = 'You do not change the status here: it moves through the ticket\'s steps, Answer the customer, Complete or Reject, so every change is checked.'

describe('the ticket edit form note (PqTicketBewerken)', () => {
	it('closes the simple ticket edit form on the status note', () => {
		expect(ticketConfig('menu-layout.simple.json').editFootnote).toBe(NOTE)
	})

	it('has the note in Dutch and English', () => {
		expect(readJson('l10n', 'nl.json').translations[NOTE]).toBe('De status wijzig je hier niet: die verandert via de stappen van het ticket, De klant beantwoorden, Afronden of Afwijzen, zodat elke wijziging wordt gecontroleerd.')
		expect(readJson('l10n', 'en.json').translations[NOTE]).toBe(NOTE)
	})

	it('names only steps the simple ticket page has', () => {
		const labels = ticketConfig('menu-layout.simple.json').headerActions.map((action) => action.label)
		expect(labels).toEqual(expect.arrayContaining(['Answer the customer', 'Complete', 'Reject']))
	})
})
