/**
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * Boards PqTicket, PqTicketBesluitvorming, PqTicketContactmomenten and
 * PqTicketGerelateerd draw the ticket's "Gerelateerd" side card as one
 * compact link to the appointment that belongs to the ticket ("Afspraak:
 * locatiebezoek Parkstraat, 15 oktober"); every relation stays on the
 * Gerelateerd tab and behind "Show all". Board PqTaak keeps its related rows.
 *
 * @spec openspec/changes/ticket-related-compact-links/specs/detail-pages/spec.md#requirement-the-ticket-side-card-lists-its-appointments-as-links
 */
import fs from 'fs'
import path from 'path'
import { describe, expect, it } from 'vitest'

const ROOT = path.resolve(__dirname, '../..')
const readJson = (...parts) => JSON.parse(fs.readFileSync(path.join(ROOT, ...parts), 'utf8'))
const manifest = readJson('src', 'manifest.json')
const widgets = manifest.pages.flatMap((page) => page.config?.widgets || [])
const widget = (id) => widgets.find((candidate) => candidate.id === id)

describe('the ticket related side card (PqTicket)', () => {
	const related = widget('ticket-related')

	it('lists the related records as compact links', () => {
		expect(related.type).toBe('related')
		expect(related.content.layout).toBe('links')
	})

	it('links the appointments that name this ticket, with subject and date', () => {
		const [bookings, ...rest] = related.content.extraSources
		expect(rest).toHaveLength(0)
		expect(bookings.endpoint).toBe('/apps/openregister/api/objects/pipelinq/appointmentBooking?ticket=@objectId')
		expect(bookings.path).toBe('results')
		expect(bookings.itemLabel).toBe('Appointment: {subject}, {startAt|longdate}')
		expect(bookings.rowRoute).toEqual({ name: 'BookingDetail', params: { id: '{id}' } })
	})

	it('has the link label in Dutch and English', () => {
		const label = related.content.extraSources[0].itemLabel
		expect(readJson('l10n', 'nl.json').translations[label]).toBe('Afspraak: {subject}, {startAt|longdate}')
		expect(readJson('l10n', 'en.json').translations[label]).toBe(label)
	})

	it('leaves the task\'s related rows as they are (board PqTaak draws rows)', () => {
		expect(widget('task-related').content?.layout).toBeUndefined()
	})
})
