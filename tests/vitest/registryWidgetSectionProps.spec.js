// @vitest-environment jsdom
/**
 * SPDX-License-Identifier: EUPL-1.2
 * Copyright (C) 2026 Conduction B.V.
 *
 * The prop mappings of the grid widgets in src/registry.js that wrap a body
 * section through sectionWidget(), and the two contact-aware create overrides.
 *
 * A detail page hands every custom widget `objectId`, `objectData` and
 * `content`; each mapping turns those into the props its section reads. A
 * wrong key (`leadId` vs `objectId`) or a lost default renders an empty
 * section with no error, so each mapping is pinned here by calling the
 * widget's own `sectionProps()` as the render path does.
 *
 * @spec openspec/specs/client-management/spec.md
 * @spec openspec/specs/contacts-sync/spec.md
 */

import { beforeAll, beforeEach, describe, expect, it, vi } from 'vitest'

const http = vi.hoisted(() => {
	const client = {
		get: vi.fn(() => Promise.resolve({ data: {} })),
		post: vi.fn(),
		put: vi.fn(() => Promise.resolve({ data: {} })),
		patch: vi.fn(() => Promise.resolve({ data: {} })),
		delete: vi.fn(() => Promise.resolve({ data: {} })),
		interceptors: { request: { use: vi.fn() }, response: { use: vi.fn() } },
	}
	client.create = () => client
	return client
})

vi.mock('@nextcloud/axios', () => ({ default: http }))
vi.mock('@nextcloud/router', async (importOriginal) => ({
	...(await importOriginal()),
	generateUrl: (path) => '/index.php' + path,
}))

let registry

beforeAll(async () => {
	// Some components translate at module scope through the Nextcloud globals.
	globalThis.t = (_app, text) => text
	globalThis.n = (_app, one, many, count) => (count === 1 ? one : many)
	registry = (await import('../../src/registry.js')).default
}, 120000)

/**
 * The props a registry widget hands its section, as its render does.
 *
 * @param {string} key The registry key.
 * @param {string} objectId The page's object id.
 * @param {object} [objectData] The page's object.
 * @param {object} [content] The widget's manifest content.
 * @return {object} The section props, without the onHeader relay.
 */
function propsOf(key, objectId, objectData = {}, content = {}) {
	const component = registry[key].component
	const emit = vi.fn()
	const { onHeader, ...props } = component.methods.sectionProps.call(
		{ objectData, content, $emit: emit },
		objectId,
	)
	onHeader({ title: 'x' })
	expect(emit).toHaveBeenCalledWith('header', { title: 'x' })
	return props
}

describe('registry section widgets: id-only mappings', () => {
	const cases = [
		['ListSignupFormWidget', { listId: 'L1' }],
		['ListSubscriberViewWidget', { listId: 'L1' }],
		['LeadContactRolesWidget', { leadId: 'L1' }],
		['DossierSnapshotWidget', { objectId: 'L1' }],
		['WooConversionWidget', { objectId: 'L1' }],
		['RequestConversionWidget', { requestId: 'L1' }],
		['SubscriptionsWidget', { contactId: 'L1' }],
		['BrpContactWidget', { contactId: 'L1' }],
		['CampaignLandingPageWidget', { campaignId: 'L1' }],
		['CampaignReportWidget', { campaignId: 'L1' }],
		['JourneyRunsWidget', { journeyId: 'L1', showSubtitle: false }],
		['BookingContextWidget', { bookingId: 'L1', part: 'context' }],
		['BookingCustomerWidget', { bookingId: 'L1', part: 'customer' }],
		['BookingWhenWidget', { bookingId: 'L1', part: 'when' }],
		['BookingAssignmentsWidget', { bookingId: 'L1' }],
	]

	it.each(cases)('%s binds the page id to the section prop it reads', (key, expected) => {
		expect(propsOf(key, 'L1', { name: 'ignored' }, { entityType: 'ignored' })).toEqual(expected)
	})

	it('the ticket conversation reads its record from the section context, not props', () => {
		expect(propsOf('TicketConversationWidget', 'T1', { id: 'T1' })).toEqual({})
	})
})

describe('registry section widgets: social posts', () => {
	it('passes the post id as a string and hides the approvals on the variants tab', () => {
		expect(propsOf('SocialPostVariantsWidget', 42)).toEqual({ postId: '42', hideApprovals: true })
		expect(propsOf('SocialPublicationsWidget', 42)).toEqual({ postId: '42' })
	})

	it('falls back to an empty post id', () => {
		expect(propsOf('SocialPostVariantsWidget', 0).postId).toBe('')
		expect(propsOf('SocialPublicationsWidget', 0).postId).toBe('')
	})
})

