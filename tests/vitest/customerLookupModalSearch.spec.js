// @vitest-environment jsdom
/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 * SPDX-License-Identifier: EUPL-1.2
 *
 * The POS customer lookup: it focuses the search, waits for two characters
 * and a pause before asking, lists the matches with a do-not-contact badge,
 * says when nothing matched or the search failed (with a retry), and hands
 * the chosen row back on click or Enter.
 *
 * @spec openspec/specs/pos-customer-link/spec.md#REQ-PCL-001
 */
import { flushPromises, mount } from '@vue/test-utils'
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import { h } from 'vue'

const searchCustomers = vi.hoisted(() => vi.fn())
vi.mock('../../src/services/posCustomerApi.js', () => ({ searchCustomers }))
const focus = vi.fn()
vi.mock('@nextcloud/vue', () => ({
	NcButton: {
		name: 'NcButton',
		emits: ['click'],
		render() {
			return h(
				'button',
				{ onClick: () => this.$emit('click') },
				this.$slots.default?.(),
			)
		},
	},
	NcLoadingIcon: { name: 'NcLoadingIcon', render: () => h('i') },
	NcTextField: {
		name: 'NcTextField',
		props: ['modelValue'],
		emits: ['update:modelValue'],
		methods: { focus },
		render: () => h('input'),
	},
}))
vi.mock('@conduction/nextcloud-vue', () => ({
	CnDialog: {
		name: 'CnDialog',
		emits: ['closing'],
		render() {
			return h('div', [this.$slots.default?.(), this.$slots.actions?.()])
		},
	},
}))

const t = (app, text) => text
globalThis.t = t

const { default: CustomerLookupModal } =
	await import('../../src/modals/CustomerLookupModal.vue')

async function type(wrapper, value) {
	const input = wrapper.findComponent({ name: 'NcTextField' })
	await input.vm.$emit('update:modelValue', value)
}

const state = (wrapper) => wrapper.find('.customer-lookup__state').text()

describe('the POS customer lookup', () => {
	beforeEach(() => {
		vi.useFakeTimers()
		searchCustomers.mockReset()
		focus.mockReset()
	})

	afterEach(() => {
		vi.useRealTimers()
	})

	it('focuses the search and waits for two characters', async () => {
		const wrapper = mount(CustomerLookupModal, { global: { mocks: { t } } })
		await flushPromises()
		expect(focus).toHaveBeenCalledTimes(1)
		expect(state(wrapper)).toBe('Type at least two characters to search.')

		await type(wrapper, ' a ')
		await vi.advanceTimersByTimeAsync(1000)
		expect(searchCustomers).not.toHaveBeenCalled()
	})

	it('searches after a pause, once, and lists the matches', async () => {
		searchCustomers.mockResolvedValue([
			{
				id: 'c1',
				name: 'Anna',
				email: 'anna@x.nl',
				phone: '0612',
				doNotContact: true,
				doNotContactBadge: 'No contact',
			},
			{ id: 'c2', name: 'Bob' },
		])
		const wrapper = mount(CustomerLookupModal, { global: { mocks: { t } } })
		await type(wrapper, 'an')
		await vi.advanceTimersByTimeAsync(200)
		await type(wrapper, 'ann ')
		await vi.advanceTimersByTimeAsync(299)
		expect(searchCustomers).not.toHaveBeenCalled()
		await vi.advanceTimersByTimeAsync(1)
		await flushPromises()

		expect(searchCustomers).toHaveBeenCalledTimes(1)
		expect(searchCustomers).toHaveBeenCalledWith('ann', 20)
		const rows = wrapper.findAll('[data-testid="customer-lookup-row"]')
		expect(rows).toHaveLength(2)
		expect(rows[0].find('.customer-lookup__badge').text()).toBe('🔒 No contact')
		expect(
			rows[0].findAll('.customer-lookup__meta span').map((s) => s.text()),
		).toEqual(['anna@x.nl', '0612'])
		expect(rows[1].find('.customer-lookup__badge').exists()).toBe(false)

		await rows[0].trigger('click')
		await rows[1].trigger('keydown', { key: 'Enter' })
		expect(wrapper.emitted('select')).toEqual([
			[expect.objectContaining({ id: 'c1' })],
			[expect.objectContaining({ id: 'c2' })],
		])
	})

	it('says when nothing matched, and clears the list when the query gets short', async () => {
		searchCustomers.mockResolvedValue([])
		const wrapper = mount(CustomerLookupModal, { global: { mocks: { t } } })
		await type(wrapper, 'zz')
		await vi.advanceTimersByTimeAsync(300)
		await flushPromises()
		expect(state(wrapper)).toBe('No results. Try a different search.')

		await type(wrapper, 'z')
		expect(state(wrapper)).toBe('Type at least two characters to search.')
	})

	it('says the search failed and retries on request', async () => {
		searchCustomers.mockRejectedValueOnce(new Error('down'))
		const wrapper = mount(CustomerLookupModal, { global: { mocks: { t } } })
		await type(wrapper, 'anna')
		await vi.advanceTimersByTimeAsync(300)
		await flushPromises()
		expect(wrapper.find('[role="alert"]').text()).toContain(
			'Error searching. Try again later.',
		)

		searchCustomers.mockResolvedValueOnce([{ id: 'c1', name: 'Anna' }])
		await wrapper.find('[role="alert"] button').trigger('click')
		await flushPromises()
		expect(searchCustomers).toHaveBeenCalledTimes(2)
		expect(wrapper.findAll('[data-testid="customer-lookup-row"]')).toHaveLength(
			1,
		)
	})

	it('cancels from the button and from closing the dialog', async () => {
		const wrapper = mount(CustomerLookupModal, { global: { mocks: { t } } })
		await wrapper.find('button').trigger('click')
		await wrapper.findComponent({ name: 'CnDialog' }).vm.$emit('closing')
		expect(wrapper.emitted('cancel')).toHaveLength(2)
	})

	it('drops a pending search when it closes', async () => {
		const wrapper = mount(CustomerLookupModal, { global: { mocks: { t } } })
		await type(wrapper, 'anna')
		wrapper.unmount()
		await vi.advanceTimersByTimeAsync(1000)
		expect(searchCustomers).not.toHaveBeenCalled()
	})
})
