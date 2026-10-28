// @vitest-environment jsdom
/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 * SPDX-License-Identifier: EUPL-1.2
 *
 * The new sale reads as the board PqKassa (a record page with Lines,
 * Transaction and Purchase history beside Totals, Customer and Receipt
 * preview; a barcode adds the product it finds), and checking out goes
 * through the Payment received dialog (PqKassaAfrekenen): the points are
 * spent, the gift cards redeemed and every part booked as a tender.
 *
 * @spec openspec/changes/pos-sale-board-look/specs/pos-transactions/spec.md#requirement-the-sale-page-reads-as-the-board
 * @spec openspec/changes/pos-sale-board-look/specs/pos-transactions/spec.md#requirement-a-payment-can-be-split
 */

import { flushPromises, mount } from '@vue/test-utils'
import { readFileSync } from 'fs'
import { resolve } from 'path'
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
		props: ['title', 'subtitle', 'label', 'items', 'columns', 'rows', 'variant', 'modelValue', 'inputLabel', 'options', 'object', 'typePill', 'breadcrumb', 'headerMeta', 'line', 'total', 'loyalty', 'restLabel', 'eyebrow', 'customerName'],
		emits: ['click', 'update:modelValue', 'confirm', 'close', 'sellGiftCard'],
		render() {
			const slots = Object.entries(this.$slots).map(([key, fn]) => h('div', { 'data-slot': key }, fn?.({ row: (this.rows || [])[0] || {} })))
			return h(tag, { 'data-stub': name, 'data-title': this.title || this.label || this.inputLabel || '', onClick: () => this.$emit('click') }, slots)
		},
	}
}

vi.mock('@conduction/nextcloud-vue', () => ({
	CnDetailPage: passthrough('CnDetailPage'),
	CnDetailCard: passthrough('CnDetailCard', 'section'),
	CnDataTable: passthrough('CnDataTable'),
	CnResourceSelect: passthrough('CnResourceSelect'),
	CnDialog: passthrough('CnDialog'),
}))
vi.mock('@nextcloud/vue', () => ({
	NcButton: passthrough('NcButton', 'button'),
	NcCheckboxRadioSwitch: passthrough('NcCheckboxRadioSwitch'),
	NcLoadingIcon: passthrough('NcLoadingIcon'),
	NcNoteCard: passthrough('NcNoteCard'),
	NcSelect: passthrough('NcSelect'),
	NcTextField: passthrough('NcTextField'),
}))
vi.mock('../../src/components/pos/PosLineItemRow.vue', () => ({ default: passthrough('PosLineItemRow', 'tr') }))
vi.mock('../../src/components/pos/PaymentMethodSelector.vue', () => ({ default: passthrough('PaymentMethodSelector') }))
vi.mock('../../src/modals/CustomerLookupModal.vue', () => ({ default: passthrough('CustomerLookupModal') }))
vi.mock('@nextcloud/dialogs', () => ({ showError: vi.fn(), showSuccess: vi.fn(), showWarning: vi.fn() }))
vi.mock('@nextcloud/router', () => ({ generateUrl: (url) => url }))
vi.mock('@nextcloud/l10n', () => ({ getLocale: () => 'nl', getLanguage: () => 'nl', translate: (app, text) => text }))

const posts = []
vi.mock('@nextcloud/axios', () => ({
	default: {
		post: vi.fn(async (url, body) => {
			posts.push([url, body])
			if (url.endsWith('/barcode-lookup')) {
				return { data: { product: { '@self': { id: 'p-map' }, name: 'City map', unitPrice: 4.95, taxRate: 9 } } }
			}
			return { data: {} }
		}),
		get: vi.fn(async (url) => {
			if (url.endsWith('/customerLoyaltyAccount')) {
				return { data: { results: [{ '@self': { id: 'acc-1' }, programmeId: 'prog-1', currentBalance: 340 }] } }
			}
			if (url.endsWith('/loyaltyProgramme/prog-1')) {
				return { data: { pointValue: 0.05 } }
			}
			if (url.endsWith('/tender-types')) {
				return { data: { results: [{ id: 't-pin', code: 'PIN' }, { id: 't-bon', code: 'BON' }, { id: 't-pts', code: 'LOYALTY' }] } }
			}
			return { data: { results: [] } }
		}),
	},
}))

