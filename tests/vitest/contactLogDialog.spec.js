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
 * @spec openspec/changes/contact-log-dialog/specs/contact-log-dialog/spec.md#requirement-a-contact-moment-is-logged-from-the-ticket-in-its-own-dialog
 */
import { flushPromises, mount } from '@vue/test-utils'
import { describe, expect, it, vi } from 'vitest'
import { h } from 'vue'

const store = vi.hoisted(() => ({
	fetchObject: vi.fn((type) => Promise.resolve(type === 'ticket'
		? { id: 't-1', reference: 'PQ-2026-0417', title: 'Vraag over vergunning straatfeest Parkstraat', client: 'c-1' }
		: { id: 'c-1', name: 'Sanne de Vries' })),
	saveObject: vi.fn(() => Promise.resolve({ id: 'cm-1' })),
}))
vi.mock('../../src/store/modules/object.js', () => ({ useObjectStore: () => store }))
vi.mock('@nextcloud/event-bus', () => ({ emit: vi.fn() }))
vi.mock('@nextcloud/vue', () => ({
	NcButton: { props: ['disabled'], emits: ['click'], render() { return h('button', { disabled: this.disabled, onClick: () => this.$emit('click') }, [this.$slots.icon?.(), this.$slots.default?.()]) } },
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

	it('draws the customer as a full-width field box and the save button with an icon', async () => {
		const w = await open()
		expect(w.find('[data-testid="contact-log-client"]').classes()).toContain('contact-log__control')
		expect(w.find('[data-testid="contact-log-save"] .contact-log__save-icon').exists()).toBe(true)
	})
})
