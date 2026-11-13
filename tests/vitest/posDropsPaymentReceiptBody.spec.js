// @vitest-environment jsdom
/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Three POS detail pieces: the cash shift drops card (Add drop only on an
 * open shift, posted through the shift action), the transaction payment card
 * (Add tender only while the transaction can still take one, and a quiet
 * tender reload on a page refresh), and the shared receipt dialog body.
 *
 * @spec openspec/changes/archive/2026-06-14-pos-split-tender/specs.md#REQ-PST-002
 */

import { flushPromises, mount } from '@vue/test-utils'
import { beforeEach, describe, expect, it, vi } from 'vitest'
import { h } from 'vue'

const shiftActions = vi.hoisted(() => ({ postShiftAction: vi.fn() }))
const bus = vi.hoisted(() => ({ handlers: {}, subscribe: vi.fn(), unsubscribe: vi.fn() }))
const panel = vi.hoisted(() => ({ openAddDialog: vi.fn(), loadTenders: vi.fn() }))

/**
 * A stand-in that keeps its props and renders every slot.
 *
 * @param {string} name The component name.
 * @param {Array<string>} props Its props.
 * @return {object} The component.
 */
function stub(name, props = []) {
	return {
		name,
		inheritAttrs: false,
		props,
		emits: ['click', 'close', 'confirm', 'update:modelValue'],
		render() {
			return h('div', { 'data-stub': name }, Object.values(this.$slots).map((slot) => slot()))
		},
	}
}

vi.mock('@conduction/nextcloud-vue', () => ({
	CnIcon: stub('CnIcon', ['name']),
	CnObjectListWidget: stub('CnObjectListWidget', ['content']),
	CnWidgetWrapper: stub('CnWidgetWrapper', ['title']),
}))
vi.mock('@nextcloud/vue', () => ({
	NcButton: stub('NcButton', ['disabled']),
	NcSelect: stub('NcSelect', ['modelValue', 'options', 'inputLabel', 'clearable']),
}))
vi.mock('@nextcloud/event-bus', () => ({ subscribe: bus.subscribe, unsubscribe: bus.unsubscribe }))
vi.mock('../../src/services/posShiftActions.js', () => shiftActions)
vi.mock('../../src/modals/CashShiftDropDialog.vue', () => ({ default: stub('CashShiftDropDialog', ['submitting']) }))
vi.mock('../../src/components/pos/ReceiptPreviewPane.vue', () => ({ default: stub('ReceiptPreviewPane', ['content', 'loading']) }))
vi.mock('../../src/components/pos/TenderEntryPanel.vue', () => ({
	default: {
		name: 'TenderEntryPanel',
		props: ['transactionId', 'transactionStatus'],
		methods: {
			openAddDialog: (...a) => panel.openAddDialog(...a),
			loadTenders: (...a) => panel.loadTenders(...a),
		},
		render: () => h('div'),
	},
}))

/**
 * Translate as the identity.
 *
 * @param {string} app The app id.
 * @param {string} text The text.
 * @return {string} The text.
 */
const t = (app, text) => text
globalThis.t = t

const { default: CashShiftDropsWidget } = await import('../../src/components/pos/CashShiftDropsWidget.vue')
const { default: PosTransactionPaymentWidget } = await import('../../src/components/pos/PosTransactionPaymentWidget.vue')
const { default: ReceiptModalBody } = await import('../../src/components/pos/ReceiptModalBody.vue')

/**
 * Mount with the translation helper.
 *
 * @param {object} component The component.
 * @param {object} [props] Its props.
 * @param {object} [slots] Its slots.
 * @return {object} The wrapper.
 */
function mountIt(component, props = {}, slots = {}) {
	return mount(component, { props, slots, global: { mocks: { t } } })
}

beforeEach(() => {
	vi.clearAllMocks()
	bus.subscribe.mockImplementation((name, handler) => {
		bus.handlers[name] = handler
	})
})

