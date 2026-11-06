/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 * SPDX-License-Identifier: EUPL-1.2
 *
 * PqAanvraagOmzetten after decision 179: the website submissions are a lens
 * over form tickets, and a colleague links one to a client and a contact.
 *
 * @spec openspec/specs/unify-ticket-supertype/spec.md
 */

import { readFileSync } from 'node:fs'
import { resolve } from 'node:path'
import { describe, expect, it, vi } from 'vitest'
import { linkPlan, runLinkPlan, submittedParty } from '../../src/utils/submissionLink.js'

const read = (...parts) => JSON.parse(readFileSync(resolve(__dirname, '../..', ...parts), 'utf8'))
const fragment = read('src', 'manifest.d', '26-website-submissions.json')
const manifest = read('src', 'manifest.json')
const registrySource = readFileSync(resolve(__dirname, '../../src/registry.js'), 'utf8')

const TICKET = {
	id: 't1',
	title: 'Catering voor de opening van onze nieuwe winkel',
	description: 'Kunt u koffie en lunch verzorgen?',
	reporterName: 'Joke Smit',
	reporterEmail: 'Joke@BakkerijDeGraaf.nl',
	reporterPhone: '06 41 22 87 90',
	reporterOrganisation: 'Bakkerij De Graaf',
	source: 'website-contact',
	sourceUrl: 'https://zuiddrecht.nl/contact',
}

describe('the website submissions page', () => {
	const page = fragment.pages.find((entry) => entry.id === 'WebsiteSubmissions')

	it('lists the tickets a form made, newest first', () => {
		expect(page.config.schema).toBe('ticket')
		expect(page.config.filter).toEqual({ source: 'IS NOT NULL' })
		expect(page.config.sortKey).toBe('occurredAt')
		expect(page.config.sortOrder).toBe('desc')
	})

	it('opens on the submissions nobody linked yet', () => {
		expect(page.config.quickFilters.map((lens) => lens.label)).toEqual(['Not linked yet', 'Linked', 'Closed', 'All'])
		expect(page.config.quickFilters[0]).toMatchObject({ default: true, filter: { client: 'IS NULL' } })
		expect(page.config.quickFilters[1].filter).toEqual({ client: 'IS NOT NULL' })
	})

	it('shows what the visitor typed and where', () => {
		expect(page.config.columns.map((column) => (typeof column === 'string' ? column : column.key)))
			.toEqual(['occurredAt', 'title', 'reporterName', 'reporterOrganisation', 'source', 'sourceUrl', 'status', 'assignee'])
		expect(page.config.columns.find((column) => column.key === 'assignee').formatter).toBe('userDisplayName')
	})

	it('has a menu entry', () => {
		expect(fragment.menu.map((entry) => entry.route)).toContain('WebsiteSubmissions')
	})
})

describe('Link to a client on the ticket page', () => {
	const ticketPage = manifest.pages.find((entry) => entry.id === 'TicketDetail')
	const action = ticketPage.config.headerActions.find((entry) => entry.id === 'link-to-client')

	it('opens the dialog for this ticket while it has no client', () => {
		expect(action).toMatchObject({ type: 'open-modal', target: 'LinkSubmissionToClientModal', props: { ticketId: '@objectId' } })
		expect(action.visibleWhen).toEqual({ field: 'client', op: 'empty' })
	})

	it('registers the dialog as a modal', () => {
		expect(registrySource).toMatch(/LinkSubmissionToClientModal: \{\s*kind: 'modal',/)
	})
})

describe('what the visitor typed', () => {
	it('reads the reporter fields, the e-mail lowercased', () => {
		expect(submittedParty(TICKET)).toEqual({
			name: 'Joke Smit',
			email: 'joke@bakkerijdegraaf.nl',
			phone: '06 41 22 87 90',
			organisation: 'Bakkerij De Graaf',
			form: 'website-contact',
			page: 'https://zuiddrecht.nl/contact',
		})
	})
})

describe('the link plan', () => {
	it('creates a new client and contact from what was typed, and the lead when asked', () => {
		const plan = linkPlan({ ticket: TICKET, client: { mode: 'new' }, contact: { mode: 'new' }, lead: true })
		expect(plan.client).toEqual({ create: { name: 'Bakkerij De Graaf', type: 'organization', email: 'joke@bakkerijdegraaf.nl' } })
		expect(plan.contact).toEqual({ create: { name: 'Joke Smit', email: 'joke@bakkerijdegraaf.nl', phone: '06 41 22 87 90' } })
		expect(plan.lead).toEqual({ title: TICKET.title, description: TICKET.description, source: 'website-contact' })
	})

	it('names a person without an organisation as a person client', () => {
		const plan = linkPlan({ ticket: { ...TICKET, reporterOrganisation: '' }, client: { mode: 'new' }, contact: { mode: 'existing', id: 'c9' }, lead: false })
		expect(plan.client.create).toMatchObject({ name: 'Joke Smit', type: 'person' })
		expect(plan.contact).toEqual({ id: 'c9' })
		expect(plan.lead).toBeNull()
	})

	it('refuses an existing choice without an id', () => {
		expect(() => linkPlan({ ticket: TICKET, client: { mode: 'existing' }, contact: { mode: 'new' } })).toThrow()
	})

	it('runs the plan: creates, then links the ticket, then makes the lead', async () => {
		const calls = []
		const api = {
			createParty: vi.fn(async (type, data) => { calls.push(['create', type, data.name]); return { id: `${type}-new` } }),
			patchTicket: vi.fn(async (id, data) => { calls.push(['patch', id, data]) }),
			defaultPipeline: vi.fn(async () => 'p1'),
			createLead: vi.fn(async (data) => { calls.push(['lead', data]); return { id: 'l1' } }),
		}
		const plan = linkPlan({ ticket: TICKET, client: { mode: 'new' }, contact: { mode: 'existing', id: 'c9' }, lead: true })
		const result = await runLinkPlan(plan, TICKET.id, api)
		expect(calls).toEqual([
			['create', 'client', 'Bakkerij De Graaf'],
			['patch', 't1', { client: 'client-new', contact: 'c9' }],
			['lead', { title: TICKET.title, description: TICKET.description, source: 'website-contact', client: 'client-new', contact: 'c9', pipeline: 'p1' }],
		])
		expect(result).toEqual({ client: 'client-new', contact: 'c9', lead: 'l1' })
	})

	it('links the ticket even when no default pipeline exists, and says the lead was skipped', async () => {
		const api = {
			createParty: vi.fn(),
			patchTicket: vi.fn(async () => {}),
			defaultPipeline: vi.fn(async () => ''),
			createLead: vi.fn(),
		}
		const plan = linkPlan({ ticket: TICKET, client: { mode: 'existing', id: 'k1' }, contact: { mode: 'existing', id: 'c9' }, lead: true })
		const result = await runLinkPlan(plan, TICKET.id, api)
		expect(api.patchTicket).toHaveBeenCalledWith('t1', { client: 'k1', contact: 'c9' })
		expect(api.createLead).not.toHaveBeenCalled()
		expect(result.lead).toBe('')
		expect(result.leadSkipped).toBe(true)
	})
})
