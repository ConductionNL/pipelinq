// @vitest-environment jsdom
/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Managing a list's sign-ups when the server says no: the dialog says what
 * did not happen, keeps the address typed, announces no change, and frees its
 * buttons again. Also how each membership state reads and when Remove is on.
 *
 * @spec openspec/specs/marketing-lists/spec.md#requirement-a-list-s-sign-ups-are-managed-from-the-list
 */

import { flushPromises, mount } from '@vue/test-utils'
import { beforeEach, describe, expect, it, vi } from 'vitest'

vi.mock('@conduction/nextcloud-vue', () => ({
	CnDialog: { name: 'CnDialog', props: ['name', 'subtitle', 'width'], emits: ['closing'], template: '<div class="cn-dialog"><slot /><slot name="actions" /></div>' },
}))
vi.mock('@nextcloud/vue', () => ({
	NcNoteCard: { name: 'NcNoteCard', props: ['type'], template: '<div class="note" :data-type="type"><slot /></div>' },
	NcButton: { name: 'NcButton', template: '<button v-bind="$attrs"><slot /></button>' },
	NcTextField: { name: 'NcTextField', props: ['modelValue'], emits: ['update:modelValue'], template: '<input class="email" :value="modelValue" @input="$emit(\'update:modelValue\', $event.target.value)">' },
}))
const api = vi.hoisted(() => ({ inviteToList: vi.fn(), unsubscribeContact: vi.fn() }))
vi.mock('../../src/services/mailingListApi.js', () => api)

const { default: ListSignupsManageDialog } = await import('../../src/dialogs/ListSignupsManageDialog.vue')
const fill = (s, v = {}) => s.replace(/\{(\w+)\}/g, (_, k) => v[k])

const ROWS = [
	{ id: 's1', email: 'info@bakkerijdegraaf.example', name: 'Bakkerij De Graaf', contactId: 'c-1', state: 'confirmed' },
	{ id: 's2', email: 'balie@sportcentrum.example', contactId: 'c-2', state: 'pending' },
	{ id: 's3', email: 'oud@lid.example', contactId: 'c-3', state: 'unsubscribed' },
	{ id: 's4', email: 'kapot@adres.example', contactId: 'c-4', state: 'bounced' },
	{ email: 'raar@adres.example', contactId: 'c-5', state: 'archived' },
	{ email: 'leeg@adres.example', contactId: 'c-6', state: '' },
]

function mountModal (subscriptions = ROWS) {
  return mount(ListSignupsManageDialog, {
	props: { listId: 'l-1', subscriptions },
	global: { mocks: { t: (_a, s, v) => fill(s, v) } },
})
}

beforeEach(() => {
	api.inviteToList.mockReset()
	api.unsubscribeContact.mockReset()
})

describe('managing a list\'s sign-ups when it goes wrong', () => {
	it('says no link was sent, keeps the address and announces no change', async () => {
		api.inviteToList.mockRejectedValueOnce(new Error('422'))
		const wrapper = mountModal()
		await wrapper.find('.email').setValue('  geen@adres  ')
		await wrapper.find('[data-testid="list-signups-add"]').trigger('submit')
		await flushPromises()

		expect(api.inviteToList).toHaveBeenCalledWith('l-1', 'geen@adres')
		expect(wrapper.find('.note').attributes('data-type')).toBe('error')
		expect(wrapper.find('.note').text()).toBe('No link was sent. Check the address, and that the list takes sign-ups.')
		expect(wrapper.vm.email).not.toBe('')
		expect(wrapper.emitted('changed')).toBeUndefined()
		expect(wrapper.vm.busy).toBe(false)
	})

	it('says the member stays on the list when removing fails', async () => {
		api.unsubscribeContact.mockRejectedValueOnce(new Error('500'))
		const wrapper = mountModal()
		await wrapper.findAll('[data-testid="list-signups-remove"]')[1].trigger('click')
		await flushPromises()

		expect(api.unsubscribeContact).toHaveBeenCalledWith('c-2', 'l-1', 'Removed from the list by a colleague')
		expect(wrapper.find('.note').attributes('data-type')).toBe('error')
		expect(wrapper.find('.note').text()).toBe('The member could not be taken off the list.')
		expect(wrapper.emitted('changed')).toBeUndefined()
		expect(wrapper.vm.busy).toBe(false)
	})

	it('names a removed member by address when it has no name', async () => {
		api.unsubscribeContact.mockResolvedValueOnce({})
		const wrapper = mountModal()
		await wrapper.findAll('[data-testid="list-signups-remove"]')[1].trigger('click')
		await flushPromises()

		expect(wrapper.find('.note').attributes('data-type')).toBe('success')
		expect(wrapper.find('.note').text()).toBe('balie@sportcentrum.example is off this list.')
	})
})

describe('the sign-up rows', () => {
	it('puts each state in words, and keeps an unknown or empty one readable', () => {
		const states = mountModal().findAll('.list-signups__state').map((cell) => cell.text())
		expect(states).toEqual(['Confirmed', 'Waiting for confirmation', 'Unsubscribed', 'Undeliverable', 'archived', '—'])
	})

	it('only offers Remove for members who still receive or wait for mail', () => {
		const disabled = mountModal().findAll('[data-testid="list-signups-remove"]').map((button) => button.attributes('disabled') !== undefined)
		expect(disabled).toEqual([false, false, true, true, true, true])
	})

	it('keeps Send disabled until an address is typed', async () => {
		const wrapper = mountModal([])
		const send = () => wrapper.find('[data-testid="list-signups-add"] button')
		expect(send().attributes('disabled')).toBeDefined()
		await wrapper.find('.email').setValue('   ')
		expect(send().attributes('disabled')).toBeDefined()
		await wrapper.find('.email').setValue('a@b.example')
		expect(send().attributes('disabled')).toBeUndefined()
	})

	it('closes from its Close button and from the dialog itself', async () => {
		const wrapper = mountModal()
		await wrapper.find('[data-testid="list-signups-close"]').trigger('click')
		wrapper.findComponent({ name: 'CnDialog' }).vm.$emit('closing')
		expect(wrapper.emitted('close')).toHaveLength(2)
	})
})
