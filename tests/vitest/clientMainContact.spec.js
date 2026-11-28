/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * PqOrganisatie Hoofdcontact: the side card under KvK and address shows the
 * organisation's main contact (initials, name, role, e-mail) and links to the
 * Contact persons tab.
 */

import fs from 'fs'
import path from 'path'
import { describe, expect, it } from 'vitest'

const ROOT = path.resolve(__dirname, '../..')
const read = (file) => JSON.parse(fs.readFileSync(path.join(ROOT, file), 'utf8'))
const manifest = read('src/manifest.json')
const nl = read('l10n/nl.json').translations
const part = read('lib/Settings/register.d/98-main-contact.json')
const example = read('lib/Settings/example-sets/zuiddrecht.json')
const config = manifest.pages.find((page) => page.id === 'ClientDetail').config
const sideIds = config.sideColumn.map((entry) => (typeof entry === 'string' ? entry : entry.id))
const card = config.sideColumn.find((entry) => entry.id === 'side-main-contact')

describe('ClientDetail main contact', () => {
	it('stands under KvK and address and above the open tickets, on an organisation only', () => {
		expect(sideIds.indexOf('side-main-contact')).toBe(sideIds.indexOf('side-kvk') + 1)
		expect(sideIds.indexOf('side-open-ticket')).toBe(sideIds.indexOf('side-main-contact') + 2)
		expect(card.visibleWhen).toEqual({ field: 'type', op: 'eq', value: 'organization' })
		expect(nl[card.title]).toBe('Hoofdcontact')
	})

	it('shows the flagged contact person first, as initials, name, role and e-mail', () => {
		const content = card.content
		expect(content).toMatchObject({ register: 'pipelinq', schema: 'contact', filter: { client: '@objectId' }, limit: 1 })
		expect(content.sort).toEqual({ field: 'mainContact', dir: 'desc' })
		// The record card's person chip: 40px, 16px initials on grey (nc-vue avatar.size 40).
		expect(content.avatar).toEqual({ text: '{name}', size: 40 })
		expect(content.title).toBe('{name}')
		expect(content.meta.parts).toEqual(['{role}'])
		expect(content.text).toBe('{email}')
		expect(part.components.schemas.contact.properties.mainContact.type).toBe('boolean')
	})

	it('links to the Contact persons tab by its id', () => {
		const tab = config.widgets.find((entry) => entry.id === 'client-panels').content.tabs
			.find((entry) => entry.widgetId === 'client-quick-contacts')
		expect(card.content.footerLink.href).toBe(`#${tab.id}`)
		expect(nl[card.content.footerLink.label]).toBe('Alle {total} contactpersonen')
		expect(nl[card.content.emptyText]).toBe('Nog geen contactpersonen')
	})

	it('gives Buurtvereniging Oost the two contact persons the board draws, Sanne de Vries as main contact', () => {
		const contacts = example.objects.filter((entry) => entry.schema === 'contact' && entry.data.client === '{{object:client-buurtoost.id}}')
		expect(contacts.map((entry) => entry.data.role)).toEqual(['Secretaris', 'Voorzitter'])
		expect(contacts.filter((entry) => entry.data.mainContact === true).map((entry) => entry.data.name)).toEqual(['{{cast:sanne.displayName}}'])
	})
})
