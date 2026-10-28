/**
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * PqAfspraak as pl2 measured it: the type pill names the booked service
 * ("Locatiebezoek"), and Afspraakgegevens reads as two columns, row by row,
 * the notes across both.
 *
 * @spec openspec/specs/appointment-booking/spec.md
 */
import { describe, expect, it } from 'vitest'
import fragment from '../../src/manifest.d/80-appointment-booking-admin.json'

const config = fragment.pages.find((p) => p.id === 'BookingDetail').config
const data = config.widgets.find((w) => w.id === 'booking-data').content

describe('the booking page', () => {
	it('names the booked service in the type pill', () => {
		expect(config.typePill).toEqual(expect.objectContaining({ field: 'serviceId', resolve: { register: 'pipelinq', schema: 'appointmentService', labelField: 'name' } }))
	})

	it('lays the appointment details out in two fixed columns, in the board\'s order', () => {
		expect(data.columns).toBe(2)
		expect(data.fixedColumns).toBe(true)
		const ordered = Object.entries(data.overrides).sort((a, b) => a[1].order - b[1].order).map(([key]) => key)
		expect(ordered).toEqual(data.include)
		expect(data.overrides.notes.gridColumn).toBe(2)
		expect(data.overrides.internalNotes.gridColumn).toBe(2)
	})
})
