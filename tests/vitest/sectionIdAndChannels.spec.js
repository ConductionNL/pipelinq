/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Two detail sections read their object id from the prop, else from the
 * section context the page host provides (a ref or a plain object); the
 * contact channels section also summarises the preferences and saves a
 * channel list with its primary value mirrored.
 */
import { beforeAll, beforeEach, describe, expect, it, vi } from 'vitest'

const dialogs = vi.hoisted(() => ({ showError: vi.fn(), showSuccess: vi.fn() }))
const sync = vi.hoisted(() => ({ writeBack: vi.fn() }))

vi.mock('@conduction/nextcloud-vue', () => ({ cnRenderMarkdown: vi.fn(), resolveImageUrl: vi.fn() }))
vi.mock('@nextcloud/axios', () => ({ default: { get: vi.fn() } }))
vi.mock('@nextcloud/event-bus', () => ({ emit: vi.fn(), subscribe: vi.fn(), unsubscribe: vi.fn() }))
vi.mock('@nextcloud/router', () => ({ generateUrl: (path) => path }))
vi.mock('@nextcloud/dialogs', () => dialogs)
vi.mock('@nextcloud/vue', () => ({ NcActionButton: {}, NcActions: {}, NcButton: {}, NcLoadingIcon: {}, NcNoteCard: {} }))
vi.mock('../../src/modals/ContactEmailPhoneModal.vue', () => ({ default: {} }))
vi.mock('../../src/modals/ContactSocialProfileModal.vue', () => ({ default: {} }))
vi.mock('../../src/modals/WriteEmailModal.vue', () => ({ default: {} }))
vi.mock('../../src/services/satisfactionApi.js', () => ({ fetchClientSatisfaction: vi.fn() }))
vi.mock('../../src/services/contactSyncApi.js', () => sync)
vi.mock('../../src/store/modules/object.js', () => ({ useObjectStore: () => ({}) }))

let sections

beforeAll(async () => {
	globalThis.t = (app, text, vars = {}) => text.replace(/\{(\w+)\}/g, (match, key) => String(vars[key] ?? match))
	sections = {
		satisfaction: (await import('../../src/components/satisfaction/ClientSatisfactionSection.vue')).default,
		channels: (await import('../../src/components/ContactChannelsSection.vue')).default,
	}
}, 60000)

beforeEach(() => {
	dialogs.showError.mockReset()
	dialogs.showSuccess.mockReset()
	sync.writeBack.mockReset()
})

const hosts = [
	[null, ''],
	[{ value: { objectId: 'o1', articleId: 'a1' } }, 'o1'],
	[{ value: { articleId: 'a1' } }, 'a1'],
	[{ value: null }, ''],
	[{ objectId: 'o2' }, 'o2'],
	[{ articleId: 'a2' }, 'a2'],
	['text', ''],
]

describe('the section ids', () => {
	it('read a client or contact id from the prop, else the host', () => {
		for (const [cnSectionContext, id] of hosts) {
			const expected = id.startsWith('a') ? '' : id
			expect(sections.satisfaction.computed.resolvedId.call({ clientId: '', cnSectionContext })).toBe(expected)
			expect(sections.channels.computed.resolvedId.call({ entityId: '', cnSectionContext })).toBe(expected)
		}
		expect(sections.satisfaction.computed.resolvedId.call({ clientId: 'c', cnSectionContext: { objectId: 'o' } })).toBe('c')
		expect(sections.channels.computed.resolvedId.call({ entityId: 'e', cnSectionContext: { objectId: 'o' } })).toBe('e')
	})
})

describe('ContactChannelsSection', () => {
	/**
	 * @param {object} entity The contact or client.
	 * @return {string} The preferences line.
	 */
	function preferences(entity) {
		return sections.channels.computed.preferencesLine.call({ ...sections.channels.methods, entity })
	}

	it('summarises the preferences that are set', () => {
		expect(preferences(null)).toBe('')
		expect(preferences({})).toBe('')
		expect(preferences({ preferredChannel: 'email', timezone: 'Europe/Amsterdam', correspondenceLanguage: 'nl', language: 'en' }))
			.toBe('Prefers Email · Europe/Amsterdam · NL')
		expect(preferences({ preferredChannel: 'whatsapp', language: 'de' })).toBe('Prefers WhatsApp · DE')
		expect(preferences({ timezone: 'UTC' })).toBe('UTC')
	})

	/**
	 * @param {object} state The section's state.
	 * @return {object} A section context.
	 */
	function ctx(state) {
		return {
			...sections.channels.methods,
			entityType: 'contact',
			resolvedId: 'k1',
			entity: { id: 'k1', name: 'Anna', email: 'old@x.nl', phone: '010' },
			objectStore: { saveObject: vi.fn(async (type, object) => ({ ...object, saved: true })), getError: vi.fn() },
			...state,
		}
	}

	it('saves a list and mirrors its primary, else first, value', async () => {
		const c = ctx({})
		await sections.channels.methods.persist.call(c, { emails: [{ value: 'a@x.nl' }, { value: 'b@x.nl', primary: true }] })
		expect(c.objectStore.saveObject).toHaveBeenCalledWith('contact', {
			id: 'k1', name: 'Anna', email: 'b@x.nl', phone: '010', emails: [{ value: 'a@x.nl' }, { value: 'b@x.nl', primary: true }],
		})
		expect(c.entity.saved).toBe(true)
		expect(dialogs.showSuccess).toHaveBeenCalledWith('Contact channels saved.')
		expect(sync.writeBack).toHaveBeenCalledWith('contact', 'k1')

		const phones = ctx({})
		await sections.channels.methods.persist.call(phones, { phones: [{ value: '06' }] })
		expect(phones.objectStore.saveObject).toHaveBeenCalledWith('contact', expect.objectContaining({ email: 'old@x.nl', phone: '06' }))
		const emptied = ctx({})
		await sections.channels.methods.persist.call(emptied, { emails: [], phones: [] })
		expect(emptied.objectStore.saveObject).toHaveBeenCalledWith('contact', expect.objectContaining({ email: '', phone: '' }))
		const social = ctx({})
		await sections.channels.methods.persist.call(social, { socialProfiles: [] })
		expect(social.objectStore.saveObject).toHaveBeenCalledWith('contact', expect.objectContaining({ email: 'old@x.nl', phone: '010', socialProfiles: [] }))
	})

	it('says why a save failed, and writes nothing back', async () => {
		const c = ctx({ objectStore: { saveObject: vi.fn(async () => null), getError: () => ({ message: 'Refused' }) } })
		await sections.channels.methods.persist.call(c, { phones: [] })
		expect(dialogs.showError).toHaveBeenCalledWith('Refused')
		expect(c.entity.saved).toBeUndefined()
		const plain = ctx({ objectStore: { saveObject: vi.fn(async () => null) } })
		await sections.channels.methods.persist.call(plain, { phones: [] })
		expect(dialogs.showError).toHaveBeenLastCalledWith('Failed to save contact channels.')
		expect(sync.writeBack).not.toHaveBeenCalled()
		expect(dialogs.showSuccess).not.toHaveBeenCalled()
	})
})
