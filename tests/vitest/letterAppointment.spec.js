/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 * SPDX-License-Identifier: EUPL-1.2
 *
 * The appointment a letter reads (PqBriefOpstellen's Afspraak field): the
 * client's next one that still stands, the one on the letter's ticket first.
 *
 * @spec openspec/changes/letter-salutation-and-appointment/specs/client-letters/spec.md#requirement-a-letter-reads-the-clients-appointment
 */

import { describe, expect, it } from 'vitest'
import { nextAppointment } from '../../src/services/letterAppointment.js'

const NOW = new Date('2026-10-08T09:00:00+02:00')

describe('nextAppointment', () => {
	it('takes the earliest upcoming appointment that still stands', () => {
		const bookings = [
			{ id: 'later', startAt: '2026-10-20T10:00:00+02:00', status: 'confirmed' },
			{ id: 'past', startAt: '2026-10-01T10:00:00+02:00', status: 'confirmed' },
			{ id: 'cancelled', startAt: '2026-10-10T10:00:00+02:00', status: 'cancelled-by-customer' },
			{ id: 'moved', startAt: '2026-10-11T10:00:00+02:00', status: 'rescheduled' },
			{ id: 'next', startAt: '2026-10-14T10:30:00+02:00', status: 'pending-deposit' },
		]
		expect(nextAppointment(bookings, '', NOW)?.id).toBe('next')
	})

	it('prefers the appointment on the letter\'s ticket', () => {
		const bookings = [
			{ id: 'other', startAt: '2026-10-09T10:00:00+02:00', status: 'confirmed', ticket: 't-2' },
			{ id: 'mine', startAt: '2026-10-14T10:30:00+02:00', status: 'confirmed', ticket: 't-1' },
		]
		expect(nextAppointment(bookings, 't-1', NOW)?.id).toBe('mine')
		expect(nextAppointment(bookings, 't-3', NOW)?.id).toBe('other')
	})

	it('answers null when nothing is ahead', () => {
		expect(nextAppointment([], '', NOW)).toBeNull()
		expect(nextAppointment(null, '', NOW)).toBeNull()
		expect(nextAppointment([{ id: 'x', startAt: 'not a date', status: 'confirmed' }], '', NOW)).toBeNull()
	})
})