describe('registry section widgets: mappings that read the object and content', () => {
	it('fills the contact strip from the party record, empty strings when absent', () => {
		expect(propsOf('PartyContactStripWidget', 'P1', {
			type: 'organisation', phone: '010', email: 'a@b.nl', address: 'Straat 1',
		})).toEqual({ partyId: 'P1', partyKind: 'organisation', phone: '010', email: 'a@b.nl', address: 'Straat 1' })
		expect(propsOf('PartyContactStripWidget', 'P1')).toEqual({
			partyId: 'P1', partyKind: '', phone: '', email: '', address: '',
		})
	})

	it('binds relationships to a contact unless the content names another type', () => {
		expect(propsOf('ContactRelationshipsWidget', 'C1', { name: 'Ada' })).toEqual({
			entityId: 'C1', entityType: 'contact', entityName: 'Ada', compact: false,
		})
		expect(propsOf('ContactRelationshipsWidget', 'C1', {}, { entityType: 'client', compact: true })).toEqual({
			entityId: 'C1', entityType: 'client', entityName: '', compact: true,
		})
		// Only a literal true makes it compact.
		expect(propsOf('ContactRelationshipsWidget', 'C1', {}, { compact: 'yes' }).compact).toBe(false)
	})

	it('reads the lead value and currency, zero and empty when absent', () => {
		expect(propsOf('LeadExchangeRateWidget', 'L1', { value: 1250, currency: 'USD' })).toEqual({ value: 1250, currency: 'USD' })
		expect(propsOf('LeadExchangeRateWidget', 'L1')).toEqual({ value: 0, currency: '' })
		// `??` keeps a real zero rather than replacing it.
		expect(propsOf('LeadExchangeRateWidget', 'L1', { value: 0 }).value).toBe(0)
	})

	it('hides the convert route only when the content switches it off', () => {
		expect(propsOf('TicketDecisionRoutesWidget', 'T1')).toEqual({ ticketId: 'T1', hideConvert: false })
		expect(propsOf('TicketDecisionRoutesWidget', 'T1', {}, { convert: false })).toEqual({ ticketId: 'T1', hideConvert: true })
		expect(propsOf('TicketDecisionRoutesWidget', 'T1', {}, { convert: true }).hideConvert).toBe(false)
	})

	it('passes the ticket fields the resident view reads', () => {
		expect(propsOf('ResidentViewWidget', 'T1', {
			ticketType: 'request', customerMessage: 'Hallo', status: 'open',
		})).toEqual({ ticketId: 'T1', ticketType: 'request', customerMessage: 'Hallo', status: 'open' })
		expect(propsOf('ResidentViewWidget', 'T1')).toEqual({ ticketId: 'T1', ticketType: '', customerMessage: '', status: '' })
	})

	it('binds the contacts sync section to the linked contact uid', () => {
		expect(propsOf('ContactsSyncWidget', 'C1', { contactsUid: 'uid-1' }, { entityType: 'client' })).toEqual({
			entityId: 'C1', entityType: 'client', contactsUid: 'uid-1',
		})
		expect(propsOf('ContactsSyncWidget', 'C1')).toEqual({ entityId: 'C1', entityType: 'contact', contactsUid: '' })
	})

	it('binds channels and messaging to a contact by default', () => {
		expect(propsOf('ContactChannelsWidget', 'C1')).toEqual({ entityId: 'C1', entityType: 'contact', compact: false })
		expect(propsOf('ContactChannelsWidget', 'C1', {}, { entityType: 'client', compact: true })).toEqual({
			entityId: 'C1', entityType: 'client', compact: true,
		})
		expect(propsOf('MessagingConversationWidget', 'C1')).toEqual({ entityId: 'C1', entityType: 'contact' })
		expect(propsOf('MessagingConversationWidget', 'C1', {}, { entityType: 'client' })).toEqual({ entityId: 'C1', entityType: 'client' })
	})

	it('routes a lead suggestion from its category and assignee, request/ticket by default', () => {
		expect(propsOf('RoutingSuggestionWidget', 'R1')).toEqual({
			objectId: 'R1', category: '', entityType: 'request', objectType: 'ticket', assignee: '',
		})
		expect(propsOf('RoutingSuggestionWidget', 'R1', { category: 'sales', assignee: 'ada' }, { entityType: 'lead', objectType: 'lead' })).toEqual({
			objectId: 'R1', category: 'sales', entityType: 'lead', objectType: 'lead', assignee: 'ada',
		})
	})
})

describe('registry contact-aware create overrides', () => {
	beforeEach(() => {
		http.post.mockReset()
	})

	it('creates a client through the contacts-sync endpoint and returns the saved object', async () => {
		http.post.mockResolvedValue({ data: { object: { id: 'c-1', contactsUid: 'uid-1' } } })
		const result = await registry.createClientContactAware.handler({ name: 'Ada' })
		expect(http.post).toHaveBeenCalledWith('/index.php/apps/pipelinq/api/contacts-sync/create', {
			objectType: 'client',
			object: { name: 'Ada' },
		})
		expect(result).toEqual({ id: 'c-1', contactsUid: 'uid-1' })
	})

	it('creates a contact the same way with its own object type', async () => {
		http.post.mockResolvedValue({ data: { object: { id: 'p-1' } } })
		const result = await registry.createContactContactAware.handler({ email: 'a@b.nl' })
		expect(http.post).toHaveBeenCalledWith('/index.php/apps/pipelinq/api/contacts-sync/create', {
			objectType: 'contact',
			object: { email: 'a@b.nl' },
		})
		expect(result).toEqual({ id: 'p-1' })
	})

	it('lets a failed create reach the caller', async () => {
		http.post.mockRejectedValue(new Error('400'))
		await expect(registry.createClientContactAware.handler({})).rejects.toThrow('400')
	})
})
