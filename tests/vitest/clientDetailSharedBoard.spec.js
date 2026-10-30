/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * The parts boards PqKlant and PqOrganisatie share on ClientDetail: Log
 * contact and New request as header buttons, the contact strip and the seven
 * figures in a band across the page (the side column starts at the tabs),
 * the type pill in teal, and the contact channels and relations compact in
 * the side column.
 *
 * @spec openspec/changes/client-page-as-the-relation-boards/specs/client-detail/spec.md
 */

import fs from 'fs'
import path from 'path'
import { describe, expect, it } from 'vitest'

const ROOT = path.resolve(__dirname, '../..')
const manifest = JSON.parse(fs.readFileSync(path.join(ROOT, 'src/manifest.json'), 'utf8'))
const registrySource = fs.readFileSync(path.join(ROOT, 'src/registry.js'), 'utf8')
const config = manifest.pages.find((page) => page.id === 'ClientDetail').config
const widget = (id) => config.widgets.find((entry) => entry.id === id)

const FIGURES = [
	'client-summary-open-tickets',
	'client-summary-new-requests',
	'client-summary-sla-breached',
	'client-summary-sla-at-risk',
	'client-summary-open-leads',
	'client-summary-last-activity',
	'client-summary-churn-risk',
]

describe('ClientDetail parts both client boards share', () => {
	it('shows Log contact and New request as header buttons', () => {
		expect(config.quickActions).toEqual(['log-contact', 'new-request'])
		const ids = config.headerActions.map((action) => action.id)
		expect(ids).toContain('log-contact')
		expect(ids).toContain('new-request')
	})

	it('draws the contact strip and the seven figures across the page, above the tabs and the side column', () => {
		expect(config.band[0]).toEqual({ widgetId: 'client-contact-strip', span: 12, chrome: 'bare' })
		expect(config.band.slice(1)).toEqual(FIGURES.map((widgetId) => ({ widgetId, span: 2 })))
		expect(widget('client-contact-strip').type).toBe('PartyContactStripWidget')
		expect(config.layout.map((item) => item.widgetId)).toEqual(['client-panels'])
		expect(config.layout[0].gridY).toBe(0)
		expect((config.bodyWidgets || []).map((entry) => entry.component)).not.toContain('PartyContactStrip')
	})

	it('prints each figure once: the tile draws its label, so the card carries no title', () => {
		FIGURES.forEach((id) => {
			expect(widget(id).title).toBeUndefined()
			expect(typeof widget(id).content.label).toBe('string')
		})
	})

	it('registers the contact strip as a widget the band can place', () => {
		expect(registrySource).toMatch(/PartyContactStripWidget:\s*\{\s*kind: 'widget'/)
	})

	it('draws the resident pill in teal, as the boards do', () => {
		expect(config.typePill.colorMap).toEqual({ person: 'teal', organization: 'teal' })
	})

	it('lists the contact channels and relations compactly in the side column', () => {
		expect(config.sideColumn).toContain('client-channels')
		expect(config.sideColumn).toContain('client-relationships')
		expect(widget('client-channels').content.compact).toBe(true)
		expect(widget('client-relationships').content.compact).toBe(true)
	})
})

describe('ClientDetail side column order', () => {
	it('ends as the boards do: history, e-mails, notes, the knowledge base, then related', () => {
		expect(config.sideColumnOrder).toBe('declared')
		const ids = config.sideColumn.map((entry) => (typeof entry === 'string' ? entry : entry.id))
		expect(ids.slice(-5)).toEqual(['side-history', 'client-email', 'client-notes', 'side-knowledge', 'client-related'])
	})
})
