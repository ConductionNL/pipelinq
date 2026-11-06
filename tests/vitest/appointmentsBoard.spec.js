// SPDX-License-Identifier: EUPL-1.2
// Copyright (C) 2026 Conduction B.V.

/**
 * The appointments list and page as the PqAfspraken and PqAfspraak boards
 * draw them (openspec/changes/screens-appointments-board).
 */

import { describe, expect, it } from 'vitest'
import overlay from '../../lib/Settings/register.d/99-zz-appointment-board.json'
import bookings from '../../src/manifest.d/80-appointment-booking-admin.json'

const page = (id) => bookings.pages.find((p) => p.id === id)
const booking = overlay.components.schemas.appointmentBooking

describe('the appointments list', () => {
	const config = page('Bookings').config

	it('has the board chips, Mine on the handler', () => {
		expect(config.quickFilters.map((q) => q.label)).toEqual(['Today', 'This week', 'Mine', 'Unconfirmed'])
		expect(config.quickFilters.find((q) => q.label === 'Mine').filter).toEqual({ assignee: '@me' })
		expect(config.quickFilters.every((q) => q.showCount)).toBe(true)
	})

	it('reads the board columns from fields the booking has', () => {
		expect(config.columns.map((c) => c.label)).toEqual(['When', 'With whom', 'About', 'Location or desk', 'Handler', 'Status'])
		for (const key of ['subject', 'location', 'assignee']) {
			expect(booking.properties[key], key).toBeDefined()
		}
	})
})

describe('the appointments list (pl2 PqAfspraken)', () => {
	const config = page('Bookings').config

	it('offers the board\'s Filter button, a menu of the status values', () => {
		expect(config.filterMenu).toBe(true)
		expect(config.columns.find((c) => c.key === 'status').widget).toBe('badge')
	})

	it('draws the handler as the board\'s small grey initials chip', () => {
		const handler = config.columns.find((c) => c.key === 'assignee')
		expect(handler.widget).toBe('avatar')
		expect(handler.widgetProps).toEqual({ user: true, size: 28, tone: 'neutral' })
	})
})

describe('the booking register overlay', () => {
	it('labels every status and source value', () => {
		expect(Object.keys(booking.properties.status['x-enum-labels'])).toHaveLength(7)
		expect(Object.keys(booking.properties.source['x-enum-labels'])).toHaveLength(5)
	})

	it('keeps the new fields optional', () => {
		expect(booking.required).toBeUndefined()
	})
})

describe('the appointment page', () => {
	const config = page('BookingDetail').config

	it('sits on the relation frame with the customer, when-and-where and the linked request beside it', () => {
		expect(config.breadcrumb.route).toBe('Bookings')
		expect(config.sideColumn).toEqual(['booking-context', 'booking-when', 'booking-request'])
		expect(config.layout.map((l) => l.widgetId)).toEqual(['booking-panels'])
	})
})
