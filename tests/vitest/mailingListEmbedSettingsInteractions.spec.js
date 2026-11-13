// @vitest-environment jsdom
/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 * SPDX-License-Identifier: EUPL-1.2
 *
 * The mailing-list signup-form section: choosing a list shows its snippet
 * (and warns when the list refuses public signup), Copy puts the snippet on
 * the clipboard or says it could not, and a failed list load offers nothing.
 *
 * @spec openspec/specs/marketing-lists/spec.md#requirement-self-service-subscribe-creates-a-pending-subscription
 */

import { flushPromises, mount } from '@vue/test-utils'
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import { h } from 'vue'
import { embedSnippet } from '../../src/services/subscriptionState.js'

const listsMock = vi.hoisted(() => ({ fetchMailingLists: vi.fn() }))
const dialogsMock = vi.hoisted(() => ({ showError: vi.fn(), showSuccess: vi.fn() }))

vi.mock('../../src/services/mailingListApi.js', () => listsMock)
vi.mock('@nextcloud/dialogs', () => dialogsMock)
vi.mock('vue-material-design-icons/ContentCopy.vue', () => ({ default: { render: () => null } }))
vi.mock('@nextcloud/vue', () => ({
	NcButton: {
		name: 'NcButton',
		emits: ['click'],
		render() {
			return h('button', { onClick: () => this.$emit('click') }, this.$slots.default?.())
		},
	},
	NcNoteCard: {
		name: 'NcNoteCard',
		props: ['type'],
		render() {
			return h('div', { 'data-testid': 'note' }, this.$slots.default?.())
		},
	},
	NcSelect: {
		name: 'NcSelect',
		props: ['modelValue', 'options', 'loading'],
		emits: ['update:modelValue'],
		render: () => h('div'),
	},
	NcSettingsSection: {
		name: 'NcSettingsSection',
		render() {
			return h('section', this.$slots.default?.())
		},
	},
}))

globalThis.t = (app, text) => text

const mountOptions = { global: { mocks: { t: globalThis.t } } }

const { default: MailingListEmbedSettings } = await import('../../src/views/settings/MailingListEmbedSettings.vue')

const clipboard = { writeText: vi.fn() }

beforeEach(() => {
	vi.clearAllMocks()
	Object.defineProperty(navigator, 'clipboard', { value: clipboard, configurable: true })
})

afterEach(() => {
	delete navigator.clipboard
})

/**
 * Mount the section with the given lists and choose the option at index.
 *
 * @param {Array} lists The mailing lists the API returns.
 * @param {number} index The option to choose.
 * @return {Promise<object>} The wrapper.
 */
async function mountAndChoose(lists, index) {
	listsMock.fetchMailingLists.mockResolvedValue(lists)
	const wrapper = mount(MailingListEmbedSettings, mountOptions)
	await flushPromises()
	const select = wrapper.getComponent({ name: 'NcSelect' })
	select.vm.$emit('update:modelValue', select.props('options')[index])
	await flushPromises()
	return wrapper
}

describe('Mailing list signup form', () => {
	it('offers each list by name, id or @self id', async () => {
		listsMock.fetchMailingLists.mockResolvedValue([
			{ id: 'l1', name: 'News', publicSignup: true },
			{ uuid: 'l2' },
			{ '@self': { uuid: 'l3' }, name: 'Events' },
		])
		const wrapper = mount(MailingListEmbedSettings, mountOptions)
		await flushPromises()

		expect(wrapper.getComponent({ name: 'NcSelect' }).props('options')).toEqual([
			{ id: 'l1', label: 'News', publicSignup: true },
			{ id: 'l2', label: 'l2', publicSignup: false },
			{ id: 'l3', label: 'Events', publicSignup: false },
		])
		expect(wrapper.find('textarea').exists()).toBe(false)
	})

	it('shows the chosen list\'s snippet without a signup warning when the list is public', async () => {
		const wrapper = await mountAndChoose([{ id: 'l1', name: 'News', publicSignup: true }], 0)

		expect(wrapper.get('textarea').element.value).toBe(embedSnippet(window.location.origin, 'l1'))
		expect(wrapper.findAll('[data-testid="note"]')).toHaveLength(1)
	})

	it('warns that a list without public signup will refuse the form', async () => {
		const wrapper = await mountAndChoose([{ id: 'l2', name: 'Internal' }], 0)

		const notes = wrapper.findAll('[data-testid="note"]')
		expect(notes).toHaveLength(2)
		expect(notes[0].text()).toContain('does not accept public signup')
	})

	it('copies the snippet to the clipboard', async () => {
		clipboard.writeText.mockResolvedValue(undefined)
		const wrapper = await mountAndChoose([{ id: 'l1', name: 'News', publicSignup: true }], 0)

		await wrapper.get('button').trigger('click')
		await flushPromises()

		expect(clipboard.writeText).toHaveBeenCalledWith(embedSnippet(window.location.origin, 'l1'))
		expect(dialogsMock.showSuccess).toHaveBeenCalledWith('Signup form copied to the clipboard.')
		expect(dialogsMock.showError).not.toHaveBeenCalled()
	})

	it('says so when the clipboard refuses', async () => {
		clipboard.writeText.mockRejectedValue(new Error('denied'))
		const wrapper = await mountAndChoose([{ id: 'l1', name: 'News', publicSignup: true }], 0)

		await wrapper.get('button').trigger('click')
		await flushPromises()

		expect(dialogsMock.showError).toHaveBeenCalledWith('Could not copy the signup form.')
		expect(dialogsMock.showSuccess).not.toHaveBeenCalled()
	})

	it('offers no lists when they cannot be loaded, and stops loading', async () => {
		listsMock.fetchMailingLists.mockRejectedValue(new Error('offline'))
		const wrapper = mount(MailingListEmbedSettings, mountOptions)
		await flushPromises()

		const select = wrapper.getComponent({ name: 'NcSelect' })
		expect(select.props('options')).toEqual([])
		expect(select.props('loading')).toBe(false)
	})
})
