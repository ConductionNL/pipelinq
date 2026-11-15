// SPDX-License-Identifier: EUPL-1.2
// Copyright (C) 2026 Conduction B.V.

/**
 * Edge cases of linking a website submission: non-string reporter fields,
 * ids answered under `@self`, failed creation and lead fallbacks.
 */

import { describe, expect, it, vi } from 'vitest'
import { linkPlan, runLinkPlan, submittedParty } from '../../src/utils/submissionLink.js'

describe('submittedParty edge cases', () => {
	it('reads non-string and missing fields as empty', () => {
		expect(submittedParty({ reporterName: 42, reporterEmail: null })).toEqual({
			name: '', email: '', phone: '', organisation: '', form: '', page: '',
		})
		expect(submittedParty(undefined).name).toBe('')
	})
})

describe('linkPlan edge cases', () => {
	it('stringifies an existing id', () => {
		const plan = linkPlan({ ticket: {}, client: { mode: 'existing', id: 7 }, contact: { mode: 'existing', id: 'c' } })
		expect(plan.client).toEqual({ id: '7' })
		expect(plan.contact).toEqual({ id: 'c' })
		expect(plan.lead).toBeNull()
	})

	it('names the missing existing contact', () => {
		expect(() => linkPlan({ ticket: {}, client: { mode: 'new' }, contact: { mode: 'existing' } }))
			.toThrow('No existing contact chosen.')
	})

	it('leaves e-mail and phone out of new records when not typed', () => {
		const plan = linkPlan({ ticket: { reporterName: 'Ann' } })
		expect(plan.client).toEqual({ create: { name: 'Ann', type: 'person' } })
		expect(plan.contact).toEqual({ create: { name: 'Ann' } })
	})

	it('falls back to the person name and the website source for a lead', () => {
		const plan = linkPlan({ ticket: { reporterEmail: ' Ann@Example.org ' }, lead: true })
		expect(plan.lead).toEqual({ title: 'ann@example.org', description: '', source: 'website' })
	})
})

describe('runLinkPlan edge cases', () => {
	const api = (overrides = {}) => ({
		createParty: vi.fn(async (type) => ({ '@self': { id: `${type}-self` } })),
		patchTicket: vi.fn(async () => ({})),
		defaultPipeline: vi.fn(async () => 'p1'),
		createLead: vi.fn(async () => ({ '@self': { id: 99 } })),
		...overrides,
	})

	it('reads created ids from @self and stringifies the lead id', async () => {
		const calls = api()
		const plan = { client: { create: { name: 'A' } }, contact: { create: { name: 'B' } }, lead: { title: 'T' } }
		expect(await runLinkPlan(plan, 'ticket-1', calls)).toEqual({ client: 'client-self', contact: 'contact-self', lead: '99' })
		expect(calls.patchTicket).toHaveBeenCalledWith('ticket-1', { client: 'client-self', contact: 'contact-self' })
		expect(calls.createLead).toHaveBeenCalledWith({ title: 'T', client: 'client-self', contact: 'contact-self', pipeline: 'p1' })
	})

	it('throws when a party is created without an id and links nothing', async () => {
		const calls = api({ createParty: vi.fn(async () => ({})) })
		const plan = { client: { create: { name: 'A' } }, contact: { id: 'c' }, lead: null }
		await expect(runLinkPlan(plan, 't', calls)).rejects.toThrow('The client could not be created.')
		expect(calls.patchTicket).not.toHaveBeenCalled()
	})

	it('answers an empty lead id when the created lead has none', async () => {
		const calls = api({ createLead: vi.fn(async () => null) })
		const plan = { client: { id: 'a' }, contact: { id: 'b' }, lead: { title: 'T' } }
		expect(await runLinkPlan(plan, 't', calls)).toEqual({ client: 'a', contact: 'b', lead: '' })
		expect(calls.createParty).not.toHaveBeenCalled()
	})
})
