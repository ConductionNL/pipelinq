/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Board PqOrganisaties: an organisation's open tickets are its requests and
 * complaints that still need work, and its last contact is its newest
 * contact moment.
 *
 * @spec openspec/specs/client-management/spec.md
 */
import { readFileSync } from 'node:fs'
import { resolve } from 'node:path'
import { describe, expect, it } from 'vitest'

const simple = JSON.parse(readFileSync(resolve(__dirname, '../../src/menu-layout.simple.json'), 'utf8'))
const preset = simple.pages.find((p) => p.id === 'Clients').config.queryPresets.find((p) => p.id === 'organisations')
const column = (key) => preset.columns.find((c) => c.key === key)

describe('the organisations list', () => {
	it('counts open requests and complaints only, not resolved tickets or contact moments', () => {
		expect(column('openTickets').aggregate.where).toEqual({
			client: '@self.id',
			ticketType: ['request', 'complaint'],
			status: ['new', 'in_progress', 'awaiting_customer'],
		})
	})

	it('shows the newest contact moment, or that there was none', () => {
		expect(column('lastContact').aggregate).toMatchObject({ op: 'max', field: 'occurredAt', where: { client: '@self.id', ticketType: 'interaction' }, zeroLabel: 'No contact yet' })
		expect(column('lastContact').formatter).toBe('date')
		expect(preset.columns.map((c) => c.key).at(-1)).toBe('lastContact')
	})

	it('names its add button for an organisation, as PqOrganisaties draws it', () => {
		expect(preset.addLabel).toBe('Add organisation')
		const nl = JSON.parse(readFileSync(resolve(__dirname, '../../l10n/nl.json'), 'utf8')).translations
		expect(nl[preset.addLabel]).toBe('Organisatie toevoegen')
	})
})
