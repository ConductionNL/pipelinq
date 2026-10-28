/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Board PqTicket (and its tab boards) draws a request's side column as three
 * facts cards: Klant with the client's initials, name and kind beside the
 * channel and a link to the client page; Termijn with the deadline written
 * out over priority and handler; Gekoppelde zaak with the case or "No case
 * linked yet.". All of it is configuration on the simple profile's ticket page.
 *
 * @spec openspec/changes/unify-ticket-supertype/specs/unify-ticket-supertype/spec.md#requirement-unified-tickets-workspace
 */

import fs from 'fs'
import path from 'path'
import { describe, expect, it } from 'vitest'

const ROOT = path.resolve(__dirname, '../..')
const simple = JSON.parse(fs.readFileSync(path.join(ROOT, 'src', 'menu-layout.simple.json'), 'utf8'))
const nl = JSON.parse(fs.readFileSync(path.join(ROOT, 'l10n', 'nl.json'), 'utf8')).translations
const en = JSON.parse(fs.readFileSync(path.join(ROOT, 'l10n', 'en.json'), 'utf8')).translations
const page = simple.pages.find((p) => p.id === 'TicketDetail')
const card = (id) => page.config.sideColumn.find((w) => typeof w === 'object' && w.id === id)

describe('the request side cards as the board draws them', () => {
	it('draws Klant as the client lead, the channel and a link to the client page', () => {
		const customer = card('side-customer')
		expect(customer.type).toBe('facts')
		expect(nl[customer.title]).toBe('Klant')
		expect(customer.content.source).toEqual({ register: 'pipelinq', schema: 'client', id: '@object.client' })
		const [lead, channel] = customer.content.fields
		expect(lead.as).toBe('lead')
		expect(lead.initialsPath).toBe('name')
		expect(lead.subtitle.map((part) => part.path)).toEqual(['type', 'address'])
		expect(channel).toMatchObject({ from: 'object', path: 'channel', label: 'Channel' })
		expect(nl[channel.map.phone]).toBe('Telefoon')
		expect(customer.content.link.route).toEqual({ name: 'ClientDetail', params: { id: '@object.client' } })
		expect(nl[customer.content.link.label]).toBe('Contactgegevens en eerdere tickets')
	})

	it('draws Termijn as the deadline written out over priority and handler', () => {
		const deadline = card('side-deadline')
		expect(deadline.type).toBe('facts')
		expect(nl[deadline.title]).toBe('Termijn')
		const [when, priority, handler] = deadline.content.fields
		expect(when).toMatchObject({ as: 'title', path: 'slaDeadline', format: 'longdatetime' })
		expect(priority).toMatchObject({ path: 'priority', label: 'Priority' })
		expect(nl[priority.map.normal]).toBe('Normaal')
		expect(handler).toMatchObject({ path: 'assignee', label: 'Handler', format: 'user' })
	})

	it('draws Gekoppelde zaak as the case by name, or that none is linked', () => {
		const linked = card('side-case')
		expect(linked.type).toBe('facts')
		expect(nl[linked.title]).toBe('Gekoppelde zaak')
		expect(linked.content.fields[0]).toMatchObject({ path: 'caseReference', resolve: { register: 'dossiq', schema: 'case', labelField: 'title' } })
		expect(en[linked.content.emptyText]).toBe('No case linked yet.')
		expect(nl[linked.content.emptyText]).toBe('Nog geen zaak gekoppeld.')
	})
})
