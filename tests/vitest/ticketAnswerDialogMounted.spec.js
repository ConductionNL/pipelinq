// @vitest-environment jsdom
/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 * SPDX-License-Identifier: EUPL-1.2
 *
 * The ticket answer dialog, mounted: its header names the ticket and client,
 * Send answer follows the reply section's state and asks it to save, and
 * every way out asks the page to reload.
 *
 * @spec openspec/changes/simple-ticket-page/specs/ticket-detail-page/spec.md#REQ-TDP-001
 */
import { flushPromises, mount } from '@vue/test-utils'
import { beforeEach, describe, expect, it, vi } from 'vitest'
import { h } from 'vue'

const bus = vi.hoisted(() => ({ emit: vi.fn() }))
const dialogs = vi.hoisted(() => ({ showSuccess: vi.fn() }))
const store = vi.hoisted(() => ({ fetchObject: vi.fn() }))
const save = vi.hoisted(() => vi.fn())
vi.mock('@nextcloud/event-bus', () => bus)
vi.mock('@nextcloud/dialogs', () => dialogs)
vi.mock('../../src/store/modules/object.js', () => ({ useObjectStore: () => store }))
vi.mock('../../src/components/CustomerReplySection.vue', () => ({
	default: { name: 'CustomerReplySection', props: ['objectId', 'inDialog'], emits: ['state', 'saved'], methods: { save }, render: () => h('section') },
}))
vi.mock('@nextcloud/vue', () => ({
	NcButton: { props: ['disabled'], emits: ['click'], render() { return h('button', { disabled: this.disabled, onClick: () => this.$emit('click') }, this.$slots.default?.()) } },
}))
vi.mock('@conduction/nextcloud-vue', () => ({
	CnDialog: { name: 'CnDialog', props: ['name', 'eyebrow', 'subtitle'], emits: ['closing'], render() { return h('div', [this.$slots.default?.(), this.$slots.actions?.()]) } },
}))

const { default: Dialog } = await import('../../src/dialogs/TicketAnswerDialog.vue')

async function open (id) {
	const w = mount(Dialog, { global: { mocks: { $route: { params: { id } } } } })
	await flushPromises()
	return w
}

beforeEach(() => {
	bus.emit.mockReset()
	dialogs.showSuccess.mockReset()
	store.fetchObject.mockReset()
	save.mockReset()
})

describe('TicketAnswerDialog mounted', () => {
	it('names the ticket and its client in the header', async () => {
		store.fetchObject.mockImplementation(async (type) => (type === 'ticket'
			? { reference: 'T-12', title: 'Lost card', client: 'c-1' }
			: { name: 'Jan Jansen' }))
		const w = await open('t-1')
		const dialog = w.findComponent({ name: 'CnDialog' })
		expect(store.fetchObject).toHaveBeenCalledWith('ticket', 't-1')
		expect(store.fetchObject).toHaveBeenCalledWith('client', 'c-1')
		expect(dialog.props('eyebrow')).toBe('Answer · T-12')
		expect(dialog.props('subtitle')).toBe('Jan Jansen · T-12 · Lost card')
		expect(w.findComponent({ name: 'CustomerReplySection' }).props('objectId')).toBe('t-1')
	})

	it('shows no reply section and loads nothing without a ticket in the route', async () => {
		const w = await open(undefined)
		expect(w.findComponent({ name: 'CustomerReplySection' }).exists()).toBe(false)
		expect(store.fetchObject).not.toHaveBeenCalled()
		expect(w.findComponent({ name: 'CnDialog' }).props('eyebrow')).toBe('Answer')
	})

	it('enables Send answer from the reply state and saves with its wait choice', async () => {
		store.fetchObject.mockResolvedValue(null)
		const w = await open('t-1')
		const send = w.find('[data-testid="ticket-answer-send"]')
		expect(send.attributes('disabled')).toBeDefined()

		await w.findComponent({ name: 'CustomerReplySection' }).vm.$emit('state', { canSave: true, busy: false, waitForCustomer: false })
		expect(send.attributes('disabled')).toBeUndefined()
		await send.trigger('click')
		expect(save).toHaveBeenCalledWith(false)

		await w.findComponent({ name: 'CustomerReplySection' }).vm.$emit('state', { canSave: true, busy: true, waitForCustomer: true })
		expect(send.attributes('disabled')).toBeDefined()
	})

	it('confirms a saved answer and closes, asking the page to reload', async () => {
		store.fetchObject.mockResolvedValue(null)
		const w = await open('t-1')
		w.findComponent({ name: 'CustomerReplySection' }).vm.$emit('saved')
		expect(dialogs.showSuccess).toHaveBeenCalledWith('The answer is saved. The customer can read it on the portal.')
		expect(w.emitted('close')).toHaveLength(1)
		expect(bus.emit).toHaveBeenCalledWith('cn:page:refresh', {})
	})

	it('reloads the page when cancelled or closed', async () => {
		store.fetchObject.mockResolvedValue(null)
		const w = await open('t-1')
		await w.find('[data-testid="ticket-answer-close"]').trigger('click')
		w.findComponent({ name: 'CnDialog' }).vm.$emit('closing')
		expect(w.emitted('close')).toHaveLength(2)
		expect(bus.emit).toHaveBeenCalledTimes(2)
		expect(save).not.toHaveBeenCalled()
	})
})
