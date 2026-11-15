// @vitest-environment jsdom
/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 * SPDX-License-Identifier: EUPL-1.2
 *
 * The New request dialog's footer (board PqNieuwVerzoek): Cancel and the
 * dialog close it, Create runs the form's own save, "Open after creating"
 * decides between opening the ticket and staying, a request is the ticket
 * type when none is picked, and a failed save says why.
 */

import { flushPromises, mount } from '@vue/test-utils'
import { beforeEach, describe, expect, it, vi } from 'vitest'
import { h } from 'vue'

const dialogs = vi.hoisted(() => ({ showError: vi.fn(), showSuccess: vi.fn() }))
const store = vi.hoisted(() => ({ saveObject: vi.fn(), getError: vi.fn() }))
const formSave = vi.hoisted(() => vi.fn())
vi.mock('@nextcloud/dialogs', () => dialogs)
vi.mock('../../src/store/modules/object.js', () => ({ useObjectStore: () => store }))
vi.mock('@conduction/nextcloud-vue', () => ({
	CnDialog: { name: 'CnDialog', emits: ['closing'], render() { return h('div', [this.$slots.default?.(), this.$slots.actions?.()]) } },
}))
vi.mock('@nextcloud/vue', () => ({
	NcButton: { props: ['disabled'], emits: ['click'], render() { return h('button', { disabled: this.disabled, onClick: () => this.$emit('click') }, this.$slots.default?.()) } },
	NcCheckboxRadioSwitch: {
		props: ['modelValue'],
		emits: ['update:modelValue'],
		render() { return h('input', { type: 'checkbox', checked: this.modelValue, onChange: () => this.$emit('update:modelValue', !this.modelValue) }) },
	},
}))
vi.mock('../../src/views/requests/RequestForm.vue', () => ({
	default: { name: 'RequestForm', props: ['preLinkedClient', 'showActions', 'boardLayout'], emits: ['save', 'update:valid'], methods: { onSave: formSave }, render: () => h('div') },
}))

globalThis.t = (_app, text) => text

const { default: RequestCreateDialog } = await import('../../src/dialogs/RequestCreateDialog.vue')

function mountDialog() {
	const push = vi.fn(() => Promise.resolve())
	const wrapper = mount(RequestCreateDialog, { global: { mocks: { t: (_app, text) => text, $router: { push } } } })
	return { wrapper, push }
}
const testid = (wrapper, id) => wrapper.find(`[data-testid="${id}"]`)

beforeEach(() => {
	dialogs.showError.mockReset()
	dialogs.showSuccess.mockReset()
	store.saveObject.mockReset()
	store.getError.mockReset()
	formSave.mockReset()
})

describe('the New request footer', () => {
	it('closes from Cancel and from the dialog itself', async () => {
		const { wrapper } = mountDialog()
		await testid(wrapper, 'request-create-cancel').trigger('click')
		wrapper.findComponent({ name: 'CnDialog' }).vm.$emit('closing')
		expect(wrapper.emitted('close')).toHaveLength(2)
	})

	it('enables Create once the form is valid, and runs the form\'s own save', async () => {
		const { wrapper } = mountDialog()
		expect(testid(wrapper, 'request-create-save').attributes('disabled')).toBeDefined()
		wrapper.findComponent({ name: 'RequestForm' }).vm.$emit('update:valid', true)
		await flushPromises()
		await testid(wrapper, 'request-create-save').trigger('click')
		expect(formSave).toHaveBeenCalledTimes(1)
		expect(testid(wrapper, 'request-create-save').text()).toBe('Create request')
	})
})

describe('creating a request', () => {
	it('saves a request ticket and opens it', async () => {
		store.saveObject.mockResolvedValueOnce({ id: 't-1' })
		const { wrapper, push } = mountDialog()
		await wrapper.vm.onSave({ title: 'Lantaarnpaal kapot' })
		await flushPromises()

		expect(store.saveObject).toHaveBeenCalledWith('ticket', { title: 'Lantaarnpaal kapot', ticketType: 'request' })
		expect(wrapper.emitted('created')).toEqual([['t-1']])
		expect(push).toHaveBeenCalledWith({ name: 'TicketDetail', params: { id: 't-1' } })
		expect(dialogs.showSuccess).not.toHaveBeenCalled()
	})

	it('keeps a picked ticket type and stays on the page with Open after creating off', async () => {
		store.saveObject.mockResolvedValueOnce({ id: 't-2' })
		const { wrapper, push } = mountDialog()
		await testid(wrapper, 'request-create-open-after').trigger('change')
		await wrapper.vm.onSave({ title: 'Klacht', ticketType: 'complaint' })

		expect(store.saveObject).toHaveBeenCalledWith('ticket', { title: 'Klacht', ticketType: 'complaint' })
		expect(dialogs.showSuccess).toHaveBeenCalledWith('The ticket is created.')
		expect(wrapper.emitted('close')).toHaveLength(1)
		expect(push).not.toHaveBeenCalled()
	})

	it('shows the store\'s error when nothing is saved, and stays open', async () => {
		store.saveObject.mockResolvedValueOnce(null)
		store.getError.mockReturnValueOnce({ message: 'Title is required' })
		const { wrapper } = mountDialog()
		await wrapper.vm.onSave({})

		expect(store.getError).toHaveBeenCalledWith('ticket')
		expect(dialogs.showError).toHaveBeenCalledWith('Title is required')
		expect(wrapper.emitted('created')).toBeUndefined()
		expect(wrapper.emitted('close')).toBeUndefined()
		expect(wrapper.vm.saving).toBe(false)
	})

	it('falls back to a generic error when the store has none', async () => {
		store.saveObject.mockResolvedValueOnce(null)
		store.getError.mockReturnValueOnce(null)
		const { wrapper } = mountDialog()
		await wrapper.vm.onSave({})

		expect(dialogs.showError).toHaveBeenCalledWith('Failed to create request.')
	})
})
