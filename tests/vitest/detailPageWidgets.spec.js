/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Detail page widgets say their title once, fit their content and name
 * their records (pipelinq audit 7 October: E2, booking timeline, line items).
 *
 * @spec openspec/changes/review-audit-fixes-b/specs/detail-pages/spec.md
 */

import { describe, expect, it } from 'vitest'
import bookingFragment from '../../src/manifest.d/80-appointment-booking-admin.json'
import manifest from '../../src/manifest.json'

/**
 * A page by id from the bundled manifest or the booking fragment.
 *
 * @param {string} id The page id.
 * @return {object} The page.
 */
function page(id) {
	return [...manifest.pages, ...bookingFragment.pages].find((p) => p.id === id)
}

/**
 * The layout item for a widget id.
 *
 * @param {object} p The page.
 * @param {string} widgetId The widget id.
 * @return {object} The layout item.
 */
function layoutItem(p, widgetId) {
	return p.config.layout.find((l) => l.widgetId === widgetId)
}

/**
 * The widget definition by id.
 *
 * @param {object} p The page.
 * @param {string} id The widget id.
 * @return {object} The widget.
 */
function widget(p, id) {
	return p.config.widgets.find((w) => w.id === id)
}

/**
 * Whether two grid items overlap.
 *
 * @param {object} a Item.
 * @param {object} b Item.
 * @return {boolean}
 */
function overlaps(a, b) {
	return (
		a.gridX < b.gridX + b.gridWidth
		&& b.gridX < a.gridX + a.gridWidth
		&& a.gridY < b.gridY + b.gridHeight
		&& b.gridY < a.gridY + a.gridHeight
	)
}

describe('contact detail', () => {
	const contact = page('ContactDetail')

	it('shows the open deals title once', () => {
		// A side card: the card draws the title, the stat draws none.
		expect(contact.config.sideColumn).toContain('contact-kpis-deals')
		expect(widget(contact, 'contact-kpis-deals').content.label).toBeUndefined()
	})

	it('has no button floating below the grid', () => {
		expect(contact.config.relationLinks).toBeUndefined()
	})

	it('puts the related records in the side column, not in the grid', () => {
		expect(contact.config.sideColumn).toContain('contact-related')
		expect(contact.config.layout.map((item) => item.widgetId)).toEqual([
			'contact-panels',
		])
	})

	it('opens on Overview and ends on History (board PqContactpersoon)', () => {
		const tabs = widget(contact, 'contact-panels').content.tabs
		expect(tabs[0].widgetId).toBe('contact-overview')
		expect(tabs[tabs.length - 1].widgetId).toBe('contact-history')
	})

	it('shows channels and relationships in Overview and as tabs, not as side cards', () => {
		const sections = widget(contact, 'contact-overview').content.sections
		expect(sections.map((s) => s.widget?.type).filter(Boolean)).toEqual(['ContactRelationshipsWidget', 'ContactChannelsWidget'])
		const tabIds = widget(contact, 'contact-panels').content.tabs.map((t) => t.widgetId)
		expect(tabIds).toEqual(expect.arrayContaining(['contact-channels', 'contact-relationships']))
		expect(contact.config.sideColumn).not.toContain('contact-channels')
	})
})

describe('lead detail', () => {
	const lead = page('LeadDetail')

	it('draws the board: one tab strip as the body and a side column of cards', () => {
		expect(lead.config.layout.map((item) => item.widgetId)).toEqual([
			'lead-panels',
		])
		expect(lead.config.sideColumn).toEqual([
			'side-client',
			'side-suggested',
			'side-win-chance',
			'side-next-step',
			'side-related',
		])
		const overview = widget(lead, 'lead-panels').content.tabs[0]
		expect(overview.widgetIds).toEqual([
			'lead-stages',
			'lead-deal',
			'lead-lines',
			'lead-contacts',
			'lead-flow-runs',
		])
	})

	it('puts logging a visit and the two close buttons in the header', () => {
		expect(lead.config.quickActions).toEqual(['log-contact', 'close-won', 'close-lost'])
		expect(lead.actionsComponent).toBe('LogVisitAction')
		const won = lead.config.headerActions.find((a) => a.id === 'close-won')
		expect(won.payload).toEqual({ action: 'win' })
		expect(won.visibleWhen).toEqual({ field: 'status', op: 'eq', value: 'open' })
	})

	it('names the product of a line item instead of its uuid', () => {
		const column = widget(lead, 'lead-lines').content.columns.find(
			(c) => c.key === 'product',
		)
		expect(column.widget).toBe('fkResolve')
		expect(column.widgetProps).toMatchObject({
			schema: 'product',
			labelField: 'name',
		})
	})
})

describe('booking detail', () => {
	const booking = page('BookingDetail')

	it('does not repeat Timeline as a heading inside its tab', () => {
		expect(widget(booking, 'booking-timeline').content.title).toBeUndefined()
	})

	it('gives the tab strip room for the whole timeline', () => {
		expect(
			layoutItem(booking, 'booking-panels').gridHeight,
		).toBeGreaterThanOrEqual(10)
	})
})

describe('layouts', () => {
	it.each(['ContactDetail', 'LeadDetail', 'BookingDetail'])(
		'%s has no overlapping widgets',
		(id) => {
			const items = page(id).config.layout
			for (let i = 0; i < items.length; i++) {
				for (let j = i + 1; j < items.length; j++) {
					expect(
						overlaps(items[i], items[j]),
						`${items[i].widgetId} / ${items[j].widgetId}`,
					).toBe(false)
				}
			}
		},
	)
})
