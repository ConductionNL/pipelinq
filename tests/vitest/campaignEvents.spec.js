/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 * SPDX-License-Identifier: EUPL-1.2
 *
 * A campaign's events and their registrations, as the PqEvenement board draws
 * one event.
 *
 * @spec openspec/changes/campaign-events/specs/marketing-campaigns/spec.md
 */

import { readFileSync } from 'node:fs'
import { resolve } from 'node:path'
import { describe, expect, it } from 'vitest'

const read = (...parts) => JSON.parse(readFileSync(resolve(__dirname, '../..', ...parts), 'utf8'))
const register = read('lib', 'Settings', 'register.d', '98-campaign-events.json')
const events = read('src', 'manifest.d', '78-campaign-events.json')
const campaigns = read('src', 'manifest.d', '78-marketing-campaigns.json')
const page = (pages, id) => pages.find((entry) => entry.id === id)
const widget = (p, id) => p.config.widgets.find((entry) => entry.id === id)

describe('the schemas', () => {
	const schemas = register.components.schemas

	it('adds the event and the registration to the pipelinq register', () => {
		expect(register.components.registers.pipelinq.schemas).toEqual(['campaignEvent', 'eventRegistration'])
	})

	it('ties an event to its campaign and a registration to its event', () => {
		expect(schemas.campaignEvent.properties.campaign.$ref).toBe('campaign')
		expect(schemas.eventRegistration.properties.event.$ref).toBe('campaignEvent')
	})

	it('keeps a registration confirmed, waiting or cancelled', () => {
		expect(schemas.eventRegistration.properties.status.enum).toEqual(['confirmed', 'waitlist', 'cancelled'])
	})
})

describe('the event page', () => {
	const detail = page(events.pages, 'CampaignEventDetail')

	it('opens on the event route', () => {
		expect(detail.route).toBe('/campaign-events/:id')
		expect(detail.config.schema).toBe('campaignEvent')
	})

	it('counts its registrations, never a number kept on the event', () => {
		const kpis = ['event-kpi-registered', 'event-kpi-waitlist', 'event-kpi-newsletter', 'event-kpi-new']
		expect(detail.config.layout.slice(0, 4).map((item) => item.widgetId)).toEqual(kpis)
		for (const id of kpis) {
			const source = widget(detail, id).content.source
			expect(source.schema).toBe('eventRegistration')
			expect(source.metric).toBe('count')
			expect(source.filter.event).toBe('@objectId')
		}
		expect(widget(detail, 'event-kpi-registered').content.caption).toBe('of {capacity} places')
	})

	it('has the Overview, Registrations and History tabs, with form, location and history beside them', () => {
		expect(widget(detail, 'event-panels').content.tabs.map((tab) => tab.label)).toEqual(['Overview', 'Registrations', 'History'])
		expect(detail.config.sideColumn).toEqual(['event-form', 'event-location', 'event-side-history'])
	})
})

describe('the lists', () => {
	it('lists the registrations with the board lenses and a board by status', () => {
		const list = page(events.pages, 'EventRegistrations')
		expect(list.config.quickFilters.map((lens) => lens.label)).toEqual(['All', 'Confirmed', 'Waiting list', 'Cancelled'])
		expect(list.config.board.statusField).toBe('status')
	})

	it('lists a campaign\'s events on its Overview tab', () => {
		const detail = page(campaigns.pages, 'CampaignDetail')
		expect(widget(detail, 'campaign-panels').content.tabs[0].widgetIds).toContain('campaign-events')
		const card = widget(detail, 'campaign-events')
		expect(card.content.filter).toEqual({ campaign: '@objectId' })
		expect(card.content.rowRoute).toBe('CampaignEventDetail')
	})
})