describe('CashShiftDropsWidget', () => {
	it.each([
		[{ id: 's1', status: 'open' }, true],
		[{ id: 's1' }, true],
		[{ id: 's1', status: 'closed' }, false],
		[null, false],
	])('on shift %p, Add drop is enabled: %p', (objectData, enabled) => {
		const w = mountIt(CashShiftDropsWidget, { objectData })

		expect(w.findComponent({ name: 'NcButton' }).props('disabled')).toBe(!enabled)
		expect(w.find('.cash-shift-drops__add').attributes('title')).toBe(enabled ? undefined : 'Drops can only be added to an open shift.')
	})

	it('titles the card, and hands the list its content without its own Add', () => {
		const w = mountIt(CashShiftDropsWidget, { objectData: { id: 's1' }, content: { schema: 'cashDrop', allowCreate: true } })

		expect(w.findComponent({ name: 'CnWidgetWrapper' }).props('title')).toBe('Drops')
		expect(w.findComponent({ name: 'CnObjectListWidget' }).props('content')).toEqual({ schema: 'cashDrop', allowCreate: false })
		expect(mountIt(CashShiftDropsWidget, { title: 'Afroomstortingen' }).findComponent({ name: 'CnWidgetWrapper' }).props('title')).toBe('Afroomstortingen')
	})

	it('posts a confirmed drop for the shift and closes the dialog once it is recorded', async () => {
		let resolve
		shiftActions.postShiftAction.mockReturnValue(new Promise((r) => { resolve = r }))
		const w = mountIt(CashShiftDropsWidget, { objectId: 'shift-9', objectData: { id: 'other', status: 'open' } })

		await w.findComponent({ name: 'NcButton' }).vm.$emit('click')
		const dialog = () => w.findComponent({ name: 'CashShiftDropDialog' })
		expect(dialog().props('submitting')).toBe(false)

		await dialog().vm.$emit('confirm', { amount: 50, reason: 'bank-run' })
		expect(shiftActions.postShiftAction).toHaveBeenCalledWith('shift-9', {
			path: 'drop',
			body: { amount: 50, reason: 'bank-run' },
			successMessage: 'Drop recorded.',
		})
		expect(dialog().props('submitting')).toBe(true)
		expect(w.findComponent({ name: 'NcButton' }).props('disabled')).toBe(true)

		resolve(true)
		await flushPromises()
		expect(dialog().exists()).toBe(false)
		expect(w.findComponent({ name: 'NcButton' }).props('disabled')).toBe(false)
	})

	it('keeps the dialog open when the drop is refused, and closes it on cancel', async () => {
		shiftActions.postShiftAction.mockResolvedValue(false)
		const w = mountIt(CashShiftDropsWidget, { objectData: { id: 's1', status: 'open' } })

		await w.findComponent({ name: 'NcButton' }).vm.$emit('click')
		await w.findComponent({ name: 'CashShiftDropDialog' }).vm.$emit('confirm', { amount: 5, reason: '' })
		await flushPromises()
		expect(shiftActions.postShiftAction.mock.calls[0][0]).toBe('s1')
		expect(w.findComponent({ name: 'CashShiftDropDialog' }).exists()).toBe(true)

		await w.findComponent({ name: 'CashShiftDropDialog' }).vm.$emit('close')
		expect(w.findComponent({ name: 'CashShiftDropDialog' }).exists()).toBe(false)
	})
})