const collections = {}
const records = {}
const store = {
	fetchCollection: vi.fn(async () => {}),
	getCollection: (type) => collections[type] || [],
	fetchObject: vi.fn(async (type, id) => records[`${type}/${id}`] || {}),
	saveObject: vi.fn(async (type, payload) => ({ id: type === 'posTransaction' ? 'tx-1' : 'line', ...payload })),
	deleteObject: vi.fn(),
}
vi.mock('../../src/store/modules/object.js', () => ({ useObjectStore: () => store }))

globalThis.t = (app, text, vars = {}) => String(text).replace(/\{(\w+)\}/g, (whole, key) => vars[key] ?? whole)
window.OC = { getCurrentUser: () => ({ uid: 'pieter', displayName: 'Pieter Jansen' }) }

const { default: PosTransactionForm } = await import('../../src/views/pos/PosTransactionForm.vue')

/**
 * Mount the new sale.
 *
 * @return {Promise<object>} The wrapper.
 */
async function mountNew() {
	const push = vi.fn()
	const wrapper = mount(PosTransactionForm, {
		global: { mocks: { t: globalThis.t, $route: { params: {} }, $router: { push } } },
	})
	await flushPromises()
	return wrapper
}

describe('the new sale (PqKassa)', () => {
	it('is a record page with a Draft pill, the Transactions breadcrumb and the register and cashier', async () => {
		const wrapper = await mountNew()
		const page = wrapper.findComponent({ name: 'CnDetailPage' })
		expect(page.props('title')).toBe('New transaction')
		expect(page.props('typePill')).toEqual({ text: 'Draft' })
		expect(page.props('breadcrumb')).toMatchObject({ label: 'Transactions', route: 'PosTransactions' })
		expect(page.props('headerMeta')).toBe('{terminalId} · cashier {cashierName} · shift open since {shiftSince}')
		expect(page.props('object').cashierName).toBe('Pieter Jansen')
		expect(wrapper.text()).not.toContain('Back to list')
	})

	it('draws the board cards; purchase history only with a customer', async () => {
		const wrapper = await mountNew()
		const titles = () => wrapper.findAllComponents({ name: 'CnDetailCard' }).map((card) => card.props('title'))
		expect(titles()).toEqual(['Lines', 'Transaction', 'Totals', 'Customer', 'Preview of the receipt'])
		wrapper.vm.selectedCustomer = { id: 'c1', name: 'Sanne de Vries' }
		await flushPromises()
		expect(titles()).toEqual(['Lines', 'Transaction', 'Purchase history', 'Totals', 'Customer', 'Preview of the receipt'])
	})

	it('adds the product a barcode finds, and one more of it on a second scan', async () => {
		const wrapper = await mountNew()
		wrapper.vm.barcode = '8712345000124'
		await wrapper.vm.addFromBarcode()
		wrapper.vm.barcode = '8712345000124'
		await wrapper.vm.addFromBarcode()
		expect(posts.filter(([url]) => url.endsWith('/barcode-lookup'))[0][1]).toEqual({ barcode: '8712345000124' })
		expect(wrapper.vm.lines).toHaveLength(1)
		expect(wrapper.vm.lines[0]).toMatchObject({ product: 'p-map', description: 'City map', quantity: 2, taxRate: 9 })
		expect(wrapper.vm.linesHint).toContain('The barcode found City map in Products.')
	})

	it('totals excl. VAT, VAT and incl. VAT, and previews the receipt', async () => {
		const wrapper = await mountNew()
		wrapper.vm.addProductLine({ id: 'p1', name: 'Waste pass, extra', unitPrice: 10, taxRate: 21 })
		await flushPromises()
		expect(wrapper.vm.totals.total).toBe(12.1)
		expect(wrapper.vm.vatRows).toEqual([{ key: 'vat', label: 'VAT 21%', tax: 2.1 }])
		expect(wrapper.vm.receiptText).toMatch(/Waste pass, extra €\s?12,10/)
	})

	it('checks out through the payment dialog and books points, gift card and the rest', async () => {
		const wrapper = await mountNew()
		wrapper.vm.addProductLine({ id: 'p1', name: 'Parking permit', unitPrice: 96.41, taxRate: 0 })
		wrapper.vm.transaction.tenderType = 'card'
		wrapper.vm.loyalty = { accountId: 'acc-1', balance: 340, pointValue: 0.05 }
		wrapper.vm.openPayment()
		await flushPromises()
		const dialog = wrapper.findComponent({ name: 'PosPaymentDialog' })
		expect(dialog.exists()).toBe(true)
		expect(dialog.props('total')).toBe(96.41)

		posts.length = 0
		await wrapper.vm.checkout({ points: 200, pointsValue: 10, giftCards: [{ giftCardId: 'g1', serial: 'ZD-GC-7731', pin: '1234', amount: 25 }], rest: 61.41 })
		const urls = posts.map(([url]) => url)
		expect(urls).toContain('/apps/pipelinq/api/pos-transactions/tx-1/confirm')
		expect(posts.find(([url]) => url === '/apps/pipelinq/api/loyalty/accounts/acc-1/pay')[1]).toEqual({ points: 200, posTransactionId: 'tx-1' })
		expect(posts.find(([url]) => url.endsWith('/gift-card/redeem'))[1]).toMatchObject({ giftCardId: 'g1', amount: 25, posTransactionId: 'tx-1' })
		const tenders = posts.filter(([url]) => url.endsWith('/tx-1/tenders')).map(([, body]) => [body.tenderType, body.amount])
		expect(tenders).toEqual([['t-pts', 10], ['t-bon', 25], ['t-pin', 61.41]])
	})

	it('opens the parked sale of a client as the board draws it', async () => {
		records['posTransaction/tx-parked'] = { id: 'tx-parked', status: 'parked', terminalId: 'Balie 2, stadskantoor', cashier: 'pieter', client: 'client-sanne' }
		records['client/client-sanne'] = { name: 'Sanne de Vries' }
		collections.posTransaction = [
			{ id: 'tx-0871', client: 'client-sanne', status: 'settled', reference: 'POS-2026-0871', confirmedAt: '2026-09-12T10:12:00Z', total: 75, tenderType: 'card' },
			{ id: 'tx-other', client: 'client-other', status: 'settled', reference: 'POS-2026-0001', total: 5 },
		]
		collections.cashShift = [{ status: 'open', drawer: 'Balie 2, stadskantoor', floatAt: '2026-10-07T08:30:00' }]
		collections.posTransactionLine = [{ id: 'l1', transaction: 'tx-0871' }, { id: 'l2', transaction: 'tx-other' }]
		const wrapper = mount(PosTransactionForm, {
			global: { mocks: { t: globalThis.t, $route: { params: { id: 'tx-parked' } }, $router: { push: vi.fn() } } },
		})
		await flushPromises()
		const page = wrapper.findComponent({ name: 'CnDetailPage' })
		expect(page.props('title')).toBe('New transaction')
		expect(page.props('typePill')).toEqual({ text: 'Draft' })
		expect(page.props('object')).toMatchObject({ cashierName: 'Pieter Jansen', shiftSince: '08:30' })
		expect(wrapper.vm.selectedCustomer).toMatchObject({ id: 'client-sanne', name: 'Sanne de Vries', isClient: true })
		expect(wrapper.vm.history.map((row) => [row.reference, row.itemCount])).toEqual([['POS-2026-0871', 1]])
		expect(wrapper.vm.loyalty).toEqual({ accountId: 'acc-1', balance: 340, pointValue: 0.05 })
	})

	it('names the customer contact of a parked sale, not its id', async () => {
		records['posTransaction/tx-contact'] = { id: 'tx-contact', status: 'parked', terminalId: 'Balie 2, stadskantoor', cashier: 'pieter', customer: 'contact-sanne' }
		records['contact/contact-sanne'] = { name: 'Sanne de Vries' }
		const wrapper = mount(PosTransactionForm, {
			global: { mocks: { t: globalThis.t, $route: { params: { id: 'tx-contact' } }, $router: { push: vi.fn() } } },
		})
		await flushPromises()
		expect(wrapper.vm.selectedCustomer).toMatchObject({ id: 'contact-sanne', name: 'Sanne de Vries' })
	})

	it('lines the totals up in a label column as wide as the longest label', () => {
		const source = readFileSync(resolve(__dirname, '../../src/views/pos/PosTransactionForm.vue'), 'utf8')
		const rule = (selector) => (source.match(new RegExp(selector.replace(/[.]/g, '\\.') + ' \\{([^}]*)\\}')) || [])[1] || ''
		expect(rule('.pos-totals')).toMatch(/grid-template-columns: max-content 1fr/)
		expect(rule('.pos-totals__row')).toMatch(/display: contents/)
	})

	it('puts a gift card sold in the dialog on the sale without VAT', async () => {
		const wrapper = await mountNew()
		wrapper.vm.addGiftCardLine({ amount: 25 })
		expect(wrapper.vm.lines[0]).toMatchObject({ _giftCard: true, description: 'Gift card', unitPrice: 25, taxRate: 0 })
	})
})
