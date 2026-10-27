// @vitest-environment jsdom
/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 * SPDX-License-Identifier: EUPL-1.2
 *
 * The new refund page reads as the board PqKassaRetour: a record page with a
 * Draft pill, the Refunds breadcrumb and "from <receipt> · <customer>", the
 * cards Refund and Items to take back, and beside them Total refund, After
 * saving and Original receipt. There is no Back to list button.
 *
 * @spec openspec/changes/pos-refund-board-look/specs/pos-refunds/spec.md#requirement-the-refund-page-reads-as-the-board
 */

import { flushPromises, mount } from '@vue/test-utils'
import { describe, expect, it, vi } from 'vitest'
import { h } from 'vue'

/**
 * A stub that renders its slots and keeps its props readable.
 *
 * @param {string} name The component name.
 * @param {string} [tag] The element it renders.
 * @return {object} The stub.
 */
function passthrough(name, tag = 'div') {
	return {
	name,
	inheritAttrs: false,
	props: ['title', 'label', 'items', 'columns', 'rows', 'variant', 'modelValue', 'inputLabel', 'options', 'object', 'typePill', 'breadcrumb', 'headerMeta'],
	render() {
		const slots = Object.entries(this.$slots).map(([key, fn]) => h('div', { 'data-slot': key }, fn?.({ row: (this.rows || [])[0] || {}, item: (this.items || [])[0] || {} })))
		return h(tag, { 'data-stub': name, 'data-title': this.title || this.label || this.inputLabel || '' }, slots)
	},
	}
}

vi.mock('@conduction/nextcloud-vue', () => ({
	CnDetailPage: passthrough('CnDetailPage'),
	CnDetailCard: passthrough('CnDetailCard', 'section'),
	CnDetailGrid: passthrough('CnDetailGrid'),
	CnDataTable: passthrough('CnDataTable'),
}))
vi.mock('@nextcloud/vue', () => ({
	NcButton: passthrough('NcButton', 'button'),
	NcCheckboxRadioSwitch: passthrough('NcCheckboxRadioSwitch'),
	NcInputField: passthrough('NcInputField'),
	NcSelect: passthrough('NcSelect'),
	NcTextArea: passthrough('NcTextArea'),
}))
vi.mock('@nextcloud/dialogs', () => ({ showError: vi.fn(), showSuccess: vi.fn() }))
vi.mock('@nextcloud/l10n', () => ({ getLocale: () => 'nl', getLanguage: () => 'nl' }))

const records = {
	posTransaction: [{ id: 'tx1', reference: 'POS-2026-1043', status: 'settled', total: 96.41, tenderType: 'card', cashier: 'pieter', customer: 'c1', confirmedAt: '2026-10-07T11:43:00Z' }],
	refundReason: [{ id: 'r1', label: 'Wrong product' }],
	posTransactionLine: [
		{ id: 'l1', transaction: 'tx1', description: 'Waste pass, extra', quantity: 1, unitPrice: 12.5, taxRate: 21, taxAmount: 2.17, lineTotal: 12.5, sortOrder: 1 },
		{ id: 'l2', transaction: 'tx1', description: 'City map', quantity: 2, unitPrice: 4.95, taxRate: 21, taxAmount: 1.72, lineTotal: 9.9, sortOrder: 2 },
	],
}
const store = {
	fetchCollection: vi.fn(async () => {}),
	getCollection: (type) => ({ results: records[type] || [] }),
	fetchObject: vi.fn(async () => ({ name: 'Sanne de Vries' })),
	saveObject: vi.fn(),
}
vi.mock('../../src/store/modules/object.js', () => ({ useObjectStore: () => store }))

globalThis.t = (app, text, vars = {}) => String(text).replace(/\{(\w+)\}/g, (whole, key) => vars[key] ?? whole)

const { default: PosRefundForm } = await import('../../src/views/pos/PosRefundForm.vue')

/**
 * Mount the new refund page from a transaction.
 *
 * @return {Promise<object>} The wrapper.
 */
async function mountNew() {
	const wrapper = mount(PosRefundForm, {
		global: {
			mocks: { t: globalThis.t, $route: { params: { transactionId: 'tx1' } }, $router: { push: vi.fn() } },
			stubs: { 'router-link': passthrough('RouterLink', 'a'), Check: true, Close: true },
		},
	})
	await flushPromises()
	return wrapper
}

describe('the new refund page (PqKassaRetour)', () => {
	it('is a record page with a Draft pill, the Refunds breadcrumb and where it comes from', async () => {
		const wrapper = await mountNew()
		const page = wrapper.findComponent({ name: 'CnDetailPage' })
		expect(page.props('title')).toBe('New refund')
		expect(page.props('typePill')).toEqual({ text: 'Draft' })
		expect(page.props('breadcrumb')).toMatchObject({ label: 'Refunds', route: 'PosRefunds' })
		expect(page.props('headerMeta')).toBe('from {fromReference} · {clientName}')
		expect(page.props('object')).toMatchObject({ fromReference: 'POS-2026-1043', clientName: 'Sanne de Vries' })
		expect(wrapper.text()).not.toContain('Back to list')
	})

	it('draws the board cards in the main and side columns', async () => {
		const wrapper = await mountNew()
		const titles = wrapper.findAll('[data-stub="CnDetailCard"]').map((card) => card.attributes('data-title'))
		expect(titles).toEqual(['Refund', 'Items to take back', 'Total refund', 'After saving', 'Original receipt'])
	})

	it('lists the transaction as reference, day and total, and the notes as a text area', async () => {
		const wrapper = await mountNew()
		const transactionSelect = wrapper.findAllComponents({ name: 'NcSelect' }).find((s) => s.props('inputLabel') === 'Original transaction')
		expect(transactionSelect.props('options')[0].label).toMatch(/^POS-2026-1043 · 7 okt · €\s96,41$/)
		expect(wrapper.find('[data-stub="NcTextArea"]').attributes('data-title')).toBe('Notes (optional)')
	})

	it('totals what is taken back, with the VAT rate and where the money goes', async () => {
		const wrapper = await mountNew()
		wrapper.vm.setSelected(0, true)
		await flushPromises()
		const totals = wrapper.findAllComponents({ name: 'CnDetailGrid' })[0].props('items')
		expect(totals.map((i) => i.label)).toEqual(['Excl. VAT', 'VAT 21%', 'Total refund', 'Refunded to'])
		expect(totals[2].value).toMatch(/12,50/)
		expect(totals[3].value).toBe('Card, the original payment method')
	})

	it('takes back every item at once', async () => {
		const wrapper = await mountNew()
		wrapper.vm.toggleAll(true)
		expect(wrapper.vm.allSelected).toBe(true)
		expect(wrapper.vm.selectedLines).toHaveLength(2)
	})
})
