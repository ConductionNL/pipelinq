// @vitest-environment jsdom
/**
 * SPDX-License-Identifier: EUPL-1.2
 * Copyright (C) 2026 Conduction B.V.
 *
 * What each section-backed widget in the v2 registry (src/registry.js) hands
 * its section: the props built from the page's object id, the object and the
 * manifest content, as `sectionProps()` of the wrapping widget returns them.
 * Also the two contact-aware create overrides, which route the generic Add
 * through the contact-first create for their own object type.
 *
 * The contact-sync API is the mocked boundary.
 */

import { beforeAll, beforeEach, describe, expect, it, vi } from 'vitest'

vi.mock('../../src/services/contactSyncApi.js', async (importOriginal) => ({
	...(await importOriginal()),
	createWithContact: vi.fn(),
}))

let registry
let createWithContact

beforeAll(async () => {
	// Some components translate at module scope through the Nextcloud globals.
	globalThis.t = (_app, text) => text
	globalThis.n = (_app, one, many, count) => (count === 1 ? one : many)
	registry = (await import('../../src/registry.js')).default
	createWithContact = (await import('../../src/services/contactSyncApi.js')).createWithContact
}, 60000)

/**
 * The props a registry widget hands its section.
 *
 * @param {string} name The registry key.
 * @param {string|number} objectId The page's object id.
 * @param {object} [objectData] The page's object.
 * @param {object} [content] The widget's manifest content.
 * @return {object} The section props, without the onHeader relay.
 */
function sectionProps(name, objectId, objectData = {}, content = {}) {
	const widget = registry[name].component
	const self = { objectData, content, $emit: vi.fn() }
	const { onHeader, ...props } = widget.methods.sectionProps.call(self, objectId)
	expect(typeof onHeader).toBe('function')
	return props
}

