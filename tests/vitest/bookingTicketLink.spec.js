/**
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * PqAfspraak's Gekoppeld verzoek: the booking page reads the ticket the
 * booking names and opens it.
 *
 * @spec openspec/changes/booking-ticket-link/specs/appointment-booking/spec.md#requirement-a-booking-can-name-its-request
 */
import { describe, expect, it } from 'vitest'
import nl from '../../l10n/nl.json'
import fragment from '../../src/manifest.d/80-appointment-booking-admin.json'

const page = fragment.pages.find((p) => p.id === 'BookingDetail')
const card = page.config.widgets.find((w) => w.id === 'booking-request')

describe('the booking page', () => {
	it('shows the linked request in the side column, read by the booking\'s ticket id', () => {
		expect(page.config.sideColumn).toContain('booking-request')
		expect(card.type).toBe('record-list')
		expect(card.content).toEqual(expect.objectContaining({ register: 'pipelinq', schema: 'ticket', filter: { _ids: ['@object.ticket'] }, limit: 1, rowRoute: 'TicketDetail' }))
	})

	it('reads as the board in Dutch', () => {
		expect(nl.translations[card.title]).toBe('Gekoppeld verzoek')
		expect(nl.translations[card.content.emptyText]).toBe('Niet aan een verzoek gekoppeld')
	})
})
