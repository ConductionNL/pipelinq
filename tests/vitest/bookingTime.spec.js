// SPDX-License-Identifier: EUPL-1.2
// Copyright (C) 2026 Conduction B.V.

/**
 * How an appointment's time reads on the PqAfspraken and PqAfspraak boards
 * (openspec/changes/screens-appointments-board): the list's time range and
 * the page's day plus time range. Dates are built in local time, so the
 * expected times hold in any time zone.
 */

import { describe, expect, it } from 'vitest'
import { bookingWhen } from '../../src/services/cellFormatters.js'
import { slotLabel, timeOfDay, timeRange } from '../../src/utils/bookingTime.js'

const start = new Date(2026, 9, 8, 14, 0).toISOString()
const end = new Date(2026, 9, 8, 15, 0).toISOString()

describe('timeOfDay', () => {
	it('prints hours and minutes with two digits', () => {
		expect(timeOfDay(new Date(2026, 9, 5, 8, 5).toISOString())).toBe('08:05')
	})

	it('is empty for no value and for a value that is not a date', () => {
		expect(timeOfDay('')).toBe('')
		expect(timeOfDay(null)).toBe('')
		expect(timeOfDay('not a date')).toBe('')
	})
})

describe('timeRange and bookingWhen', () => {
	it('joins start and end', () => {
		expect(timeRange(start, end)).toBe('14:00 to 15:00')
		expect(bookingWhen(start, { endAt: end })).toBe('14:00 to 15:00')
	})

	it('shows the start alone without an end', () => {
		expect(timeRange(start, '')).toBe('14:00')
		expect(bookingWhen(start, {})).toBe('14:00')
		expect(bookingWhen(start)).toBe('14:00')
	})

	it('is empty without a start', () => {
		expect(bookingWhen('', { endAt: end })).toBe('')
	})
})

describe('slotLabel', () => {
	it('names the day with a capital and adds the time range', () => {
		expect(slotLabel(start, end, 'nl-NL')).toBe('Donderdag 8 oktober, 14:00 to 15:00')
	})

	it('is empty without a start', () => {
		expect(slotLabel(null, end, 'nl-NL')).toBe('')
	})
})
