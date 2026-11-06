// @vitest-environment jsdom
/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Manage a list's sign-ups from the list (board PqMailinglijst "Aanmeldingen
 * beheren"): send the list's own confirmation link, or take a known member off.
 *
 * @spec openspec/specs/marketing-lists/spec.md#requirement-a-list-s-sign-ups-are-managed-from-the-list
 */

import { flushPromises, mount } from '@vue/test-utils'
import { describe, expect, it, vi } from 'vitest'

vi.mock('@conduction/nextcloud-vue', () => ({
	CnDialog: { name: 'CnDialog', props: ['name', 'subtitle', 'width'], emits: ['closing'], template: '<div class="cn-dialog" :data-width="width"><slot /><slot name="actions" /></div>' },
}))
vi.mock('@nextcloud/vue', () => ({
	NcNoteCard: { name: 'NcNoteCard', props: ['type'], template: '<div class="note" :data-type="type"><slot /></div>' },
	NcButton: { name: 'NcButton', template: '<button v-bind="$attrs"><slot /></button>' },
	NcTextField: { name: 'NcTextField', props: ['modelValue'], emits: ['update:modelValue'], template: '<input class="email" :value="modelValue" @input="$emit(\'update:modelValue\', $event.target.value)">' },
}))
const api = vi.hoisted(() => ({ inviteToList: vi.fn(async () => ({})), unsubscribeContact: vi.fn(async () => ({})) }))
vi.mock('../../src/services/mailingListApi.js', () => api)

const { default: ListSignupsManageDialog } = await import('../../src/dialogs/ListSignupsManageDialog.vue')
const fill = (s, v = {}) => s.replace(/\{(\w+)\}/g, (_, k) => v[k])

const ROWS = [
	{ id: 's1', email: 'info@bakkerijdegraaf.example', name: 'Bakkerij De Graaf', contactId: 'c-1', state: 'confirmed' },
	{ id: 's2', email: 'balie@sportcentrum.example', state: 'pending' },
]

const mountModal = () => mount(ListSignupsManageDialog, { props: { listId: 'l-1', subscriptions: ROWS }, global: { mocks: { t: (_a, s, v) => fill(s, v) } } })

describe('managing a list\'s sign-ups', () => {
	it('is a board-look form dialog with Close in its actions', () => {
		const wrapper = mountModal()
		expect(wrapper.find('.cn-dialog').attributes('data-width')).toBe('form')
		expect(wrapper.find('[data-testid="list-signups-close"]').exists()).toBe(true)
	})


	it('sends the list\'s confirmation link to an address', async () => {
		const wrapper = mountModal()
		await wrapper.find('.email').setValue('nieuw@ondernemer.example')
		await wrapper.find('[data-testid="list-signups-add"]').trigger('submit')
		await flushPromises()
		expect(api.inviteToList).toHaveBeenCalledWith('l-1', 'nieuw@ondernemer.example')
		expect(wrapper.find('.note').text()).toBe('A confirmation link went to nieuw@ondernemer.example.')
		expect(wrapper.emitted('changed')).toHaveLength(1)
	})

	it('takes a known member off this list, and only a known member', async () => {
		const wrapper = mountModal()
		const buttons = wrapper.findAll('[data-testid="list-signups-remove"]')
		expect(buttons[1].attributes('disabled')).toBeDefined()
		await buttons[0].trigger('click')
		await flushPromises()
		expect(api.unsubscribeContact).toHaveBeenCalledWith('c-1', 'l-1', 'Removed from the list by a colleague')
		expect(wrapper.find('.note').text()).toBe('Bakkerij De Graaf is off this list.')
	})
})