describe('PosTransactionPaymentWidget', () => {
	it.each([
		['draft', true],
		[undefined, true],
		['settled', false],
		['refunded', false],
	])('a %s transaction offers Add tender: %p', (status, offered) => {
		const w = mountIt(PosTransactionPaymentWidget, { objectData: { id: 'tx-1', status } })

		expect(w.findComponent({ name: 'NcButton' }).exists()).toBe(offered)
		const tenders = w.findComponent({ name: 'TenderEntryPanel' })
		expect(tenders.props()).toEqual({ transactionId: 'tx-1', transactionStatus: status || 'draft' })
	})

	it('shows no tenders and no Add tender without a transaction', () => {
		const w = mountIt(PosTransactionPaymentWidget)

		expect(w.findComponent({ name: 'TenderEntryPanel' }).exists()).toBe(false)
		expect(w.findComponent({ name: 'NcButton' }).exists()).toBe(false)
		expect(w.findComponent({ name: 'CnWidgetWrapper' }).props('title')).toBe('Payment')
	})

	it('opens the tender dialog of the panel', async () => {
		const w = mountIt(PosTransactionPaymentWidget, { objectId: 'tx-2' })

		await w.findComponent({ name: 'NcButton' }).vm.$emit('click')

		expect(panel.openAddDialog).toHaveBeenCalledTimes(1)
		expect(w.findComponent({ name: 'TenderEntryPanel' }).props('transactionId')).toBe('tx-2')
	})

	it('reloads the tenders quietly on a page refresh, and lets the page wait for it', () => {
		const done = Promise.resolve()
		panel.loadTenders.mockReturnValue(done)
		const w = mountIt(PosTransactionPaymentWidget, { objectData: { id: 'tx-1' } })
		const waitUntil = vi.fn()

		expect(bus.subscribe).toHaveBeenCalledWith('cn:page:refresh', expect.any(Function))
		bus.handlers['cn:page:refresh']({ waitUntil })
		bus.handlers['cn:page:refresh']()

		expect(panel.loadTenders).toHaveBeenCalledWith({ silent: true })
		expect(panel.loadTenders).toHaveBeenCalledTimes(2)
		expect(waitUntil).toHaveBeenCalledWith(done)

		const handler = bus.handlers['cn:page:refresh']
		w.unmount()
		expect(bus.unsubscribe).toHaveBeenCalledWith('cn:page:refresh', handler)
	})

	it('ignores a page refresh while there is no tender panel', () => {
		mountIt(PosTransactionPaymentWidget)
		const waitUntil = vi.fn()

		bus.handlers['cn:page:refresh']({ waitUntil })

		expect(panel.loadTenders).not.toHaveBeenCalled()
		expect(waitUntil).not.toHaveBeenCalled()
	})
})

describe('ReceiptModalBody', () => {
	it('shows the template choice, the dialog line, the preview and a coloured status', async () => {
		const options = [{ id: 'a', label: 'Standard' }, { id: 'b', label: 'Gift' }]
		const w = mountIt(ReceiptModalBody, {
			selectedTemplate: options[0],
			templateOptions: options,
			previewText: 'TOTAL 12.00',
			previewLoading: true,
			statusMessage: 'Sent.',
			statusType: 'success',
		}, { default: '<p class="dialog-line">To: a@b.nl</p>' })

		const select = w.findComponent({ name: 'NcSelect' })
		expect([select.props('modelValue'), select.props('options'), select.props('clearable')]).toEqual([options[0], options, false])
		expect(w.find('.dialog-line').text()).toBe('To: a@b.nl')
		expect(w.findComponent({ name: 'ReceiptPreviewPane' }).props()).toEqual({ content: 'TOTAL 12.00', loading: true })
		const status = w.find('.receipt-modal__status')
		expect(status.text()).toBe('Sent.')
		expect(status.classes()).toEqual(['receipt-modal__status', 'receipt-modal__status--success'])

		await select.vm.$emit('update:modelValue', options[1])
		expect(w.emitted('update:selectedTemplate')).toEqual([[options[1]]])
	})

	it('marks an error status, a plain status, and no status at all', () => {
		const error = mountIt(ReceiptModalBody, { statusMessage: 'Failed.', statusType: 'error' })
		const plain = mountIt(ReceiptModalBody, { statusMessage: 'Working…' })
		const none = mountIt(ReceiptModalBody)

		expect(error.find('.receipt-modal__status').classes()).toContain('receipt-modal__status--error')
		expect(plain.find('.receipt-modal__status').classes()).toEqual(['receipt-modal__status'])
		expect(none.find('.receipt-modal__status').exists()).toBe(false)
	})
})
