// SPDX-License-Identifier: EUPL-1.2
// Copyright (C) 2026 Conduction B.V.

/**
 * Unit tests for src/services/serviceSteps.js and the booking page manifest
 * (openspec/changes/booking-and-service-pages, pipelinq review E5-E7).
 */

import { describe, expect, it } from 'vitest'
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
