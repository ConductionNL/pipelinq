// @vitest-environment jsdom
/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Saving a contact's channels: what is merged and sent, the primary value kept in step,
 * the write-back, and what a refused save says. Also the preferences line.
 *
 * @spec openspec/changes/contact-channel-details/specs/contact-channel-details/spec.md
 */
import { flushPromises, mount } from '@vue/test-utils'
import { beforeEach, describe, expect, it, vi } from 'vitest'

const dialogs = vi.hoisted(() => ({ showError: vi.fn(), showSuccess: vi.fn() }))
const sync = vi.hoisted(() => ({ writeBack: vi.fn() }))
const store = vi.hoisted(() => ({ fetchObject: vi.fn(), saveObject: vi.fn(), getError: vi.fn() }))

vi.mock('@nextcloud/dialogs', () => dialogs)
vi.mock('@nextcloud/vue', () => ({
	NcActionButton: { template: '<button><slot /></button>' },
	NcActions: { template: '<div><slot name="icon" /><slot /></div>' },
	NcButton: { template: '<button><slot name="icon" /><slot /></button>' },
	NcLoadingIcon: { template: '<i class="loading" />' },
}))
vi.mock('../../src/modals/ContactEmailPhoneModal.vue', () => ({ default: { template: '<div />' } }))
vi.mock('../../src/modals/ContactSocialProfileModal.vue', () => ({ default: { template: '<div />' } }))
vi.mock('../../src/modals/WriteEmailModal.vue', () => ({ default: { template: '<div />' } }))
vi.mock('../../src/services/contactSyncApi.js', () => sync)
vi.mock('../../src/store/modules/object.js', () => ({ useObjectStore: () => store }))

globalThis.t = (_app, text, vars = {}) => text.replace(/\{(\w+)\}/g, (m, k) => String(vars[k] ?? m))
const { default: ContactChannelsSection } = await import('../../src/components/ContactChannelsSection.vue')

/**
 * Mount on a contact the store answers.
 *
 * @param {object} entity The contact.
 * @param {object} props Extra props.
 * @return {Promise<object>} The wrapper.
 */
async function section(entity, props = {}) {
	store.fetchObject.mockResolvedValue(entity)
	const w = mount(ContactChannelsSection, {
		props: { entityId: 'p-1', entityType: 'contact', ...props },
		global: { mocks: { t: globalThis.t }, stubs: { Star: true, CheckCircle: true, Pencil: true, Delete: true, Plus: true, EmailEditOutline: true } },
	})
	await flushPromises()
	return w
}

