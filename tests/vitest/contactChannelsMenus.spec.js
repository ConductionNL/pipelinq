// @vitest-environment jsdom
/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Opening and closing the channel dialogs from the screen: the compact
 * "Add channel" menu, the social profile buttons of the full lists, writing
 * an email from an address, and the kind chips the lists show.
 *
 * @spec openspec/specs/contact-channel-details/spec.md#requirement-detail-pages-display-channels-as-a-linked-list-with-kind-chips
 */
import { flushPromises, mount } from '@vue/test-utils'
import { beforeEach, describe, expect, it, vi } from 'vitest'

const store = vi.hoisted(() => ({ fetchObject: vi.fn(), saveObject: vi.fn(), getError: vi.fn() }))

vi.mock('@nextcloud/dialogs', () => ({ showError: vi.fn(), showSuccess: vi.fn() }))
vi.mock('@nextcloud/vue', () => ({
	NcActionButton: { name: 'NcActionButton', emits: ['click'], template: '<button class="action" @click="$emit(\'click\')"><slot /></button>' },
	NcActions: { name: 'NcActions', template: '<div><slot name="icon" /><slot /></div>' },
	NcButton: { name: 'NcButton', emits: ['click'], template: '<button @click="$emit(\'click\')"><slot name="icon" /><slot /></button>' },
	NcLoadingIcon: { template: '<i class="loading" />' },
}))
vi.mock('../../src/modals/ContactEmailPhoneModal.vue', () => ({
	default: { name: 'ContactEmailPhoneModal', props: ['entry', 'channelType'], emits: ['close', 'save'], template: '<div class="email-phone-modal" />' },
}))
vi.mock('../../src/modals/ContactSocialProfileModal.vue', () => ({
	default: { name: 'ContactSocialProfileModal', props: ['profile'], emits: ['close', 'save'], template: '<div class="social-modal" />' },
}))
vi.mock('../../src/modals/WriteEmailModal.vue', () => ({
	default: { name: 'WriteEmailModal', props: ['address', 'language', 'placeholderValues'], emits: ['close'], template: '<div class="write-modal" />' },
}))
vi.mock('../../src/services/contactSyncApi.js', () => ({ writeBack: vi.fn() }))
vi.mock('../../src/store/modules/object.js', () => ({ useObjectStore: () => store }))

globalThis.t = (_app, text, vars = {}) => text.replace(/\{(\w+)\}/g, (m, k) => String(vars[k] ?? m))
const { default: ContactChannelsSection } = await import('../../src/components/ContactChannelsSection.vue')

const STUBS = { Star: true, CheckCircle: true, Pencil: true, Delete: true, Plus: true, EmailEditOutline: true }

async function section(entity, props = {}) {
	store.fetchObject.mockResolvedValue(entity)
	const w = mount(ContactChannelsSection, {
		props: { entityId: 'p-1', entityType: 'contact', ...props },
		global: { mocks: { t: globalThis.t }, stubs: STUBS },
	})
	await flushPromises()
	return w
}

describe('ContactChannelsSection dialogs', () => {
	beforeEach(() => {
		Object.values(store).forEach((fn) => fn.mockReset())
	})

	it('the compact menu opens an empty email, phone or social dialog, and each closes', async () => {
		const w = await section({ id: 'p-1', emails: [{ value: 'a@x.nl', kind: 'work' }] }, { compact: true })
		expect(w.find('[data-testid="channels-compact"]').text()).toContain('a@x.nl, work')
		const [email, phone, social] = w.findAll('button.action')
		expect([email.text(), phone.text(), social.text()]).toEqual(['Email address', 'Phone number', 'Social profile'])

		await email.trigger('click')
		let modal = w.findComponent({ name: 'ContactEmailPhoneModal' })
		expect(modal.props()).toEqual({ entry: null, channelType: 'email' })
		await modal.vm.$emit('close')
		expect(w.findComponent({ name: 'ContactEmailPhoneModal' }).exists()).toBe(false)

		await phone.trigger('click')
		modal = w.findComponent({ name: 'ContactEmailPhoneModal' })
		expect(modal.props('channelType')).toBe('phone')
		await modal.vm.$emit('close')

		await social.trigger('click')
		const socialModal = w.findComponent({ name: 'ContactSocialProfileModal' })
		expect(socialModal.props('profile')).toBeNull()
		await socialModal.vm.$emit('close')
		expect(w.findComponent({ name: 'ContactSocialProfileModal' }).exists()).toBe(false)
		expect(store.saveObject).not.toHaveBeenCalled()
	})

	it('the social list adds an empty profile or edits the one on its row', async () => {
		const profile = { network: 'mastodon', handle: '@a@m.nl', url: 'https://m.nl/@a' }
		const w = await section({ id: 'p-1', socialProfiles: [profile] })

		await w.find('[data-testid="add-social-button"]').trigger('click')
		expect(w.findComponent({ name: 'ContactSocialProfileModal' }).props('profile')).toBeNull()
		await w.findComponent({ name: 'ContactSocialProfileModal' }).vm.$emit('close')

		const row = w.findAll('.contact-channels-section__row').find((li) => li.text().includes('@a@m.nl'))
		await row.find('button[aria-label="Edit"]').trigger('click')
		expect(w.findComponent({ name: 'ContactSocialProfileModal' }).props('profile')).toEqual(profile)
	})

	it('writes an email to the address on its row in the contact\'s language, and closes', async () => {
		const w = await section({ id: 'p-1', correspondenceLanguage: 'nl', emails: [{ value: 'a@x.nl' }] })

		await w.find('[data-testid="write-email-button"]').trigger('click')
		const modal = w.findComponent({ name: 'WriteEmailModal' })
		expect(modal.props('address')).toBe('a@x.nl')
		expect(modal.props('language')).toBe('nl')

		await modal.vm.$emit('close')
		expect(w.findComponent({ name: 'WriteEmailModal' }).exists()).toBe(false)
	})

	it('labels a WhatsApp number with its kind', async () => {
		const w = await section({ id: 'p-1', phones: [{ value: '0612345678', kind: 'whatsapp' }] })

		const row = w.findAll('.contact-channels-section__row').find((li) => li.text().includes('0612345678'))
		expect(row.find('.contact-channels-section__kind').text()).toBe('WhatsApp')
		expect(row.find('a').attributes('href')).toBe('tel:0612345678')
	})
})
