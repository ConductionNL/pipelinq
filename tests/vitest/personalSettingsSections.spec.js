// @vitest-environment jsdom
/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 * SPDX-License-Identifier: EUPL-1.2
 *
 * The app's sections in the user settings dialog (board PqPersoonlijk): the
 * sync with Contacts reads and saves the reader's own choices, and the agent
 * profile shows availability and skills.
 *
 * @spec openspec/specs/contacts-sync/spec.md
 */

import { flushPromises, mount } from '@vue/test-utils'
import { describe, expect, it, vi } from 'vitest'
import { h } from 'vue'

const axiosMock = vi.hoisted(() => ({ get: vi.fn(), put: vi.fn() }))

vi.mock('@nextcloud/axios', () => ({ default: axiosMock }))
vi.mock('@nextcloud/dialogs', () => ({ showError: vi.fn() }))
vi.mock('@nextcloud/router', () => ({ generateUrl: (path) => '/index.php' + path }))
vi.mock('@conduction/nextcloud-vue', () => ({
	CnStatusBadge: { name: 'CnStatusBadge', props: ['label'], render() { return h('span', { 'data-stub': 'badge' }, this.label) } },
}))
vi.mock('@nextcloud/vue', () => ({
	NcAppSettingsSection: { name: 'NcAppSettingsSection', props: ['name'], render() { return h('section', [this.name, this.$slots.default?.()]) } },
	NcSelect: { name: 'NcSelect', props: ['modelValue', 'options'], emits: ['update:modelValue'], render: () => h('div', { 'data-stub': 'select' }) },
	NcCheckboxRadioSwitch: { name: 'NcCheckboxRadioSwitch', props: ['modelValue'], emits: ['update:modelValue'], render() { return h('label', this.$slots.default?.()) } },
}))

const { default: PersonalSettingsSections } = await import('../../src/components/settings/PersonalSettingsSections.vue')

const SETTINGS = '/index.php/apps/pipelinq/api/contacts-sync/settings'

function mountSections() {
	axiosMock.get.mockImplementation(async (url) => {
		if (url === SETTINGS) {
			return { data: { addressBook: '4', writeBack: true, addressBooks: [{ key: '4', name: 'Pipelinq CRM' }] } }
		}
		return { data: { agentProfile: { isAvailable: true, skills: ['Afval', 'Parkeren', ''] } } }
	})
	return mount(PersonalSettingsSections, {
		global: {
			mocks: { t: (app, text) => text },
			stubs: { 'router-link': { render() { return h('a', this.$slots.default?.()) } } },
		},
	})
}

describe('the personal settings sections', () => {
	it('shows the reader\'s address book, write-back, availability and skills', async () => {
		const wrapper = mountSections()
		await flushPromises()
		const select = wrapper.findComponent({ name: 'NcSelect' })
		expect(select.props('modelValue')).toBe('4')
		expect(select.props('options').map((option) => option.key)).toEqual(['', '4'])
		expect(wrapper.findComponent({ name: 'NcCheckboxRadioSwitch' }).props('modelValue')).toBe(true)
		expect(wrapper.find('[data-testid="personal-settings-availability"]').text()).toContain('Available.')
		expect(wrapper.findAll('[data-stub="badge"]').map((badge) => badge.text())).toEqual(['Afval', 'Parkeren'])
	})

	it('saves a changed choice and shows what the server kept', async () => {
		const wrapper = mountSections()
		await flushPromises()
		axiosMock.put.mockResolvedValue({ data: { addressBook: '', writeBack: false, addressBooks: [] } })
		const toggle = wrapper.findComponent({ name: 'NcCheckboxRadioSwitch' })
		toggle.vm.$emit('update:modelValue', false)
		await flushPromises()
		expect(axiosMock.put).toHaveBeenCalledWith(SETTINGS, { addressBook: '4', writeBack: false })
		expect(toggle.props('modelValue')).toBe(false)
		expect(wrapper.findComponent({ name: 'NcSelect' }).props('modelValue')).toBe('')
	})
})
