// @vitest-environment jsdom
/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 * SPDX-License-Identifier: EUPL-1.2
 *
 * The letter dialog opened outside a routed page, with a store that answers
 * nothing (null) instead of an empty list: the ids come from the props, the
 * contacts, tickets and appointment fall back to none, and the pickers write
 * the option's value into the letter that is asked for.
 *
 * @spec openspec/specs/client-letters/spec.md#requirement-a-user-makes-a-letter-for-a-client-from-a-filinq-template-req-wlt-001
 * @spec openspec/specs/client-letters/spec.md#requirement-a-letter-reads-the-clients-appointment
 */

import { flushPromises, mount } from '@vue/test-utils'
import { beforeAll, beforeEach, describe, expect, it, vi } from 'vitest'
import { h } from 'vue'

const storeMock = vi.hoisted(() => ({
	fetchObject: vi.fn(),
	fetchCollection: vi.fn(),
}))
const api = vi.hoisted(() => ({
	fetchLetterTemplates: vi.fn(),
	makeLetter: vi.fn(),
	downloadLetter: vi.fn(),
	previewLetter: vi.fn(),
	letterPreviewBody: vi.fn((html) => html),
}))

vi.mock('../../src/store/modules/object.js', () => ({
	useObjectStore: () => storeMock,
}))
vi.mock('../../src/services/letters.js', () => api)
vi.mock('@conduction/nextcloud-vue', () => ({
	CnDialog: {
		name: 'CnDialog',
		props: ['name', 'eyebrow', 'width'],
		emits: ['closing'],
		render() {
			return h('div', { class: 'cn-dialog' }, [this.$slots.default?.(), this.$slots.actions?.()])
		},
	},
}))
vi.mock('@nextcloud/vue', () => ({
	NcButton: {
		name: 'NcButton',
		props: { disabled: Boolean },
		emits: ['click'],
		render() {
			return h('button', { disabled: this.disabled, onClick: () => this.$emit('click') }, [
				this.$slots.icon?.(),
				this.$slots.default?.(),
			])
		},
	},
	NcSelect: {
		name: 'NcSelect',
		inheritAttrs: false,
		props: ['modelValue', 'options', 'inputLabel'],
		emits: ['update:modelValue'],
		render() {
			return h('select', { 'aria-label': this.inputLabel, 'data-testid': this.$attrs['data-testid'] })
		},
	},
}))

let Modal

beforeAll(async () => {
	globalThis.t = (app, text, vars) => text.replace(/\{(\w+)\}/g, (m, k) => (vars && k in vars ? vars[k] : m))
	Modal = (await import('../../src/modals/LetterFromTemplateModal.vue')).default
})

beforeEach(() => {
	Object.values(storeMock).forEach((fn) => fn.mockReset())
	Object.values(api).forEach((fn) => fn.mockReset())
	api.letterPreviewBody.mockImplementation((html) => html)
	api.previewLetter.mockResolvedValue({ html: '<p>Beste Anna</p>', fields: [], complete: true, warnings: [] })
	api.fetchLetterTemplates.mockResolvedValue({ available: true, templates: [{ id: 'tpl-1', name: 'Appointment letter' }] })
	storeMock.fetchObject.mockResolvedValue({ id: 'client-1', name: 'Gemeente Voorbeeld' })
})

/**
 * Mount the dialog with props only: there is no router.
 *
 * @param {object} props The dialog's props.
 * @return {object} The wrapper.
 */
function mountWith(props) {
	return mount(Modal, {
		props,
		global: {
			mixins: [{
				methods: {
					t: (app, text, vars) => text.replace(/\{(\w+)\}/g, (m, k) => (vars && k in vars ? vars[k] : m)),
					n: (app, one, many, count) => (count === 1 ? one : many).replace('%n', String(count)),
				},
			}],
		},
	})
}

/**
 * The picker with a test id.
 *
 * @param {object} wrapper The dialog.
 * @param {string} id The test id.
 * @return {object|undefined} The NcSelect stub.
 */
function picker(wrapper, id) {
	return wrapper.findAllComponents({ name: 'NcSelect' }).find((s) => s.attributes('data-testid') === id)
}

