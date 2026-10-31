// @vitest-environment jsdom
/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Board PqTicketContactLoggen: the contact log is its own dialog: the ticket
 * it belongs to, the channel, inbound or outbound, the date and time filled
 * with now, the duration, the subject, the notes and the customer. It saves a
 * contact moment under the ticket, for the ticket's customer.
 *
 * @spec openspec/specs/contact-log-dialog/spec.md#requirement-a-contact-moment-is-logged-from-the-ticket-in-its-own-dialog
 */
import { flushPromises, mount } from '@vue/test-utils'
import { readFileSync } from 'fs'
import { resolve } from 'path'
import { describe, expect, it, vi } from 'vitest'
import { h } from 'vue'

const store = vi.hoisted(() => ({
	fetchObject: vi.fn((type) => Promise.resolve(type === 'ticket'
		? { id: 't-1', reference: 'PQ-2026-0417', title: 'Vraag over vergunning straatfeest Parkstraat', client: 'c-1' }
		: { id: 'c-1', name: 'Sanne de Vries' })),
	saveObject: vi.fn(() => Promise.resolve({ id: 'cm-1' })),
	fetchCollectionForOptions: vi.fn(() => Promise.resolve([
		{ id: 'c-1', name: 'Sanne de Vries' },
		{ id: 'c-2', name: 'Bakkerij De Graaf' },
	])),
}))
vi.mock('../../src/store/modules/object.js', () => ({ useObjectStore: () => store }))
vi.mock('@nextcloud/event-bus', () => ({ emit: vi.fn() }))
vi.mock('@nextcloud/vue', () => ({
	NcButton: { props: ['disabled'], emits: ['click'], render() { return h('button', { disabled: this.disabled, onClick: () => this.$emit('click') }, [this.$slots.icon?.(), this.$slots.default?.()]) } },
	NcSelect: {
		name: 'NcSelect',
		props: ['modelValue', 'options', 'ariaLabelCombobox', 'clearable', 'filterable'],
		emits: ['update:modelValue', 'search'],
		render() { return h('div', { class: 'nc-select-stub' }, [this.modelValue ? this.$slots['selected-option']?.(this.modelValue) : null]) },
	},
}))
vi.mock('@conduction/nextcloud-vue', () => ({
	CnDialog: { props: ['name', 'eyebrow', 'subtitle', 'width'], render() { return h('div', { 'data-eyebrow': this.eyebrow }, [this.$slots.default?.(), this.$slots.actions?.()]) } },
}))

const { default: Dialog } = await import('../../src/dialogs/ContactLogDialog.vue')

/**
 * Mount the dialog on the ticket page.
 *
 * @return {Promise<object>} The wrapper.
 */
async function open() {
	const w = mount(Dialog, { global: { mocks: { $route: { params: { id: 't-1' } } } } })
	await flushPromises()
	return w
}

describe('ContactLogDialog', () => {
	it('names the ticket and the customer, and fills the time with now', async () => {
		const w = await open()
		expect(w.find('[data-eyebrow]').attributes('data-eyebrow')).toBe('Contact moment · PQ-2026-0417')
		expect(w.find('[data-testid="contact-log-parent"]').text()).toContain('PQ-2026-0417')
		expect(w.find('[data-testid="contact-log-client"]').text()).toContain('Sanne de Vries')
		expect(w.text()).toContain('Filled in with now')
		expect(w.find('[data-testid="contact-log-direction-inbound"]').element.checked).toBe(true)
	})

	it('saves a contact moment under the ticket, for its customer', async () => {
		const w = await open()
		await w.find('[data-testid="contact-log-channel"]').setValue('balie')
		await w.find('[data-testid="contact-log-direction-outbound"]').setValue(true)
		await w.find('[data-testid="contact-log-date"]').setValue('2026-10-04')
		await w.find('[data-testid="contact-log-time"]').setValue('10:24')
		await w.find('[data-testid="contact-log-subject"]').setValue('Teruggebeld over het podium')
		await w.find('[data-testid="contact-log-notes"]').setValue('Vergunning is nodig.')
		await w.find('[data-testid="contact-log-save"]').trigger('click')
		await flushPromises()
		const [type, payload] = store.saveObject.mock.calls[0]
		expect(type).toBe('ticket')
		expect(payload).toMatchObject({
			ticketType: 'interaction',
			parentTicket: 't-1',
			client: 'c-1',
			channel: 'balie',
			direction: 'outbound',
			title: 'Teruggebeld over het podium',
			notes: 'Vergunning is nodig.',
		})
		expect(new Date(payload.occurredAt).getHours()).toBe(10)
		expect(w.emitted('close')).toHaveLength(1)
	})

	it('initials the customer by first and last name, as the board ("SV")', async () => {
		const w = await open()
		expect(w.find('.contact-log__initials').text()).toBe('SV')
	})

	it('names the date field When, as the board labels it', async () => {
		const w = await open()
		expect(w.find('[data-testid="contact-log-date"]').attributes('aria-label')).toBe('When')
	})

	it('sets the ticket note in a tinted block with a link icon', async () => {
		const w = await open()
		const note = w.find('[data-testid="contact-log-parent"]')
		expect(note.classes()).toContain('contact-log__parent--block')
		expect(note.find('.contact-log__parent-icon').exists()).toBe(true)
	})

	it('draws the save button with an icon', async () => {
		const w = await open()
		expect(w.find('[data-testid="contact-log-save"] .contact-log__save-icon').exists()).toBe(true)
	})

	it('draws the customer as a picker that starts on the ticket\'s customer', async () => {
		const w = await open()
		const picker = w.findComponent({ name: 'NcSelect' })
		expect(picker.exists()).toBe(true)
		expect(picker.props('modelValue')).toMatchObject({ id: 'c-1', label: 'Sanne de Vries' })
		expect(picker.props('ariaLabelCombobox')).toBe('Customer')
		expect(picker.props('clearable')).toBe(false)
		expect(picker.props('options').map((o) => o.id)).toEqual(['c-1', 'c-2'])
	})

	it('saves the contact moment for the customer picked in the dialog', async () => {
		store.saveObject.mockClear()
		const w = await open()
		const picker = w.findComponent({ name: 'NcSelect' })
		picker.vm.$emit('update:modelValue', { id: 'c-2', label: 'Bakkerij De Graaf' })
		await w.find('[data-testid="contact-log-subject"]').setValue('Vraag over de bestelling')
		await w.find('[data-testid="contact-log-save"]').trigger('click')
		await flushPromises()
		expect(store.saveObject.mock.calls[0][1].client).toBe('c-2')
	})

	it('searches the clients by what is typed in the picker', async () => {
		const w = await open()
		store.fetchCollectionForOptions.mockClear()
		w.findComponent({ name: 'NcSelect' }).vm.$emit('search', 'Graaf')
		await flushPromises()
		expect(store.fetchCollectionForOptions).toHaveBeenCalledWith('client', expect.objectContaining({ _search: 'Graaf' }))
	})
})

describe('ContactLogDialog style', () => {
	it('sets the ticket note on the sunken ground with the 8px control radius, as the board', () => {
		const source = readFileSync(resolve(__dirname, '../../src/dialogs/ContactLogDialog.vue'), 'utf8')
		const at = source.indexOf('.contact-log__parent {')
		const rule = source.slice(at, source.indexOf('}', at))
		expect(rule).toContain('background: var(--cn-board-sunken-ground')
		expect(rule).toContain('border-radius: var(--cn-board-control-radius, 8px)')
	})
})
