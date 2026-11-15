// @vitest-environment jsdom
/**
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * The props every POS detail-page widget takes from the page host: the
 * record, its id and the manifest title, each with the same default; and
 * none of the other keys the host spreads lands on the widget's root.
 *
 * @spec openspec/specs/pos/spec.md
 */
import { flushPromises, shallowMount } from '@vue/test-utils'
import { describe, expect, it, vi } from 'vitest'

vi.mock('@nextcloud/axios', () => ({ default: { get: vi.fn(async () => ({ data: {} })) } }))
vi.mock('@nextcloud/event-bus', () => ({ subscribe: vi.fn(), unsubscribe: vi.fn() }))
vi.mock('@nextcloud/dialogs', () => ({ showError: vi.fn(), showSuccess: vi.fn() }))
vi.mock('@nextcloud/l10n', () => ({ translatePlural: (_app, one) => one }))
vi.mock('@nextcloud/router', () => ({ generateUrl: (p) => p }))
vi.mock('@nextcloud/vue', () => ({ NcButton: {}, NcLoadingIcon: {}, NcNoteCard: {} }))
vi.mock('@conduction/nextcloud-vue', () => ({ CnIcon: {}, CnObjectListWidget: {}, CnWidgetWrapper: {} }))
vi.mock('../../src/modals/CashShiftDropDialog.vue', () => ({ default: {} }))
vi.mock('../../src/dialogs/ConfirmDialog.vue', () => ({ default: {} }))
vi.mock('../../src/components/pos/TenderEntryPanel.vue', () => ({ default: {} }))
vi.mock('../../src/services/posRefundLines.js', () => ({ fetchOriginalLines: vi.fn(async () => []), fetchRefundLines: vi.fn(async () => []) }))
vi.mock('../../src/services/collectionRows.js', () => ({ collectionRows: vi.fn(async () => []) }))
vi.mock('../../src/store/modules/object.js', () => ({ useObjectStore: () => ({ fetchCollection: vi.fn(async () => []), fetchObject: vi.fn(async () => null) }) }))

globalThis.t = (_app, text) => text

const widgets = {}
for (const name of ['CashShiftDropsWidget', 'PosRefundItemsWidget', 'PosTransactionPaymentWidget', 'PosTransactionTotalsWidget', 'ZReportBookkeepingWidget']) {
	widgets[name] = (await import(`../../src/components/pos/${name}.vue`)).default
}

/**
 * @param {object} widget The widget.
 * @param {object} props What the page host hands it.
 * @return {object} The wrapper.
 */
function mountWidget(widget, props) {
	return shallowMount(widget, { props, global: { mocks: { t: globalThis.t, n: (_app, one) => one } } })
}

describe.each(Object.keys(widgets))('%s', (name) => {
	const widget = widgets[name]

	it('takes the record, its id and the title from the page host', async () => {
		const wrapper = mountWidget(widget, { objectData: { id: 'x1' }, objectId: 'x1', title: 'Card' })
		await flushPromises()
		expect(wrapper.vm.objectData).toEqual({ id: 'x1' })
		expect(wrapper.vm.objectId).toBe('x1')
		expect(wrapper.vm.title).toBe('Card')
	})

	it('defaults to no record, an empty id and an empty title', async () => {
		const wrapper = mountWidget(widget, {})
		await flushPromises()
		expect(wrapper.vm.objectData).toBeNull()
		expect(wrapper.vm.objectId).toBe('')
		expect(wrapper.vm.title).toBe('')
	})

	it('keeps the host\'s other keys off its root element', async () => {
		const wrapper = mountWidget(widget, { objectId: 'x1', register: 'pipelinq', schema: 'posShift' })
		await flushPromises()
		expect(wrapper.attributes('register')).toBeUndefined()
		expect(wrapper.attributes('schema')).toBeUndefined()
	})

	it('declares the three props with their types', () => {
		const props = { ...Object.assign({}, ...(widget.mixins || []).map((m) => m.props || {})), ...widget.props }
		expect(props.objectData).toMatchObject({ type: Object, default: null })
		expect(props.objectId).toMatchObject({ type: String, default: '' })
		expect(props.title).toMatchObject({ type: String, default: '' })
	})
})
