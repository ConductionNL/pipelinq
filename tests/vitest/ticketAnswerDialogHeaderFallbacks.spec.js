// @vitest-environment jsdom
/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 * SPDX-License-Identifier: EUPL-1.2
 *
 * The ticket answer dialog's header when the ticket or its client cannot be
 * read in full: a client without a name, a ticket without a client, and a
 * ticket that fails to load all leave only the parts that are known.
 *
 * @spec openspec/changes/simple-ticket-page/specs/ticket-detail-page/spec.md#REQ-TDP-001
 */
import { flushPromises, mount } from '@vue/test-utils'
import { beforeEach, describe, expect, it, vi } from 'vitest'
import { h } from 'vue'

const store = vi.hoisted(() => ({ fetchObject: vi.fn() }))
vi.mock('@nextcloud/event-bus', () => ({ emit: vi.fn() }))
vi.mock('@nextcloud/dialogs', () => ({ showSuccess: vi.fn() }))
vi.mock('../../src/store/modules/object.js', () => ({ useObjectStore: () => store }))
vi.mock('../../src/components/CustomerReplySection.vue', () => ({
	default: { name: 'CustomerReplySection', props: ['objectId', 'inDialog'], emits: ['state', 'saved'], render: () => h('section') },
}))
vi.mock('@nextcloud/vue', () => ({
	NcButton: { props: ['disabled'], emits: ['click'], render() { return h('button', this.$slots.default?.()) } },
}))
vi.mock('@conduction/nextcloud-vue', () => ({
	CnDialog: { name: 'CnDialog', props: ['name', 'eyebrow', 'subtitle'], emits: ['closing'], render() { return h('div', [this.$slots.default?.(), this.$slots.actions?.()]) } },
}))

const { default: Dialog } = await import('../../src/dialogs/TicketAnswerDialog.vue')

/**
 * Mount the dialog on the ticket route and let it read the ticket.
 *
 * @param {string} id The ticket id in the route.
 * @return {Promise<object>} The CnDialog wrapper.
 */
async function header(id) {
	const w = mount(Dialog, { global: { mocks: { $route: { params: { id } } } } })
	await flushPromises()
	return w.findComponent({ name: 'CnDialog' })
}

beforeEach(() => {
	store.fetchObject.mockReset()
})

describe('TicketAnswerDialog header fallbacks', () => {
	it('leaves the client out of the subtitle when the client has no name', async () => {
		store.fetchObject.mockImplementation(async (type) => (type === 'ticket'
			? { reference: 'T-7', title: 'Broken lamp', client: 'c-9' }
			: { email: 'x@example.org' }))
		const dialog = await header('t-7')
		expect(store.fetchObject).toHaveBeenCalledWith('client', 'c-9')
		expect(dialog.props('subtitle')).toBe('T-7 · Broken lamp')
		expect(dialog.props('eyebrow')).toBe('Answer · T-7')
	})

	it('reads no client for a ticket whose client is empty or not an id', async () => {
		store.fetchObject.mockResolvedValueOnce({ reference: 'T-8', title: 'Question', client: '' })
		const dialog = await header('t-8')
		expect(store.fetchObject).toHaveBeenCalledTimes(1)
		expect(dialog.props('subtitle')).toBe('T-8 · Question')

		store.fetchObject.mockReset()
		store.fetchObject.mockResolvedValueOnce({ title: 'Untitled', client: { id: 'c-1' } })
		const second = await header('t-9')
		expect(store.fetchObject).toHaveBeenCalledTimes(1)
		expect(second.props('subtitle')).toBe('Untitled')
		expect(second.props('eyebrow')).toBe('Answer')
	})

	it('falls back to the bare header when reading the ticket fails', async () => {
		store.fetchObject.mockRejectedValue(new Error('offline'))
		const dialog = await header('t-1')
		expect(dialog.props('eyebrow')).toBe('Answer')
		expect(dialog.props('subtitle')).toBe('')
	})

	it('drops the client name again when the client read fails after the ticket', async () => {
		store.fetchObject.mockImplementation(async (type) => {
			if (type === 'ticket') return { reference: 'T-3', title: 'Refund', client: 'c-3' }
			throw new Error('forbidden')
		})
		const dialog = await header('t-3')
		expect(dialog.props('eyebrow')).toBe('Answer')
		expect(dialog.props('subtitle')).toBe('')
	})
})
