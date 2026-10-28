/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 * SPDX-License-Identifier: EUPL-1.2
 *
 * A resource's load this week: how many bookings it carries and what share
 * of its bookable time they fill. Bookable time is the working hours of each
 * weekday, minus the days that fall in a vacation.
 *
 * @module utils/resourceWeek
 */

const WEEKDAYS = ['sunday', 'monday', 'tuesday', 'wednesday', 'thursday', 'friday', 'saturday']

const CANCELLED = new Set(['cancelled-by-customer', 'cancelled-by-business', 'rescheduled'])

/**
 * Monday 00:00 (local time) of the week that holds `now`, and the Monday after.
 *
 * @param {Date} now The moment inside the week.
 * @return {{start: Date, end: Date}}
 * @spec openspec/changes/screens-inloop-resource-board/specs/appointment-booking/spec.md
 */
export function weekBounds(now) {
	const start = new Date(now.getFullYear(), now.getMonth(), now.getDate())
	const offset = (start.getDay() + 6) % 7
	start.setDate(start.getDate() - offset)
	const end = new Date(start)
	end.setDate(end.getDate() + 7)
	return { start, end }
}

/**
 * Minutes between two "HH:MM" times, or 0 when either is missing or the
 * closing time is not after the opening time.
 *
 * @param {string} open Opening time.
 * @param {string} close Closing time.
 * @return {number}
 */
function minutesBetween(open, close) {
	const toMinutes = (value) => {
		const match = /^(\d{1,2}):(\d{2})/.exec(String(value || ''))
		return match ? Number(match[1]) * 60 + Number(match[2]) : null
	}
	const from = toMinutes(open)
	const to = toMinutes(close)
	return from !== null && to !== null && to > from ? to - from : 0
}

/**
 * The date part ("YYYY-MM-DD") of a local date.
 *
 * @param {Date} date The date.
 * @return {string}
 */
function isoDay(date) {
	const pad = (n) => String(n).padStart(2, '0')
	return `${date.getFullYear()}-${pad(date.getMonth() + 1)}-${pad(date.getDate())}`
}

/**
 * The resource's bookings this week and the share of its bookable time
 * they fill.
 *
 * @param {object} options The inputs.
 * @param {Array<object>} options.bookings Bookings, any resource; only this resource's count.
 * @param {string} options.resourceId The resource.
 * @param {Array<{day: string, openTime: string, closeTime: string}>} options.workingHours Hours per weekday.
 * @param {Array<{startDate: string, endDate: string}>} options.vacations Periods the resource cannot be booked.
 * @param {Date} options.now A moment inside the week.
 * @return {{count: number, percent: (number|null)}} `percent` is null when the week has no bookable time.
 * @spec openspec/changes/screens-inloop-resource-board/specs/appointment-booking/spec.md
 */
export function resourceWeekLoad({ bookings = [], resourceId, workingHours = [], vacations = [], now = new Date() }) {
	const { start, end } = weekBounds(now)

	let bookable = 0
	for (let i = 0; i < 7; i++) {
		const day = new Date(start)
		day.setDate(day.getDate() + i)
		const iso = isoDay(day)
		const away = vacations.some((v) => v && v.startDate && iso >= String(v.startDate).slice(0, 10) && iso <= String(v.endDate || v.startDate).slice(0, 10))
		if (away) {
			continue
		}
		for (const row of workingHours) {
			if (row && row.day === WEEKDAYS[day.getDay()]) {
				bookable += minutesBetween(row.openTime, row.closeTime)
			}
		}
	}

	let count = 0
	let booked = 0
	for (const booking of bookings) {
		if (!booking || CANCELLED.has(booking.status)) {
			continue
		}
		const assigned = (booking.resourceAssignments || []).some((a) => a && a.resourceId === resourceId)
		const from = new Date(booking.startAt)
		if (!assigned || Number.isNaN(from.getTime()) || from < start || from >= end) {
			continue
		}
		count++
		const to = new Date(booking.endAt)
		if (!Number.isNaN(to.getTime()) && to > from) {
			booked += (to - from) / 60000
		}
	}

	return {
		count,
		percent: bookable > 0 ? Math.round((booked / bookable) * 100) : null,
	}
}
