// SPDX-License-Identifier: EUPL-1.2
// Copyright (C) 2026 Conduction B.V.

/**
 * Unit tests for src/services/serviceSteps.js and the booking page manifest
 * (openspec/changes/booking-and-service-pages, pipelinq review E5-E7; the
 * library timeline widget: openspec/changes/review-part-two).
 */

import { describe, expect, it } from 'vitest'
import bookingRegister from '../../lib/Settings/register.d/45-appointment-booking.json'
import bookings from '../../src/manifest.d/80-appointment-booking-admin.json'
import {
	STEP_UNITS,
	stepAmount,
	unitLabel,
} from '../../src/services/serviceSteps.js'

describe('serviceSteps', () => {
	it('labels every stored unit', () => {
		for (const unit of STEP_UNITS) {
			expect(unitLabel(unit)).not.toBe(unit)
		}
		expect(unitLabel('weird')).toBe('weird')
	})

	it('shows a quantity with its unit, and nothing without a quantity', () => {
		expect(stepAmount({ quantity: 8, unit: 'hour' })).toBe('8 hours')
		expect(stepAmount({ quantity: 1 })).toBe('1')
		expect(stepAmount({ unit: 'month' })).toBe('')
		expect(stepAmount(null)).toBe('')
	})
})

describe('the booking page', () => {
	const page = bookings.pages.find((p) => p.id === 'BookingDetail')
	const widget = (id) => page.config.widgets.find((w) => w.id === id)

	it('uses tabs with the notes leaf and no body section', () => {
		const tabs = widget('booking-tabs').content.tabs.map((t) => t.widgetId)
		expect(tabs).toEqual([
			'booking-timeline',
			'booking-assignments',
			'booking-notes',
			'booking-files',
		])
		expect(widget('booking-notes')).toMatchObject({
			type: 'integration',
			integrationId: 'notes',
		})
		expect(page.config.bodyWidgets).toBeUndefined()
	})

	it('shows the timeline through the library timeline widget', () => {
		const timeline = widget('booking-timeline')
		expect(timeline.type).toBe('timeline')
		const fields = timeline.content.fields.map((f) => f.field)
		for (const field of [
			'@self.created',
			'depositPaidAt',
			'confirmationSentAt',
			'startAt',
			'endAt',
		]) {
			expect(fields).toContain(field)
		}
		// Status changes come from the audit trail.
		expect(timeline.content.auditTrail).toBe(true)
		// Every date field exists on the booking schema.
		const schema =
			bookingRegister.components.schemas.appointmentBooking.properties
		for (const field of fields.filter((f) => !f.startsWith('@self.'))) {
			expect(schema[field]?.format).toBe('date-time')
		}
	})

	it('keeps tab children out of the grid', () => {
		const placed = page.config.layout.map((l) => l.widgetId)
		for (const id of [
			'booking-timeline',
			'booking-assignments',
			'booking-notes',
			'booking-files',
		]) {
			expect(placed).not.toContain(id)
		}
	})
})
