/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 * SPDX-License-Identifier: EUPL-1.2
 *
 * The appointment a letter reads: the client's next one that still stands.
 *
 * @spec openspec/changes/letter-salutation-and-appointment/specs/client-letters/spec.md#requirement-a-letter-reads-the-clients-appointment
 */

/** Statuses of an appointment that no longer takes place at its time. */
const GONE = ['cancelled-by-customer', 'cancelled-by-business', 'rescheduled', 'completed', 'no-show']

/**
 * The earliest upcoming appointment that still stands, the one on the letter's ticket first.
 *
 * @param {Array<object>|null} bookings The client's appointments.
 * @param {string} ticketId The letter's ticket, or ''.
 * @param {Date} now The moment that counts as now.
 * @return {object|null} The appointment, or null when none is ahead.
 * @spec openspec/changes/letter-salutation-and-appointment/specs/client-letters/spec.md#requirement-a-letter-reads-the-clients-appointment
 */
export function nextAppointment(bookings, ticketId, now = new Date()) {
	const ahead = (Array.isArray(bookings) ? bookings : [])
		.map((booking) => ({ booking, at: Date.parse(booking?.startAt || '') }))
		.filter(({ booking, at }) => !Number.isNaN(at) && at >= now.getTime() && !GONE.includes(booking.status))
		.sort((a, b) => a.at - b.at)
		.map(({ booking }) => booking)

	return (ticketId && ahead.find((booking) => booking.ticket === ticketId)) || ahead[0] || null
}