describe('the letter dialog without a route and with null answers', () => {
	it('takes the client from the props and treats null lists as empty', async () => {
		storeMock.fetchCollection.mockResolvedValue(null)
		const wrapper = mountWith({ clientId: 'client-1' })
		await flushPromises()

		expect(storeMock.fetchObject).toHaveBeenCalledWith('client', 'client-1')
		expect(storeMock.fetchCollection).toHaveBeenCalledWith('ticket', { client: 'client-1', _limit: 50 })
		expect(storeMock.fetchCollection).toHaveBeenCalledWith('appointmentBooking', { customerId: 'client-1', _limit: 200 })
		expect(wrapper.vm.resolvedClientId).toBe('client-1')
		expect(wrapper.vm.contacts).toEqual([])
		expect(wrapper.vm.tickets).toEqual([])
		expect(wrapper.vm.aboutTicketId).toBe(null)
		expect(wrapper.vm.appointmentId).toBe(null)
		expect(wrapper.vm.error).toBe('')
		expect(picker(wrapper, 'letter-about')).toBeUndefined()
		expect(picker(wrapper, 'letter-contact')).toBeUndefined()
	})

	it('also asks for the ticket\'s bookings and keeps none when that answer is null', async () => {
		storeMock.fetchCollection.mockResolvedValue(null)
		const wrapper = mountWith({ clientId: 'client-1', ticketId: 'ticket-9' })
		await flushPromises()

		expect(storeMock.fetchCollection).toHaveBeenCalledWith('appointmentBooking', { ticket: 'ticket-9', _limit: 50 })
		expect(storeMock.fetchCollection).not.toHaveBeenCalledWith('ticket', expect.anything())
		expect(wrapper.vm.appointmentId).toBe(null)
	})

	it('writes each picker\'s option value into the letter and the preview', async () => {
		storeMock.fetchCollection.mockImplementation(async (type) => {
			if (type === 'contact') return [{ id: 'contact-1', name: 'Anna de Jong' }]
			if (type === 'ticket') return [{ id: 'ticket-1', reference: 'PQ-1', title: 'Parkeren' }, { id: 'ticket-2', title: 'Afval' }]
			return []
		})
		const wrapper = mountWith({ clientId: 'client-1' })
		await flushPromises()

		const template = picker(wrapper, 'letter-template')
		expect(template.vm.$attrs.reduce({ value: 'tpl-1', label: 'Appointment letter' })).toBe('tpl-1')
		await template.vm.$emit('update:modelValue', 'tpl-1')
		const about = picker(wrapper, 'letter-about')
		expect(about.props('options')).toEqual([{ value: 'ticket-1', label: 'PQ-1 Parkeren' }, { value: 'ticket-2', label: 'Afval' }])
		expect(about.vm.$attrs.reduce({ value: 'ticket-2' })).toBe('ticket-2')
		await about.vm.$emit('update:modelValue', 'ticket-2')
		const contact = picker(wrapper, 'letter-contact')
		expect(contact.vm.$attrs.reduce({ value: 'contact-1' })).toBe('contact-1')
		await contact.vm.$emit('update:modelValue', 'contact-1')
		await flushPromises()

		const channel = picker(wrapper, 'letter-channel')
		expect(channel.props('options')).toEqual([{ value: 'pdf', label: 'PDF to print and post' }])
		expect(channel.vm.$attrs.reduce({ value: 'pdf' })).toBe('pdf')
		await channel.vm.$emit('update:modelValue', 'pdf')
		expect(wrapper.vm.channel).toBe('pdf')
		expect(wrapper.vm.templateId).toBe('tpl-1')
		expect(wrapper.vm.aboutTicketId).toBe('ticket-2')
		expect(wrapper.vm.contactId).toBe('contact-1')
		expect(api.previewLetter).toHaveBeenLastCalledWith('client-1', { templateId: 'tpl-1', contactId: 'contact-1', ticketId: 'ticket-2', appointmentId: undefined })
	})

	it('shows the action icons and closes when the dialog is dismissed', async () => {
		storeMock.fetchCollection.mockResolvedValue([])
		const wrapper = mountWith({ clientId: 'client-1' })
		await flushPromises()

		expect(wrapper.find('[data-testid="letter-download"] svg, .letter-modal__download svg').exists()).toBe(true)
		await wrapper.findComponent({ name: 'CnDialog' }).vm.$emit('closing')

		expect(wrapper.emitted('close')).toHaveLength(1)
	})
})
