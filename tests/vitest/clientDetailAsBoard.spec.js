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
		expect(tabs.map((tab) => nl[tab.label])).toEqual(['Overzicht', 'Tickets', 'Contactpersonen', 'Contactmomenten', 'Verkoop', 'Documenten', 'Geschiedenis'])
		expect(tabs[2].visibleWhen).toEqual({ field: 'type', op: 'eq', value: 'organization' })
		expect(tabs[4].visibleWhen).toEqual({ field: 'type', op: 'eq', value: 'person' })
	})

	it('keeps the sales figures and the files out of the side column', () => {
		expect(config.sideColumn).not.toContain('client-kpis-sales')
		expect(config.sideColumn).not.toContain('client-files')
	})
})
