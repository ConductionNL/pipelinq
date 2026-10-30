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
import { describe, expect, it, vi } from 'vitest'

vi.mock('@nextcloud/l10n', () => ({ translate: (_app, text) => text }))
globalThis.t = (_app, text) => text
const { objectTypes } = await import('../../src/config/objectTypes.js')

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

describe('the object store', () => {
	it('registers both slugs, so the lists and the event page can fetch them', () => {
		const slugs = objectTypes().map((type) => type.slug)
		expect(slugs).toContain('campaignEvent')
		expect(slugs).toContain('eventRegistration')
	})
})

describe('the event page', () => {
	const detail = page(events.pages, 'CampaignEventDetail')

	it('opens on the event route', () => {
		expect(detail.route).toBe('/campaign-events/:id')
		expect(detail.config.schema).toBe('campaignEvent')
	})

	it('counts its registrations in a row across the page, never a number kept on the event', () => {
		const kpis = ['event-kpi-registered', 'event-kpi-waitlist', 'event-kpi-newsletter', 'event-kpi-new']
		expect(detail.config.band.map((item) => item.widgetId)).toEqual(kpis)
		expect(detail.config.band.map((item) => item.span)).toEqual([3, 3, 3, 3])
		for (const id of kpis) {
			const source = widget(detail, id).content.source
			expect(source.schema).toBe('eventRegistration')
			expect(source.metric).toBe('count')
			expect(source.filter.event).toBe('@objectId')
		}
	})

	it('has the Overview, Registrations, Registration form, Results and History tabs, with form, location and history beside them', () => {
		const tabs = widget(detail, 'event-panels').content.tabs
		expect(tabs.map((tab) => tab.label)).toEqual(['Overview', 'Registrations', 'Registration form', 'Results', 'History'])
		expect(tabs[1].countFrom).toEqual({ register: 'pipelinq', schema: 'eventRegistration', filter: { event: '@objectId' } })
		expect(detail.config.sideColumn).toEqual(['event-form', 'event-location', 'event-side-history'])
	})

	it('marks the page as an event with a purple type pill beside the status, as the board', () => {
		const pill = page(events.pages, 'CampaignEventDetail').config.typePill
		expect(pill).toEqual({ text: 'Campaign event', variant: 'purple' })
		const nl = read('l10n', 'nl.json').translations
		expect(nl[pill.text]).toBe('Evenement')
	})

	it('reads the places of the event under the registered count', () => {
		const tile = widget(page(events.pages, 'CampaignEventDetail'), 'event-kpi-registered')
		expect(tile.content.caption).toBe('of @object.capacity places')
		const nl = read('l10n', 'nl.json').translations
		expect(nl[tile.content.caption]).toBe('van @object.capacity plaatsen')
	})

	it('writes the day in full on the header line', () => {
		expect(detail.config.headerMeta).toBe('{startAt|fulldate}, {startAt|time} to {endAt|time} · {venue}')
		const recent = widget(detail, 'event-registrations-recent').content.columns.find((column) => column.key === 'status')
		expect(recent.widget).toBe('badge')
	})

	it('lists the registrations in the tab with a list page\'s toolbar, narrowed to this event', () => {
		const list = widget(detail, 'event-registrations')
		expect(list.type).toBe('index-list')
		expect(list.content.filter).toEqual({ event: '@objectId' })
		expect(list.content.quickFilters.map((lens) => lens.label)).toEqual(['All', 'Confirmed', 'Waiting list', 'Cancelled'])
		expect(list.content.viewModes).toEqual(['table', 'cards', 'board'])
		expect(list.content.searchPlaceholder).toBe('Search a name or street')
	})

	it('counts each registrations lens and offers the Filter button, as the Aanmeldingen tab draws them', () => {
		const list = widget(detail, 'event-registrations')
		for (const lens of list.content.quickFilters) {
			expect(lens.showCount, lens.label).toBe(true)
		}
		expect(list.content.filterMenu).toBe(true)
	})

	it('ends the registrations list with the event\'s whole count, however narrowed (PqEvenement "73 aanmeldingen in totaal")', () => {
		const list = widget(detail, 'event-registrations')
		// nextcloud-vue footer-unfiltered-total: read under the event filter only.
		expect(list.content.countValues).toEqual({ allRegistrations: { metric: 'count', unfiltered: true } })
		expect(list.content.footerNote).toBe('{allRegistrations} registrations in total')
		const nl = read('l10n', 'nl.json').translations
		expect(nl[list.content.footerNote]).toBe('{allRegistrations} aanmeldingen in totaal')
	})

	it('tones each registration status in the tab as the latest registrations do', () => {
		const recent = widget(detail, 'event-registrations-recent').content.columns.find((column) => column.key === 'status')
		const status = widget(detail, 'event-registrations').content.columns.find((column) => column?.key === 'status')
		expect(status).toMatchObject({ label: recent.label, widget: 'badge', widgetProps: { colorMap: recent.widgetProps.colorMap } })
		expect(recent.widgetProps.colorMap.confirmed).toBe('success')
	})

	it('sends the reminder and opens the check-in list from the header', () => {
		const actions = detail.config.headerActions
		const reminder = actions.find((action) => action.id === 'send-reminder')
		expect(reminder.url).toBe('/apps/pipelinq/api/campaign-events/@objectId/reminder')
		expect(reminder.confirm).toBe(true)
		expect(actions.find((action) => action.id === 'check-in').target).toBe('/campaign-events/@objectId/check-in')
		// Board PqEvenement draws both as buttons before Bewerken, not in Meer.
		expect(detail.config.quickActions).toEqual(['send-reminder', 'check-in'])
	})

	it('draws no add button or Actions menu in the registrations tab, as the board', () => {
		const list = widget(detail, 'event-registrations')
		expect(list.content.showAdd).toBe(false)
		expect(list.content.showActionsMenu).toBe(false)
	})

	it('counts who came and who did not on the Results tab', () => {
		expect(widget(detail, 'event-result-came').content.source.filter).toEqual({ event: '@objectId', status: 'confirmed', checkedInAt: 'IS NOT NULL' })
		expect(widget(detail, 'event-result-missed').content.source.filter).toEqual({ event: '@objectId', status: 'confirmed', checkedInAt: 'IS NULL' })
	})

	it('shows the registration form beside the tabs: the form, a link to its page, when it closes and where sign-ups land', () => {
		const form = widget(detail, 'event-form')
		expect(form.type).toBe('facts')
		expect(form.content.fields.map((field) => field.label)).toEqual(['Form', 'On page', 'Closes'])
		expect(form.content.fields[1]).toEqual({ label: 'On page', path: 'pageUrl', format: 'url' })
		expect(form.content.footnote).toBe('Built with portaliq; every sign-up arrives here as a registration.')
	})

	it('edits the form in portaliq, a button drawn only when the event names its form binding', () => {
		const [edit] = widget(detail, 'event-form').content.actions
		expect(edit).toMatchObject({ label: 'Edit form', type: 'navigate', target: '/index.php/apps/portaliq/form-bindings/@source.formBinding' })
		const schema = read('lib', 'Settings', 'register.d', '99-zz-event-reminder-and-check-in.json').components.schemas.campaignEvent
		expect(schema.version).toBe('1.2.0')
		expect(schema.properties.formBinding).toMatchObject({ type: 'string', format: 'uuid' })
		expect(register.components.schemas.campaignEvent.required).not.toContain('formBinding')
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

describe('the check-in list', () => {
	const checkIn = page(events.pages, 'EventCheckIn')

	it('lists the event\'s confirmed registrations, who is not in yet first', () => {
		expect(checkIn.route).toBe('/campaign-events/:id/check-in')
		expect(checkIn.config.filter).toEqual({ event: ':id', status: 'confirmed' })
		expect(checkIn.config.quickFilters[0]).toEqual({ label: 'Not in yet', filter: { checkedInAt: 'IS NULL' }, default: true })
	})

	it('checks someone in with one press, stamping the moment, and can undo it', () => {
		const [checkInAction, undo] = checkIn.config.actions
		expect(checkInAction.method).toBe('PATCH')
		expect(checkInAction.payload).toEqual({ checkedInAt: '@now' })
		expect(checkInAction.visibleWhen).toEqual({ field: 'checkedInAt', op: 'empty' })
		expect(undo.payload).toEqual({ checkedInAt: null })
	})
})