describe('registry widget section props', () => {
	it.each([
		['ListSignupFormWidget', { listId: 'obj-1' }],
		['ListSubscriberViewWidget', { listId: 'obj-1' }],
		['SocialPostVariantsWidget', { postId: 'obj-1', hideApprovals: true }],
		['SocialPublicationsWidget', { postId: 'obj-1' }],
		['LeadContactRolesWidget', { leadId: 'obj-1' }],
		['TicketConversationWidget', {}],
		['DossierSnapshotWidget', { objectId: 'obj-1' }],
		['WooConversionWidget', { objectId: 'obj-1' }],
		['RequestConversionWidget', { requestId: 'obj-1' }],
		['SubscriptionsWidget', { contactId: 'obj-1' }],
		['BrpContactWidget', { contactId: 'obj-1' }],
		['CampaignLandingPageWidget', { campaignId: 'obj-1' }],
		['CampaignReportWidget', { campaignId: 'obj-1' }],
		['JourneyRunsWidget', { journeyId: 'obj-1', showSubtitle: false }],
		['BookingContextWidget', { bookingId: 'obj-1', part: 'context' }],
		['BookingCustomerWidget', { bookingId: 'obj-1', part: 'customer' }],
		['BookingWhenWidget', { bookingId: 'obj-1', part: 'when' }],
		['BookingAssignmentsWidget', { bookingId: 'obj-1' }],
	])('%s maps the object id', (name, expected) => {
		expect(sectionProps(name, 'obj-1')).toEqual(expected)
	})

	it('a social post id is passed on as a string', () => {
		expect(sectionProps('SocialPostVariantsWidget', 42)).toEqual({ postId: '42', hideApprovals: true })
		expect(sectionProps('SocialPublicationsWidget', 42)).toEqual({ postId: '42' })
	})

	it('the party strip reads kind and channels off the object, empty when absent', () => {
		expect(sectionProps('PartyContactStripWidget', 'p-1', {
			type: 'organisation',
			phone: '+31 20 123 4567',
			email: 'info@example.nl',
			address: 'Dam 1',
		})).toEqual({
			partyId: 'p-1',
			partyKind: 'organisation',
			phone: '+31 20 123 4567',
			email: 'info@example.nl',
			address: 'Dam 1',
		})
		expect(sectionProps('PartyContactStripWidget', 'p-1')).toEqual({
			partyId: 'p-1', partyKind: '', phone: '', email: '', address: '',
		})
	})

	it('relationships default to a contact and are compact only when the content says true', () => {
		expect(sectionProps('ContactRelationshipsWidget', 'c-1', { name: 'Ada' }, { entityType: 'client', compact: true }))
			.toEqual({ entityId: 'c-1', entityType: 'client', entityName: 'Ada', compact: true })
		expect(sectionProps('ContactRelationshipsWidget', 'c-1', {}, { compact: 'yes' }))
			.toEqual({ entityId: 'c-1', entityType: 'contact', entityName: '', compact: false })
	})

	it('the exchange rate reads value and currency, a missing value as 0', () => {
		expect(sectionProps('LeadExchangeRateWidget', 'l-1', { value: 1500, currency: 'USD' }))
			.toEqual({ value: 1500, currency: 'USD' })
		expect(sectionProps('LeadExchangeRateWidget', 'l-1', { value: null }))
			.toEqual({ value: 0, currency: '' })
	})

	it('decision routes hide the convert action only when the content turns it off', () => {
		expect(sectionProps('TicketDecisionRoutesWidget', 't-1', {}, { convert: false }))
			.toEqual({ ticketId: 't-1', hideConvert: true })
		expect(sectionProps('TicketDecisionRoutesWidget', 't-1'))
			.toEqual({ ticketId: 't-1', hideConvert: false })
	})

	it('the resident view reads the ticket\'s type, message and status', () => {
		expect(sectionProps('ResidentViewWidget', 't-2', {
			ticketType: 'complaint', customerMessage: 'Bin not emptied', status: 'open',
		})).toEqual({ ticketId: 't-2', ticketType: 'complaint', customerMessage: 'Bin not emptied', status: 'open' })
		expect(sectionProps('ResidentViewWidget', 't-2'))
			.toEqual({ ticketId: 't-2', ticketType: '', customerMessage: '', status: '' })
	})

	it('contacts sync carries the entity type and the object\'s contacts uid', () => {
		expect(sectionProps('ContactsSyncWidget', 'c-2', { contactsUid: 'abc' }, { entityType: 'client' }))
			.toEqual({ entityId: 'c-2', entityType: 'client', contactsUid: 'abc' })
		expect(sectionProps('ContactsSyncWidget', 'c-2'))
			.toEqual({ entityId: 'c-2', entityType: 'contact', contactsUid: '' })
	})

	it('channels and conversations default to a contact entity', () => {
		expect(sectionProps('ContactChannelsWidget', 'c-3', {}, { entityType: 'client', compact: true }))
			.toEqual({ entityId: 'c-3', entityType: 'client', compact: true })
		expect(sectionProps('ContactChannelsWidget', 'c-3'))
			.toEqual({ entityId: 'c-3', entityType: 'contact', compact: false })
		expect(sectionProps('MessagingConversationWidget', 'c-3', {}, { entityType: 'lead' }))
			.toEqual({ entityId: 'c-3', entityType: 'lead' })
		expect(sectionProps('MessagingConversationWidget', 'c-3'))
			.toEqual({ entityId: 'c-3', entityType: 'contact' })
	})

	it('a routing suggestion defaults to a request on a ticket', () => {
		expect(sectionProps('RoutingSuggestionWidget', 'r-1', { category: 'waste', assignee: 'bob' }, { entityType: 'lead', objectType: 'case' }))
			.toEqual({ objectId: 'r-1', category: 'waste', entityType: 'lead', objectType: 'case', assignee: 'bob' })
		expect(sectionProps('RoutingSuggestionWidget', 'r-1'))
			.toEqual({ objectId: 'r-1', category: '', entityType: 'request', objectType: 'ticket', assignee: '' })
	})
})

describe('registry contact-aware create overrides', () => {
	beforeEach(() => {
		createWithContact.mockReset()
	})

	it.each([
		['createClientContactAware', 'client'],
		['createContactContactAware', 'contact'],
	])('%s creates a %s through the contact-first path', async (name, type) => {
		const form = { name: 'Ada Lovelace', email: 'ada@example.nl' }
		createWithContact.mockResolvedValue({ id: 'new-1' })

		await expect(registry[name].handler(form)).resolves.toEqual({ id: 'new-1' })

		expect(createWithContact).toHaveBeenCalledTimes(1)
		expect(createWithContact).toHaveBeenCalledWith(type, form)
	})
})
