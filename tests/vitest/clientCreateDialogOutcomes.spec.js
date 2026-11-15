// @vitest-environment jsdom
/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 * SPDX-License-Identifier: EUPL-1.2
 *
 * The New client dialog's footer and its failed saves: Cancel and the dialog
 * close it, Save runs the form's own save, a client saved under `@self`
 * still opens, and a failed save says why and leaves the dialog open.
 *
 * @spec openspec/specs/client-forms/spec.md#requirement-one-client-dialog-that-returns-to-the-form-that-opened-it
 */

import { flushPromises, mount } from '@vue/test-utils'
import { beforeEach, describe, expect, it, vi } from 'vitest'
import { h } from 'vue'

const createWithContact = vi.hoisted(() => vi.fn())
const dialogs = vi.hoisted(() => ({ showError: vi.fn() }))
const formSave = vi.hoisted(() => vi.fn())
vi.mock('../../src/services/contactSyncApi.js', () => ({ createWithContact }))
vi.mock('@nextcloud/dialogs', () => dialogs)
vi.mock('@conduction/nextcloud-vue', () => ({
	CnDialog: { name: 'CnDialog', emits: ['closing'], render() { return h('div', [this.$slots.default?.(), this.$slots.actions?.()]) } },
}))
vi.mock('@nextcloud/vue', () => ({
	NcButton: { props: ['disabled'], emits: ['click'], render() { return h('button', { disabled: this.disabled, onClick: () => this.$emit('click') }, this.$slots.default?.()) } },
}))
vi.mock('../../src/views/clients/ClientForm.vue', () => ({
	default: { name: 'ClientForm', props: ['initialName', 'initialValues', 'showActions', 'boardLayout'], emits: ['save', 'update:valid'], methods: { onSave: formSave }, render: () => h('div', { class: 'client-form' }) },
}))

globalThis.t = (_app, text) => text

const { default: ClientCreateDialog } = await import('../../src/dialogs/ClientCreateDialog.vue')

function mountDialog(props = {}) {
	const push = vi.fn(() => Promise.resolve())
	const wrapper = mount(ClientCreateDialog, { props, global: { mocks: { t: (_app, text) => text, $router: { push } } } })
	return { wrapper, push }
}
const save = (wrapper) => wrapper.find('[data-testid="client-form-save"]')

beforeEach(() => {
	createWithContact.mockReset()
	dialogs.showError.mockReset()
	formSave.mockReset()
})

describe('the New client footer', () => {
	it('closes from Cancel and from the dialog itself', async () => {
		const { wrapper } = mountDialog()
		await wrapper.find('[data-testid="client-create-cancel"]').trigger('click')
		wrapper.findComponent({ name: 'CnDialog' }).vm.$emit('closing')
		expect(wrapper.emitted('close')).toHaveLength(2)
	})

	it('enables Save once the form is valid, and runs the form\'s own save', async () => {
		const { wrapper } = mountDialog()
		expect(save(wrapper).attributes('disabled')).toBeDefined()
		wrapper.findComponent({ name: 'ClientForm' }).vm.$emit('update:valid', true)
		await flushPromises()
		expect(save(wrapper).attributes('disabled')).toBeUndefined()
		await save(wrapper).trigger('click')
		expect(formSave).toHaveBeenCalledTimes(1)
	})

	it('hands the form the values to start with, without turning it into a picker', () => {
		const { wrapper } = mountDialog({ values: { email: 'a@b.example' } })
		const form = wrapper.findComponent({ name: 'ClientForm' })
		expect(form.props('initialValues')).toEqual({ email: 'a@b.example' })
		expect(form.props('initialName')).toBe('')
		expect(wrapper.vm.returnsToForm).toBe(false)
	})
})

describe('saving a new client', () => {
	it('opens a client the server returns under @self', async () => {
		createWithContact.mockResolvedValueOnce({ '@self': { id: 'c-9' }, name: 'Gamma' })
		const { wrapper, push } = mountDialog()
		await wrapper.vm.onSave({ name: 'Gamma' })
		await flushPromises()

		expect(wrapper.emitted('created')[0][0]).toBe('c-9')
		expect(push).toHaveBeenCalledWith({ name: 'ClientDetail', params: { id: 'c-9' } })
		expect(wrapper.vm.saving).toBe(false)
	})

	it('says it failed when the server returns no id, and stays open', async () => {
		createWithContact.mockResolvedValueOnce({})
		const { wrapper, push } = mountDialog()
		await wrapper.vm.onSave({ name: 'Delta' })

		expect(dialogs.showError).toHaveBeenCalledWith('Failed to create client.')
		expect(wrapper.emitted('created')).toBeUndefined()
		expect(wrapper.emitted('close')).toBeUndefined()
		expect(push).not.toHaveBeenCalled()
		expect(wrapper.vm.saving).toBe(false)
	})

	it('shows the server\'s own error when the save is refused', async () => {
		createWithContact.mockRejectedValueOnce({ response: { data: { error: 'The email address is already in use' } } })
		const { wrapper } = mountDialog()
		await wrapper.vm.onSave({ name: 'Echo' })

		expect(dialogs.showError).toHaveBeenCalledWith('The email address is already in use')
		expect(wrapper.emitted('close')).toBeUndefined()
		expect(wrapper.vm.saving).toBe(false)
	})

	it('falls back to a generic error when the failure carries none', async () => {
		createWithContact.mockRejectedValueOnce(new Error('Network Error'))
		const { wrapper } = mountDialog()
		await wrapper.vm.onSave({ name: 'Foxtrot' })

		expect(dialogs.showError).toHaveBeenCalledWith('Failed to create client.')
	})
})
