// @vitest-environment jsdom
/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 * SPDX-License-Identifier: EUPL-1.2
 *
 * The contact create dialog opened on its own with proposed values (Paste or
 * scan): only non-blank text fields seed the form, a typed name and client
 * win over them, and a saved contact closes the dialog and opens the new
 * contact. Opened from a picker (no values) it stays put.
 *
 * @spec openspec/specs/lead-management/spec.md#requirement-linked-party-selection-on-the-create-form-mvp
 */
import { flushPromises, mount } from '@vue/test-utils'
import { beforeEach, describe, expect, it, vi } from 'vitest'
import { h } from 'vue'

const mocks = vi.hoisted(() => ({ createWithContact: vi.fn(), showError: vi.fn() }))
vi.mock('@nextcloud/dialogs', () => ({ showError: (...a) => mocks.showError(...a) }))
vi.mock('../../src/services/contactSyncApi.js', () => ({ createWithContact: (...a) => mocks.createWithContact(...a) }))
vi.mock('@conduction/nextcloud-vue', () => ({
	CnDialog: { name: 'CnDialog', props: ['name'], emits: ['closing'], render() { return h('div', this.$slots.default?.()) } },
}))
vi.mock('../../src/views/contacts/ContactForm.vue', () => ({
	default: { name: 'ContactForm', props: ['contact', 'preSelectedClient'], emits: ['save', 'cancel'], render: () => h('form') },
}))

globalThis.t = (app, text) => text
const { default: Dialog } = await import('../../src/dialogs/ContactCreateDialog.vue')

/**
 * Mount the dialog with an optional router.
 *
 * @param {object} props The dialog props.
 * @param {object|undefined} router The $router mock, or undefined for none.
 * @return {object} The wrapper.
 */
function open(props, router) {
	return mount(Dialog, { props, global: { mocks: { t: globalThis.t, ...(router ? { $router: router } : {}) } } })
}

beforeEach(() => {
	mocks.createWithContact.mockReset()
	mocks.showError.mockReset()
})

describe('ContactCreateDialog with proposed values', () => {
	it('seeds only the non-blank text fields it knows', () => {
		const w = open({ values: { name: 'Ann Smit', role: '  ', email: 'ann@example.org', phone: 612345678, notes: 'ignored' } })
		expect(w.findComponent({ name: 'ContactForm' }).props('contact')).toEqual({ name: 'Ann Smit', email: 'ann@example.org' })
	})

	it('lets the typed name and the parent client win over the proposal', () => {
		const w = open({ values: { name: 'Proposed', phone: '0201234567' }, name: 'Typed', client: 'client-2' })
		expect(w.findComponent({ name: 'ContactForm' }).props('contact')).toEqual({ name: 'Typed', phone: '0201234567', client: 'client-2' })
	})

	it('closes and opens the new contact after saving', async () => {
		const router = { push: vi.fn(() => Promise.resolve()) }
		mocks.createWithContact.mockResolvedValue({ id: 'c-42', name: 'Ann' })
		const w = open({ values: { name: 'Ann' } }, router)
		w.findComponent({ name: 'ContactForm' }).vm.$emit('save', { name: 'Ann' })
		await flushPromises()
		expect(w.emitted('created')).toEqual([[{ id: 'c-42', name: 'Ann' }]])
		expect(w.emitted('close')).toHaveLength(1)
		expect(router.push).toHaveBeenCalledWith({ name: 'ContactDetail', params: { id: 'c-42' } })
		expect(mocks.showError).not.toHaveBeenCalled()
	})

	it('swallows a refused navigation and still closes', async () => {
		const router = { push: vi.fn(() => Promise.reject(new Error('redundant'))) }
		mocks.createWithContact.mockResolvedValue({ '@self': { id: 'c-7' } })
		const w = open({ values: {} }, router)
		await w.vm.onSave({})
		await flushPromises()
		expect(router.push).toHaveBeenCalledWith({ name: 'ContactDetail', params: { id: 'c-7' } })
		expect(w.emitted('close')).toHaveLength(1)
		expect(mocks.showError).not.toHaveBeenCalled()
	})

	it('closes without a router to navigate with', async () => {
		mocks.createWithContact.mockResolvedValue({ id: 'c-8' })
		const w = open({ values: { name: 'Bo' } })
		await w.vm.onSave({ name: 'Bo' })
		expect(w.emitted('close')).toHaveLength(1)
	})

	it('neither closes nor navigates when opened from a picker', async () => {
		const router = { push: vi.fn() }
		mocks.createWithContact.mockResolvedValue({ id: 'c-9' })
		const w = open({ name: 'Cas' }, router)
		await w.vm.onSave({ name: 'Cas' })
		expect(w.emitted('created')).toHaveLength(1)
		expect(w.emitted('close')).toBeUndefined()
		expect(router.push).not.toHaveBeenCalled()
	})
})