describe('ContactChannelsSection saving', () => {
	beforeEach(() => {
		Object.values(dialogs).forEach((fn) => fn.mockReset())
		Object.values(store).forEach((fn) => fn.mockReset())
		sync.writeBack.mockReset()
	})

	it('adds an email, makes it the only primary, sets the primary address and writes back', async () => {
		const w = await section({ id: 'p-1', name: 'Ilse', emails: [{ value: 'old@x.nl', primary: true }] })
		store.saveObject.mockImplementation(async (_type, merged) => merged)

		w.vm.openEmailPhoneModal('email', null)
		expect(w.vm.emailPhoneModal).toEqual({ open: true, channelType: 'email', index: null, entry: null })
		await w.vm.onEmailPhoneSaved({ value: 'new@x.nl', primary: true })

		const [type, merged] = store.saveObject.mock.calls[0]
		expect(type).toBe('contact')
		expect(merged.emails).toEqual([{ value: 'old@x.nl', primary: false }, { value: 'new@x.nl', primary: true }])
		expect(merged.email).toBe('new@x.nl')
		expect(merged).not.toHaveProperty('phone')
		expect(w.vm.emailPhoneModal.open).toBe(false)
		expect(dialogs.showSuccess).toHaveBeenCalledWith('Contact channels saved.')
		expect(sync.writeBack).toHaveBeenCalledWith('contact', 'p-1')
		expect(w.vm.entity).toEqual(merged)
	})

	it('replaces an edited phone in place and takes the first as primary when none is marked', async () => {
		const w = await section({ id: 'p-1', phones: [{ value: '010' }, { value: '020' }] })
		store.saveObject.mockImplementation(async (_type, merged) => merged)

		w.vm.openEmailPhoneModal('phone', 1)
		expect(w.vm.emailPhoneModal.entry).toEqual({ value: '020' })
		await w.vm.onEmailPhoneSaved({ value: '030' })

		const merged = store.saveObject.mock.calls[0][1]
		expect(merged.phones).toEqual([{ value: '010' }, { value: '030' }])
		expect(merged.phone).toBe('010')
	})

	it('adds and edits social profiles', async () => {
		const w = await section({ id: 'p-1', socialProfiles: [{ network: 'x', handle: '@a' }] })
		store.saveObject.mockImplementation(async (_type, merged) => merged)

		w.vm.openSocialModal(0)
		expect(w.vm.socialModal.profile).toEqual({ network: 'x', handle: '@a' })
		await w.vm.onSocialProfileSaved({ network: 'x', handle: '@b' })
		expect(store.saveObject.mock.calls[0][1].socialProfiles).toEqual([{ network: 'x', handle: '@b' }])

		w.vm.openSocialModal(null)
		await w.vm.onSocialProfileSaved({ network: 'bluesky', handle: '@c' })
		expect(store.saveObject.mock.calls[1][1].socialProfiles).toEqual([{ network: 'x', handle: '@b' }, { network: 'bluesky', handle: '@c' }])
		expect(w.vm.socialModal.open).toBe(false)
	})

	it('removes an entry and recomputes the primary value', async () => {
		const w = await section({ id: 'p-1', email: 'a@x.nl', emails: [{ value: 'a@x.nl' }, { value: 'b@x.nl' }] })
		store.saveObject.mockImplementation(async (_type, merged) => merged)

		await w.vm.removeEntry('emails', 0)

		expect(store.saveObject.mock.calls[0][1]).toMatchObject({ emails: [{ value: 'b@x.nl' }], email: 'b@x.nl' })

		await w.vm.removeEntry('emails', 0)
		expect(store.saveObject.mock.calls[1][1]).toMatchObject({ emails: [], email: '' })
	})

	it('a refused save reports the store\'s error, or a fallback, and keeps the entity', async () => {
		const entity = { id: 'p-1', emails: [{ value: 'a@x.nl' }] }
		const w = await section(entity)
		store.saveObject.mockResolvedValue(null)

		store.getError.mockReturnValue({ message: 'Not allowed' })
		await w.vm.removeEntry('emails', 0)
		expect(store.getError).toHaveBeenCalledWith('contact')
		expect(dialogs.showError).toHaveBeenLastCalledWith('Not allowed')

		store.getError.mockReturnValue(null)
		await w.vm.removeEntry('emails', 0)
		expect(dialogs.showError).toHaveBeenLastCalledWith('Failed to save contact channels.')
		expect(dialogs.showSuccess).not.toHaveBeenCalled()
		expect(sync.writeBack).not.toHaveBeenCalled()
		expect(w.vm.entity).toEqual(entity)
	})

	it('builds the preferences line from channel, timezone and language', async () => {
		const w = await section({ id: 'p-1', preferredChannel: 'whatsapp', timezone: 'Europe/Amsterdam', correspondenceLanguage: 'nl' })
		expect(w.vm.preferencesLine).toBe('Prefers WhatsApp · Europe/Amsterdam · NL')

		w.vm.entity = { preferredChannel: 'mastodon', language: 'en' }
		expect(w.vm.preferencesLine).toBe('Prefers Mastodon · EN')
		w.vm.entity = { preferredChannel: 'other' }
		expect(w.vm.preferencesLine).toBe('Prefers Other')
		w.vm.entity = { preferredChannel: 'signal' }
		expect(w.vm.preferencesLine).toBe('Prefers signal')
		w.vm.entity = { preferredChannel: 'phone' }
		expect(w.vm.preferencesLine).toBe('Prefers Phone')
		w.vm.entity = {}
		expect(w.vm.preferencesLine).toBe('')
		w.vm.entity = null
		expect(w.vm.preferencesLine).toBe('')
	})

	it('names the email placeholder after the entity type', async () => {
		expect((await section({ id: 'p-1', name: 'Ilse' })).vm.emailPlaceholderValues).toEqual({ 'contact.name': 'Ilse' })
		expect((await section({ id: 'c-1', name: 'Bakkerij' }, { entityId: 'c-1', entityType: 'client' })).vm.emailPlaceholderValues).toEqual({ 'client.name': 'Bakkerij' })
	})

	it('reads nothing without an id', async () => {
		const w = await section({ id: 'x' }, { entityId: '' })
		expect(store.fetchObject).not.toHaveBeenCalled()
		expect(w.vm.entity).toBeNull()
	})
})
