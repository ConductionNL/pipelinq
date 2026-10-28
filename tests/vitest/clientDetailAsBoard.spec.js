/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * ClientDetail as PqKlant and PqOrganisatie draw it: one row of six figures
 * (label and value only), the tab strip under it in the boards' order and
 * words, sales in its own tab instead of the side column.
 */

import fs from 'fs'
import path from 'path'
import { describe, expect, it } from 'vitest'

const ROOT = path.resolve(__dirname, '../..')
const manifest = JSON.parse(fs.readFileSync(path.join(ROOT, 'src/manifest.json'), 'utf8'))
const nl = JSON.parse(fs.readFileSync(path.join(ROOT, 'l10n/nl.json'), 'utf8')).translations
const config = manifest.pages.find((page) => page.id === 'ClientDetail').config
const widget = (id) => config.widgets.find((entry) => entry.id === id)

describe('ClientDetail as the boards draw it', () => {
	it('lays the six figures out in one row above the tabs', () => {
		const row = config.layout.filter((item) => item.gridY === 0).map((item) => item.widgetId)
		expect(row).toEqual([
			'client-summary-open-tickets',
			'client-summary-new-requests',
			'client-summary-sla-breached',
			'client-summary-sla-at-risk',
			'client-summary-open-leads',
			'client-summary-last-activity',
		])
		expect(config.layout.every((item) => item.gridY > 0 || item.gridWidth === 2)).toBe(true)
		expect(config.layout.find((item) => item.widgetId === 'client-panels').gridY).toBe(1)
	})

	it('draws each figure as its label and value, in the boards\' words', () => {
		const labels = ['client-summary-open-tickets', 'client-summary-new-requests', 'client-summary-sla-breached', 'client-summary-sla-at-risk', 'client-summary-open-leads', 'client-summary-last-activity']
			.map((id) => widget(id).content)
		expect(labels.every((content) => content.caption === undefined)).toBe(true)
		expect(labels.map((content) => nl[content.label])).toEqual(['Openstaande zaken', 'Nieuwe verzoeken', 'Termijn verstreken', 'Termijn in gevaar', 'Open leads', 'Laatste activiteit'])
		expect(widget('client-summary-last-activity').content.format).toEqual({ style: 'date' })
	})

	it('orders the tabs as the boards do, contact persons for an organisation and sales for a resident', () => {
		const tabs = widget('client-panels').content.tabs
		expect(tabs.map((tab) => nl[tab.label])).toEqual(['Overzicht', 'Zaken', 'Tickets', 'Contactpersonen', 'Contactmomenten', 'Verkoop', 'Documenten', 'Geschiedenis'])
		expect(tabs[3].visibleWhen).toEqual({ field: 'type', op: 'eq', value: 'organization' })
		expect(tabs[5].visibleWhen).toEqual({ field: 'type', op: 'eq', value: 'person' })
	})

	it('keeps the sales figures and the files out of the side column', () => {
		expect(config.sideColumn).not.toContain('client-kpis-sales')
		expect(config.sideColumn).not.toContain('client-files')
	})
})

describe('ClientDetail overview as the boards draw it', () => {
	it('opens on the resident or organisation card, the tickets and the latest contact moments', () => {
		const overview = widget('client-panels').content.tabs[0]
		expect(overview.widgetIds).toEqual(['client-person-card', 'client-organisation-card', 'client-cases', 'client-tickets', 'client-latest-moments'])
		expect(widget('client-person-card').visibleWhen).toEqual({ field: 'type', op: 'eq', value: 'person' })
		expect(widget('client-organisation-card').visibleWhen).toEqual({ field: 'type', op: 'eq', value: 'organization' })
		expect(widget('client-person-card').content.fields.map((field) => nl[field.label])).toEqual(['Naam', 'Voornamen', 'Achternaam', 'Geboortedatum', 'Woonadres', 'Omschrijving'])
		expect(widget('client-organisation-card').content.fields.map((field) => nl[field.label])).toEqual(['Handelsnaam', 'KVK-nummer', 'Rechtsvorm', 'Adres'])
	})

	it('draws the tickets as ruled rows with a pill and the date at the end', () => {
		const content = widget('client-tickets').content
		expect(content.presentation).toBe('rows')
		expect(content.trailing).toEqual(['Done {resolvedAt|date}', 'By {slaDeadline|date}'])
		expect(nl[content.headerLink.label]).toBe('Alle {total} tickets bekijken')
	})

	it('draws the latest contact moments as a feed', () => {
		const content = widget('client-latest-moments').content
		expect(content.presentation).toBe('feed')
		expect(content.limit).toBe(3)
		expect(nl[content.headerNote]).toBe('{total} in totaal')
	})

	it('offers Log contact and New request for this client in the header', () => {
		const actions = config.headerActions.map((action) => action.id)
		expect(actions.slice(0, 2)).toEqual(['log-contact', 'new-request'])
		expect(config.headerActions[1].props).toEqual({ clientId: '@objectId' })
	})
})

describe('ClientDetail side column as the boards draw it', () => {
	const ids = config.sideColumn.map((card) => (typeof card === 'string' ? card : card.id))
	const card = (id) => config.sideColumn.find((entry) => typeof entry === 'object' && entry.id === id)

	it('keeps the boards\' order: resident cards, organisation cards, then the shared ones', () => {
		expect(ids.slice(0, 9)).toEqual(['side-reach', 'side-identity', 'side-address', 'side-kvk', 'side-main-contact', 'side-open-case', 'side-open-ticket', 'client-channels', 'client-relationships'])
	})

	it('shows the resident cards for a resident and the organisation cards for an organisation', () => {
		for (const id of ['side-reach', 'side-identity', 'side-address']) {
			expect(card(id).visibleWhen).toEqual({ field: 'type', op: 'eq', value: 'person' })
		}
		for (const id of ['side-kvk', 'side-main-contact', 'side-open-ticket']) {
			expect(card(id).visibleWhen).toEqual({ field: 'type', op: 'eq', value: 'organization' })
		}
	})

	it('titles the cards in the boards\' words', () => {
		expect(['side-reach', 'side-identity', 'side-address', 'side-kvk', 'side-open-ticket', 'side-history'].map((id) => nl[card(id).title]))
			.toEqual(['Bereikbaar via', 'Identiteit', 'Woonadres', 'KVK en adres', 'Open tickets', 'Geschiedenis'])
		expect(config.recordLastChange).toBe(true)
	})
})
