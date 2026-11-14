// @vitest-environment jsdom
/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 * SPDX-License-Identifier: EUPL-1.2
 *
 * The returned items of a refund, joined to the lines of the original sale.
 */

import { flushPromises, shallowMount } from '@vue/test-utils'
import { beforeEach, describe, expect, it, vi } from 'vitest'

const fetchRefundLines = vi.hoisted(() => vi.fn())
const fetchOriginalLines = vi.hoisted(() => vi.fn())
const showError = vi.hoisted(() => vi.fn())
const subscribe = vi.hoisted(() => vi.fn())
const unsubscribe = vi.hoisted(() => vi.fn())
const store = vi.hoisted(() => ({ fetchCollection: vi.fn(), getCollection: vi.fn() }))

vi.mock('@conduction/nextcloud-vue', () => ({ CnIcon: {}, CnWidgetWrapper: {} }))
vi.mock('@nextcloud/vue', () => ({ NcLoadingIcon: {} }))
vi.mock('@nextcloud/dialogs', () => ({ showError }))
vi.mock('@nextcloud/event-bus', () => ({ subscribe, unsubscribe }))
vi.mock('../../src/services/posRefundLines.js', () => ({ fetchRefundLines, fetchOriginalLines }))
vi.mock('../../src/store/modules/object.js', () => ({ useObjectStore: () => store }))

globalThis.t = (_app, text) => text

const { default: PosRefundItemsWidget } =
	await import('../../src/components/pos/PosRefundItemsWidget.vue')

/**
 * Mount the widget and let it load.
 *
 * @param {object} props The props.
 * @return {Promise<object>} The wrapper.
 */
async function widget(props) {
	const wrapper = shallowMount(PosRefundItemsWidget, {
		props,
		global: { mocks: { t: (_app, text) => text } },
	})
	await flushPromises()
	return wrapper
}

beforeEach(() => {
	vi.clearAllMocks()
	fetchRefundLines.mockResolvedValue([
		{ id: 'r1', originalLine: 'o1', returnedQuantity: 1, returnReason: 'broken', restock: false, lineTotal: 5 },
		{ id: 'r2', originalLine: 'o-missing', returnedQuantity: 2, returnReason: 'other' },
	])
	fetchOriginalLines.mockResolvedValue([{ id: 'o1', description: 'Mok', quantity: 3 }])
	store.getCollection.mockReturnValue({ results: [{ id: 'broken', label: 'Kapot' }, { id: 'other', code: 'OTH' }] })
})

describe('PosRefundItemsWidget', () => {
	it('joins each returned line to its original sale line', async () => {
		const wrapper = await widget({ objectId: 'ref-1', objectData: { originalTransaction: 'tx-9' } })

		expect(fetchRefundLines).toHaveBeenCalledWith(store, 'ref-1')
		expect(store.fetchCollection).toHaveBeenCalledWith('refundReason', { _limit: 100 })
		expect(fetchOriginalLines).toHaveBeenCalledWith(store, 'tx-9')
		expect(wrapper.vm.lineRows).toEqual([
			{ id: 'r1', description: 'Mok', originalQuantity: 3, returnedQuantity: 1, returnReason: 'broken', restock: false, lineTotal: 5 },
			{ id: 'r2', description: '-', originalQuantity: '-', returnedQuantity: 2, returnReason: 'other', restock: true, lineTotal: 0 },
		])
		expect(wrapper.vm.loading).toBe(false)
	})

	it('names a reason by label, then code, then its id', async () => {
		const wrapper = await widget({ objectId: 'ref-1' })
		expect(wrapper.vm.reasonLabel('broken')).toBe('Kapot')
		expect(wrapper.vm.reasonLabel('other')).toBe('OTH')
		expect(wrapper.vm.reasonLabel('unlisted')).toBe('unlisted')
		expect(wrapper.vm.reasonLabel('')).toBe('-')
	})

	it('takes the refund id from the object when no id prop is given, and loads nothing without one', async () => {
		await widget({ objectData: { id: 'ref-2' } })
		expect(fetchRefundLines).toHaveBeenCalledWith(store, 'ref-2')

		fetchRefundLines.mockClear()
		await widget({})
		expect(fetchRefundLines).not.toHaveBeenCalled()
	})

	it('reports a failed load with the server error or a default', async () => {
		fetchRefundLines.mockRejectedValueOnce(Object.assign(new Error('x'), { response: { data: { error: 'No access' } } }))
		const wrapper = await widget({ objectId: 'ref-1' })
		expect(showError).toHaveBeenCalledWith('No access')
		expect(wrapper.vm.loading).toBe(false)

		fetchRefundLines.mockRejectedValueOnce(new Error('x'))
		await widget({ objectId: 'ref-1' })
		expect(showError).toHaveBeenLastCalledWith('Could not load refund.')
	})

	it('reloads quietly on a page refresh and when the original transaction arrives', async () => {
		const wrapper = await widget({ objectId: 'ref-1', objectData: {} })
		expect(subscribe).toHaveBeenCalledWith('cn:page:refresh', wrapper.vm.onPageRefresh)

		const waitUntil = vi.fn()
		wrapper.vm.onPageRefresh({ waitUntil })
		expect(wrapper.vm.loading).toBe(false)
		expect(waitUntil).toHaveBeenCalledWith(expect.any(Promise))
		await flushPromises()

		fetchOriginalLines.mockClear()
		await wrapper.setProps({ objectData: { originalTransaction: 'tx-late' } })
		await flushPromises()
		expect(fetchOriginalLines).toHaveBeenCalledWith(store, 'tx-late')

		wrapper.unmount()
		expect(unsubscribe).toHaveBeenCalledWith('cn:page:refresh', expect.any(Function))
	})
})
