/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 * SPDX-License-Identifier: EUPL-1.2
 *
 * The "This week" card on a resource (pl2 PqResource): the bookings this
 * resource carries this week and the share of its bookable time they fill.
 */
import { describe, expect, it } from 'vitest'
import { resourceWeekLoad, weekBounds } from '../../src/utils/resourceWeek.js'

// Wednesday 21 October 2026, so the week runs Monday 19 to Sunday 25.
const now = new Date(2026, 9, 21, 10, 0)
const at = (day, hour, minute = 0) => new Date(2026, 9, day, hour, minute).toISOString()

const workingHours = [
	{ day: 'monday', openTime: '08:30', closeTime: '17:00' },
	{ day: 'tuesday', openTime: '08:30', closeTime: '17:00' },
	{ day: 'wednesday', openTime: '08:30', closeTime: '12:30' },
	{ day: 'thursday', openTime: '08:30', closeTime: '20:00' },
	{ day: 'friday', openTime: '08:30', closeTime: '12:30' },
]

function booking (startAt, endAt, extra = {}) {
  return {
	startAt,
	endAt,
	status: 'confirmed',
	resourceAssignments: [{ resourceId: 'fatma' }],
	...extra,
}
}

describe('a resource\'s load this week', () => {
	it('runs the week from Monday to the next Monday', () => {
		const { start, end } = weekBounds(now)
		expect(start).toEqual(new Date(2026, 9, 19))
		expect(end).toEqual(new Date(2026, 9, 26))
	})

	it('counts this resource\'s bookings this week and their share of the bookable time', () => {
		const bookings = [
			booking(at(19, 9), at(19, 13)),
			booking(at(20, 9), at(20, 10)),
			booking(at(21, 9), at(21, 10), { resourceAssignments: [{ resourceId: 'someone-else' }] }),
			booking(at(22, 9), at(22, 10), { status: 'cancelled-by-customer' }),
			booking(at(26, 9), at(26, 10)),
		]
		// Bookable: 510 + 510 + 240 + 690 + 240 = 2190 minutes; booked 300.
		expect(resourceWeekLoad({ bookings, resourceId: 'fatma', workingHours, vacations: [], now }))
			.toEqual({ count: 2, percent: 14 })
	})

	it('leaves vacation days out of the bookable time', () => {
		const bookings = [booking(at(19, 9), at(19, 13))]
		const vacations = [{ startDate: '2026-10-20', endDate: '2026-10-23' }]
		// Only Monday is left: 240 of 510 minutes.
		expect(resourceWeekLoad({ bookings, resourceId: 'fatma', workingHours, vacations, now }))
			.toEqual({ count: 1, percent: 47 })
	})

	it('has no share when the week holds no bookable time', () => {
		expect(resourceWeekLoad({ bookings: [], resourceId: 'fatma', workingHours: [], vacations: [], now }))
			.toEqual({ count: 0, percent: null })
	})
})
