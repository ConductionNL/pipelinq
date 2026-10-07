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
		expect(layoutItem(contact, 'contact-kpis-deals').showTitle).toBe(false)
		expect(widget(contact, 'contact-kpis-deals').content.label).toBe(
			'Open deals',
		)
	})

	it('has no button floating below the grid', () => {
		expect(contact.config.relationLinks).toBeUndefined()
	})

	it('gives the related widget room for its items', () => {
		expect(
			layoutItem(contact, 'contact-related').gridHeight,
		).toBeGreaterThanOrEqual(5)
	})
})

describe('lead detail', () => {
	const lead = page('LeadDetail')

	it('shows the deal value and line item titles once', () => {
		expect(layoutItem(lead, 'lead-kpi-value').showTitle).toBe(false)
		expect(layoutItem(lead, 'lead-kpi-lines').showTitle).toBe(false)
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
			layoutItem(booking, 'booking-tabs').gridHeight,
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
