/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 * SPDX-License-Identifier: EUPL-1.2
 *
 * The contact moment draft's two checks, branch by branch: whether a quick
 * log form holds anything worth a draft, and which draft is offered back.
 *
 * @spec openspec/changes/contact-moments-keep-draft/tasks.md#task-1.2
 */
import { describe, expect, it } from 'vitest'
import { isDraftFormEmpty, pickDraft } from '../../src/services/contactMomentDraft.js'

describe('isDraftFormEmpty', () => {
	it('calls no form empty', () => {
		expect(isDraftFormEmpty(null)).toBe(true)
		expect(isDraftFormEmpty(undefined, { clientId: 'c' })).toBe(true)
	})

	it('counts typed text in any text field, not whitespace or non-strings', () => {
		for (const key of ['title', 'description', 'duration', 'notes']) {
			expect(isDraftFormEmpty({ [key]: 'x' })).toBe(false)
			expect(isDraftFormEmpty({ [key]: ' \n ' })).toBe(true)
			expect(isDraftFormEmpty({ [key]: 5 })).toBe(true)
		}
	})

	it('counts a pick that differs from its preset', () => {
		for (const key of ['channel', 'direction', 'outcome', 'contact']) {
			expect(isDraftFormEmpty({ [key]: 'a' })).toBe(false)
			expect(isDraftFormEmpty({ [key]: 'a' }, { defaults: { [key]: 'a' } })).toBe(true)
			expect(isDraftFormEmpty({ [key]: 'a' }, { defaults: { [key]: 'b' } })).toBe(false)
			expect(isDraftFormEmpty({ [key]: '' }, { defaults: { [key]: 'b' } })).toBe(true)
		}
	})

	it('counts a client or request other than the one the log was opened on', () => {
		expect(isDraftFormEmpty({ client: 'c' })).toBe(false)
		expect(isDraftFormEmpty({ client: 'c' }, { clientId: 'c' })).toBe(true)
		expect(isDraftFormEmpty({ client: 'd' }, { clientId: 'c' })).toBe(false)
		expect(isDraftFormEmpty({ parentTicket: 'r' })).toBe(false)
		expect(isDraftFormEmpty({ parentTicket: 'r' }, { requestId: 'r' })).toBe(true)
		expect(isDraftFormEmpty({ parentTicket: 'q' }, { requestId: 'r' })).toBe(false)
		expect(isDraftFormEmpty({ client: '', parentTicket: null }, { clientId: 'c', requestId: 'r' })).toBe(true)
	})
})

describe('pickDraft', () => {
	const now = new Date('2026-11-10T12:00:00Z')
	const draft = (id, extra = {}, self = {}) => ({
		id,
		ticketType: 'interaction',
		client: 'c',
		parentTicket: null,
		'@self': { status: 'draft', owner: 'me', updated: '2026-11-09T12:00:00Z', ...self },
		...extra,
	})

	it('reads no drafts as none', () => {
		expect(pickDraft(null, { author: 'me', clientId: 'c', requestId: null, now })).toBe(null)
		expect(pickDraft([], { author: 'me', clientId: 'c', requestId: null, now })).toBe(null)
	})

	it('skips what is not the author\'s own contact moment draft', () => {
		const drafts = [
			null,
			{ id: 'no-self', ticketType: 'interaction', client: 'c' },
			draft('published', {}, { status: 'published' }),
			draft('colleague', {}, { owner: 'you' }),
			draft('request', { ticketType: 'request' }),
		]
		expect(pickDraft(drafts, { author: 'me', clientId: 'c', requestId: null, now })).toBe(null)
	})

	it('skips drafts without a readable date or older than seven days, and falls back to the created date', () => {
		const drafts = [
			draft('undated', {}, { updated: undefined }),
			draft('bad', {}, { updated: 'nope' }),
			draft('old', {}, { updated: '2026-11-02T11:59:00Z' }),
			draft('created', {}, { updated: '', created: '2026-11-03T12:00:00Z' }),
		]
		expect(pickDraft(drafts, { author: 'me', clientId: 'c', requestId: null, now })?.id).toBe('created')
	})

	it('matches on client and request, and on the request alone without a client in the context', () => {
		const drafts = [
			draft('other-client', { client: 'd' }),
			draft('no-client', { client: undefined }),
			draft('on-request', { parentTicket: 'r' }),
		]
		expect(pickDraft(drafts, { author: 'me', clientId: 'c', requestId: null, now })).toBe(null)
		expect(pickDraft(drafts, { author: 'me', clientId: null, requestId: null, now })?.id).toBe('no-client')
		expect(pickDraft(drafts, { author: 'me', clientId: '', requestId: null, now })?.id).toBe('no-client')
		expect(pickDraft(drafts, { author: 'me', clientId: undefined, requestId: 'r', now })?.id).toBe('on-request')
		expect(pickDraft(drafts, { author: 'me', clientId: undefined, requestId: undefined, now })?.id).toBe('other-client')
	})

	it('offers the newest match', () => {
		const drafts = [
			draft('older', {}, { updated: '2026-11-08T12:00:00Z' }),
			draft('newest', {}, { updated: '2026-11-10T11:00:00Z' }),
			draft('middle', {}, { updated: '2026-11-09T12:00:00Z' }),
		]
		expect(pickDraft(drafts, { author: 'me', clientId: 'c', requestId: null, now })?.id).toBe('newest')
	})

	it('measures the age from the current time without a now', () => {
		const fresh = draft('fresh', {}, { updated: new Date().toISOString() })
		expect(pickDraft([fresh], { author: 'me', clientId: 'c', requestId: null })?.id).toBe('fresh')
	})
})
