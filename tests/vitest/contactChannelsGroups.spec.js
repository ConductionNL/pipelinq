// @vitest-environment jsdom
/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 * SPDX-License-Identifier: EUPL-1.2
 *
 * The Channels tab of a client or contact: the e-mail and phone groups with
 * their heading, add button, empty line, and one row per entry (kind, link,
 * primary and verified marks, write-email for an address, edit and remove),
 * and what each button opens or removes.
 *
 * @spec openspec/changes/contact-channel-details/specs/contact-channel-details/spec.md#requirement-detail-pages-display-channels-as-a-linked-list-with-kind-chips
 */
import { flushPromises, mount } from '@vue/test-utils'
import { describe, expect, it, vi } from 'vitest'

vi.mock('@nextcloud/dialogs', () => ({ showError: vi.fn(), showSuccess: vi.fn() }))
vi.mock('@nextcloud/vue', () => ({
	NcActionButton: { template: '<button class="nc-action"><slot /></button>' },
	NcActions: { template: '<div class="nc-actions"><slot name="icon" /><slot /></div>' },
	NcButton: {
		props: ['variant', 'ariaLabel'],
		emits: ['click'],
		template: '<button class="nc-button" :data-variant="variant" :aria-label="ariaLabel" @click="$emit(\'click\')"><slot name="icon" /><slot /></button>',
	},
	NcLoadingIcon: { template: '<i class="loading" />' },
}))
vi.mock('../../src/modals/ContactEmailPhoneModal.vue', () => ({ default: { props: ['entry', 'channelType'], template: '<div class="email-phone-modal" :data-type="channelType" />' } }))
vi.mock('../../src/modals/ContactSocialProfileModal.vue', () => ({ default: { template: '<div class="social-modal" />' } }))
vi.mock('../../src/modals/WriteEmailModal.vue', () => ({ default: { props: ['to'], template: '<div class="write-email-modal" :data-to="to" />' } }))
vi.mock('../../src/services/contactSyncApi.js', () => ({ writeBack: vi.fn() }))

const store = vi.hoisted(() => ({ fetchObject: vi.fn(), saveObject: vi.fn() }))
vi.mock('../../src/store/modules/object.js', () => ({ useObjectStore: () => store }))

globalThis.t = (_app, text, vars = {}) => text.replace(/\{(\w+)\}/g, (m, k) => String(vars[k] ?? m))
const { default: ContactChannelsSection } = await import('../../src/components/ContactChannelsSection.vue')

/**
 * @param {object} wrapper The mounted section.
 * @return {string} Its markup without Vue's placeholder comments and the whitespace around tags.
 */
const markup = (wrapper) => wrapper.html().replace(/<!--[\s\S]*?-->/g, '').replace(/>\s+</g, '><').replace(/>\s+/g, '>').replace(/\s+</g, '<')

const icon = (name) => ({ props: ['title'], template: `<b class="icon-${name}" :title="title" />` })

/**
 * @param {object} entity The client the store returns.
 * @return {Promise<object>} The mounted section.
 */
async function mountWith(entity) {
	store.fetchObject.mockResolvedValue(entity)
	const wrapper = mount(ContactChannelsSection, {
		props: { entityId: 'c1', entityType: 'client' },
		global: {
			mocks: { t: globalThis.t },
			stubs: { Star: icon('star'), CheckCircle: icon('check'), Pencil: icon('pencil'), Delete: icon('delete'), Plus: icon('plus'), EmailEditOutline: icon('write') },
		},
	})
	await flushPromises()
	return wrapper
}

const full = {
	id: 'c1',
	emails: [
		{ kind: 'work', value: 'a@example.org', primary: true, verified: true },
		{ kind: 'private', value: 'b@example.org' },
	],
	phones: [
		{ kind: 'mobile', value: '0612345678', primary: true },
		{ kind: 'other', value: '0201234567', verified: true },
	],
	socialProfiles: [{ network: 'linkedin', url: 'https://example.org/in/a', handle: 'a', verified: true, followsUs: true }],
}

describe('the e-mail and phone groups', () => {
	it('render the same markup', async () => {
		const wrapper = await mountWith(full)
		expect(markup(wrapper)).toMatchSnapshot()
	})

	it('render the empty lines when there are no entries', async () => {
		const wrapper = await mountWith({ id: 'c1' })
		expect(wrapper.findAll('.contact-channels-section__empty').map((p) => p.text())).toEqual(['No email addresses yet.', 'No phone numbers yet.', 'No social profiles yet.'])
		expect(markup(wrapper)).toMatchSnapshot()
	})

	it('open the right modal from each add and edit button', async () => {
		const wrapper = await mountWith(full)
		await wrapper.get('[data-testid="add-email-button"]').trigger('click')
		expect(wrapper.vm.emailPhoneModal).toMatchObject({ open: true, channelType: 'email', index: null })
		await wrapper.get('[data-testid="add-phone-button"]').trigger('click')
		expect(wrapper.vm.emailPhoneModal).toMatchObject({ open: true, channelType: 'phone', index: null })
		const edits = wrapper.findAll('button[aria-label="Edit"]')
		await edits[1].trigger('click')
		expect(wrapper.vm.emailPhoneModal).toMatchObject({ channelType: 'email', index: 1, entry: full.emails[1] })
		await edits[3].trigger('click')
		expect(wrapper.vm.emailPhoneModal).toMatchObject({ channelType: 'phone', index: 1, entry: full.phones[1] })
	})

	it('write an e-mail to the address of the row', async () => {
		const wrapper = await mountWith(full)
		const writes = wrapper.findAll('[data-testid="write-email-button"]')
		expect(writes).toHaveLength(2)
		await writes[1].trigger('click')
		expect(wrapper.vm.writeEmailTo).toBe('b@example.org')
	})

	it('remove the entry of the row from its own list', async () => {
		const wrapper = await mountWith(full)
		const removeEntry = vi.spyOn(wrapper.vm, 'removeEntry').mockImplementation(() => {})
		const removes = wrapper.findAll('button[aria-label="Remove"]')
		await removes[0].trigger('click')
		await removes[3].trigger('click')
		await removes[4].trigger('click')
		expect(removeEntry.mock.calls).toEqual([['emails', 0], ['phones', 1], ['socialProfiles', 0]])
	})
})
