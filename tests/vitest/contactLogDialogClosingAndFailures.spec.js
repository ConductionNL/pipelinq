// @vitest-environment jsdom
/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 * SPDX-License-Identifier: EUPL-1.2
 *
 * The contact log dialog's ways out and its failed saves: Cancel and closing
 * the dialog leave without saving or reloading the page, a refused save says
 * so and keeps the dialog open, an ended session says how to continue instead,
 * and an empty subject saves nothing. A cleared duration and an unreadable
 * date do not reach the saved contact moment.
 *
 * @spec openspec/specs/contact-log-dialog/spec.md#requirement-a-contact-moment-is-logged-from-the-ticket-in-its-own-dialog
 */
import { flushPromises, mount } from '@vue/test-utils'
import { beforeEach, describe, expect, it, vi } from 'vitest'
import { h } from 'vue'

const store = vi.hoisted(() => ({
	fetchObject: vi.fn(),
	saveObject: vi.fn(),
	getError: vi.fn(() => null),
	fetchCollectionForOptions: vi.fn(() => Promise.resolve([])),
}))
const bus = vi.hoisted(() => ({ emit: vi.fn() }))
vi.mock('../../src/store/modules/object.js', () => ({ useObjectStore: () => store }))
vi.mock('@nextcloud/event-bus', () => bus)
vi.mock('@nextcloud/router', () => ({ generateUrl: (path) => '/index.php' + path }))
vi.mock('@nextcloud/vue', () => ({
	NcButton: { props: ['disabled'], emits: ['click'], render() { return h('button', { disabled: this.disabled, onClick: () => this.$emit('click') }, this.$slots.default?.()) } },
	NcSelect: { name: 'NcSelect', props: ['modelValue', 'options'], emits: ['update:modelValue', 'search'], render: () => h('div') },
}))
vi.mock('@conduction/nextcloud-vue', () => ({
	CnDurationField: { name: 'CnDurationField', props: ['modelValue'], emits: ['update:modelValue'], render: () => h('div') },
	CnDialog: { name: 'CnDialog', props: ['name', 'eyebrow', 'subtitle', 'width'], emits: ['closing'], render() { return h('div', [this.$slots.default?.(), this.$slots.actions?.()]) } },
}))

const { default: Dialog } = await import('../../src/dialogs/ContactLogDialog.vue')

/**
 * Mount the dialog on ticket t-1 and let it load.
 *
 * @return {Promise<object>} The wrapper.
 */
async function open() {
	const w = mount(Dialog, { global: { mocks: { $route: { params: { id: 't-1' } } } } })
	await flushPromises()
	return w
}

/**
 * Type a subject and press Save.
 *
 * @param {object} w The wrapper.
 * @param {string} subject The subject.
 * @return {Promise<void>}
 */
async function saveWith(w, subject) {
	await w.find('[data-testid="contact-log-subject"]').setValue(subject)
	await w.find('[data-testid="contact-log-save"]').trigger('click')
	await flushPromises()
}

beforeEach(() => {
	store.fetchObject.mockReset()
	store.fetchObject.mockResolvedValue({ id: 't-1', title: 'Question', client: '' })
	store.saveObject.mockReset()
	store.getError.mockReset()
	store.getError.mockReturnValue(null)
	bus.emit.mockReset()
})

describe('ContactLogDialog closing and failures', () => {
	it('leaves from Cancel and from closing the dialog without saving', async () => {
		const w = await open()
		await w.find('[data-testid="contact-log-cancel"]').trigger('click')
		w.findComponent({ name: 'CnDialog' }).vm.$emit('closing')
		expect(w.emitted('close')).toHaveLength(2)
		expect(store.saveObject).not.toHaveBeenCalled()
		expect(bus.emit).not.toHaveBeenCalled()
	})

	it('names only the contact moment for a ticket without a reference', async () => {
		const w = await open()
		expect(w.findComponent({ name: 'CnDialog' }).props('eyebrow')).toBe('Contact moment')
		expect(w.find('[data-testid="contact-log-parent"] strong').text()).toBe('')
	})

	it('says a refused save was not saved and stays open', async () => {
		store.saveObject.mockResolvedValue(null)
		store.getError.mockReturnValue({ status: 422, message: 'invalid' })
		const w = await open()
		await saveWith(w, 'Called back')
		expect(w.find('.contact-log__error[role="alert"]').text()).toBe('The contact moment is not saved. Try again.')
		expect(w.emitted('close')).toBeUndefined()
		expect(bus.emit).not.toHaveBeenCalled()
		expect(w.find('[data-testid="contact-log-save"]').attributes('disabled')).toBeUndefined()
	})

	it('says the session ended instead of the generic error', async () => {
		store.saveObject.mockResolvedValue(null)
		store.getError.mockReturnValue({ status: 401, message: 'unauthorised' })
		const w = await open()
		await saveWith(w, 'Called back')
		expect(w.find('[data-testid="contact-log-session-ended"]').text()).toBe('Your session has ended. Log in again in a new tab, then press Save here.')
		expect(w.findAll('.contact-log__error')).toHaveLength(1)
		expect(w.emitted('close')).toBeUndefined()
	})

	it('saves nothing for a blank subject submitted from the form', async () => {
		const w = await open()
		await w.find('[data-testid="contact-log-subject"]').setValue('   ')
		await w.find('form').trigger('submit')
		await flushPromises()
		expect(store.saveObject).not.toHaveBeenCalled()
	})

	it('leaves a cleared duration out and saves now for an unreadable date', async () => {
		store.saveObject.mockResolvedValue({ id: 'cm-1' })
		const w = await open()
		const duration = w.findComponent({ name: 'CnDurationField' })
		duration.vm.$emit('update:modelValue', 'PT2M')
		duration.vm.$emit('update:modelValue', null)
		w.vm.form.date = 'not-a-date'
		const before = Date.now()
		await saveWith(w, '  Called back  ')
		const [, payload] = store.saveObject.mock.calls[0]
		expect(payload).not.toHaveProperty('duration')
		expect(payload).not.toHaveProperty('client')
		expect(payload.title).toBe('Called back')
		expect(Date.parse(payload.occurredAt)).toBeGreaterThanOrEqual(before - 1000)
		expect(bus.emit).toHaveBeenCalledWith('cn:page:refresh', {})
		expect(w.emitted('close')).toHaveLength(1)
	})
})
