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
	const week = weekBounds(now)
	const bookable = bookableMinutes(week.start, { workingHours, vacations })
	const { count, booked } = bookedInWeek(bookings, { resourceId, week })
	return {
		count,
		percent: sharePercent(booked, bookable),
	}
}

/**
 * @param {number} booked The booked minutes.
 * @param {number} bookable The bookable minutes.
 * @return {(number|null)} The booked share as a whole percentage; null without bookable time.
 */
function sharePercent(booked, bookable) {
	return bookable > 0 ? Math.round((booked / bookable) * 100) : null
}

/**
 * The bookable minutes of the week: the working hours of each day the
 * resource is not away.
 *
 * @param {Date} start The Monday the week starts on.
 * @param {{workingHours: Array<object>, vacations: Array<object>}} availability The hours per weekday and the vacations.
 * @return {number} The minutes.
 */
function bookableMinutes(start, availability) {
	let bookable = 0
	for (let i = 0; i < 7; i++) {
		const day = new Date(start)
		day.setDate(day.getDate() + i)
		if (isAway(isoDay(day), availability.vacations)) {
			continue
		}
		bookable += dayMinutes(availability.workingHours, WEEKDAYS[day.getDay()])
	}
	return bookable
}

/**
 * Whether a vacation covers the day; one without an end covers its start day.
 *
 * @param {string} iso The day, YYYY-MM-DD.
 * @param {Array<object>} vacations The vacations.
 * @return {boolean} Whether the resource is away.
 */
function isAway(iso, vacations) {
	return vacations.some((v) => v && v.startDate && iso >= String(v.startDate).slice(0, 10) && iso <= String(v.endDate || v.startDate).slice(0, 10))
}

/**
 * The working minutes of a weekday, over every row for that day.
 *
 * @param {Array<object>} workingHours The hours per weekday.
 * @param {string} weekday The weekday name.
 * @return {number} The minutes.
 */
function dayMinutes(workingHours, weekday) {
	let minutes = 0
	for (const row of workingHours) {
		if (row && row.day === weekday) {
			minutes += minutesBetween(row.openTime, row.closeTime)
		}
	}
	return minutes
}

/**
 * The resource's bookings that start in the week, and the minutes they last.
 *
 * @param {Array<object>} bookings Bookings, any resource.
 * @param {{resourceId: string, week: {start: Date, end: Date}}} scope The resource and the week.
 * @return {{count: number, booked: number}} The count and the minutes.
 */
function bookedInWeek(bookings, scope) {
	let count = 0
	let booked = 0
	for (const booking of bookings) {
		if (!isLiveBooking(booking) || !isAssignedTo(booking, scope.resourceId) || !startsIn(booking, scope.week)) {
			continue
		}
		count++
		booked += bookingMinutes(booking)
	}
	return { count, booked }
}

/**
 * @param {?object} booking The booking.
 * @return {boolean} Whether it is a booking that was not cancelled or moved.
 */
function isLiveBooking(booking) {
	return Boolean(booking) && !CANCELLED.has(booking.status)
}

/**
 * @param {object} booking The booking.
 * @param {string} resourceId The resource.
 * @return {boolean} Whether the resource is assigned to it.
 */
function isAssignedTo(booking, resourceId) {
	return (booking.resourceAssignments || []).some((a) => a && a.resourceId === resourceId)
}

/**
 * @param {object} booking The booking.
 * @param {{start: Date, end: Date}} week The week.
 * @return {boolean} Whether it starts inside the week.
 */
function startsIn(booking, week) {
	const from = new Date(booking.startAt)
	return !Number.isNaN(from.getTime()) && from >= week.start && from < week.end
}

/**
 * @param {object} booking The booking.
 * @return {number} Its length in minutes; zero without a usable end.
 */
function bookingMinutes(booking) {
	const from = new Date(booking.startAt)
	const to = new Date(booking.endAt)
	if (!Number.isNaN(to.getTime()) && to > from) {
		return (to - from) / 60000
	}
	return 0
}
