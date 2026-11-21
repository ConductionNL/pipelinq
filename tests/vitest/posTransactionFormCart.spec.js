/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 * SPDX-License-Identifier: EUPL-1.2
 *
 * The till's cart: a scanned barcode or an empty line, removing a stored line,
 * the products and the stored lines it loads, and the sync that stores the sale
 * and its changed lines. Each runs against a plain context so the requests it
 * makes and the state it leaves are read directly.
 *
 * @spec openspec/specs/pos-transactions/spec.md#requirement-the-sale-lines-read-as-a-table-and-stay-editable
 */
import { afterEach, beforeAll, beforeEach, describe, expect, it, vi } from 'vitest'

const http = vi.hoisted(() => ({ get: vi.fn(), post: vi.fn() }))
const dialogs = vi.hoisted(() => ({ showError: vi.fn(), showSuccess: vi.fn(), showWarning: vi.fn() }))

vi.mock('@nextcloud/axios', () => ({ default: http }))
vi.mock('@nextcloud/dialogs', () => dialogs)
vi.mock('@nextcloud/router', () => ({ generateUrl: (path) => path }))
vi.mock('@conduction/nextcloud-vue', () => ({ CnDataTable: {}, CnDetailCard: {}, CnDetailPage: {}, CnResourceSelect: {} }))
vi.mock('@nextcloud/vue', () => ({ NcButton: {}, NcCheckboxRadioSwitch: {}, NcNoteCard: {}, NcSelect: {}, NcTextField: {} }))
for (const icon of ['AccountPlus', 'Barcode', 'CashRegister', 'Check', 'Close', 'PauseCircleOutline', 'Plus']) {
	vi.doMock(`vue-material-design-icons/${icon}.vue`, () => ({ default: {} }))
}
vi.mock('../../src/components/pos/PaymentMethodSelector.vue', () => ({ default: {} }))
vi.mock('../../src/components/pos/PosLineItemRow.vue', () => ({ default: {} }))
vi.mock('../../src/dialogs/PosPaymentDialog.vue', () => ({ default: {} }))
vi.mock('../../src/modals/CustomerLookupModal.vue', () => ({ default: {} }))
vi.mock('../../src/services/posCustomerApi.js', () => ({ attachCustomer: vi.fn(), detachCustomer: vi.fn(), getCustomerHistory: vi.fn() }))
vi.mock('../../src/store/modules/object.js', () => ({ useObjectStore: () => ({}) }))

let form

beforeAll(async () => {
	globalThis.t = (app, text, vars = {}) => text.replace(/\{(\w+)\}/g, (match, key) => String(vars[key] ?? match))
	form = (await import('../../src/views/pos/PosTransactionForm.vue')).default
}, 60000)

/**
 * A context with the form's methods bound and the given state.
 *
 * @param {object} state The state.
 * @return {object} The context.
 */
function context(state = {}) {
	const ctx = {
		transaction: { client: null, customer: null, tenderType: 'cash' },
		lines: [],
		deletedLineIds: [],
		keyCounter: 0,
		editingKey: null,
		barcode: '',
		scanMessage: '',
		syncQueue: Promise.resolve(),
		...state,
	}
	for (const [name, fn] of Object.entries(form.methods)) {
		if (!(name in ctx)) {
			ctx[name] = fn.bind(ctx)
		}
	}
	return ctx
}

beforeEach(() => {
	http.post.mockReset()
	Object.values(dialogs).forEach((fn) => fn.mockReset())
})

afterEach(() => {
	vi.useRealTimers()
})

describe('PosTransactionForm barcode', () => {
	it('adds an empty line, open for editing, when no barcode was typed', async () => {
		const ctx = context({ barcode: '  ' })
		await ctx.addFromBarcode()
		expect(http.post).not.toHaveBeenCalled()
		expect(ctx.lines).toEqual([{ _key: 1, _dirty: true, description: '', quantity: 1, unitPrice: 0, discount: 0, taxRate: 21, product: null }])
		expect(ctx.editingKey).toBe(1)
	})

	it('reports an unknown barcode, keeps it in the field and adds nothing', async () => {
		http.post.mockRejectedValue(new Error('404'))
		const ctx = context({ barcode: ' 8712 ', scanMessage: 'old' })
		await ctx.addFromBarcode()
		expect(http.post).toHaveBeenCalledWith('/apps/pipelinq/api/products/barcode-lookup', { barcode: '8712' })
		expect(dialogs.showError).toHaveBeenCalledWith('No product found for barcode 8712')
		expect(ctx.scanMessage).toBe('')
		expect(ctx.barcode).toBe(' 8712 ')
		expect(ctx.lines).toEqual([])
	})
})

describe('PosTransactionForm lines', () => {
	it('remembers a removed stored line for deletion and closes its editor', () => {
		const scheduleSync = vi.fn()
		const ctx = context({ scheduleSync, editingKey: 2, lines: [{ _key: 1, description: 'a' }, { _key: 2, id: 'l-2', description: 'b' }] })
		ctx.removeLine(1)
		expect(ctx.deletedLineIds).toEqual(['l-2'])
		expect(ctx.editingKey).toBe(null)
		expect(ctx.lines).toEqual([{ _key: 1, description: 'a' }])
		expect(scheduleSync).toHaveBeenCalledWith(0)
		ctx.removeLine(0)
		expect(ctx.deletedLineIds).toEqual(['l-2'])
	})

	it('loads no products when the store refuses', async () => {
		const ctx = context({ products: ['stale'], objectStore: { fetchCollection: vi.fn().mockRejectedValue(new Error('down')) } })
		await ctx.loadProducts()
		expect(ctx.products).toEqual([])
	})

	it('loads the sale\'s own lines in their stored order, each with a key', async () => {
		const objectStore = {
			fetchObject: vi.fn().mockResolvedValue({ id: 'tx', paymentProvider: 'mollie', paymentMethod: 'ideal' }),
			fetchCollection: vi.fn().mockResolvedValue(),
			getCollection: () => ({ results: [
				{ id: 'b', transaction: 'tx', sortOrder: 2 },
				{ id: 'x', transaction: 'other', sortOrder: 1 },
				{ id: 'a', transaction: 'tx', sortOrder: 1 },
				{ id: 'c', transaction: 'tx' },
			] }),
		}
		const ctx = context({ transactionId: 'tx', objectStore, loadShift: vi.fn().mockResolvedValue() })
		await ctx.loadTransaction()
		expect(ctx.lines.map((l) => [l.id, l._key])).toEqual([['c', 1], ['a', 2], ['b', 3]])
		expect(ctx.transaction).toMatchObject({ tenderType: 'cash', marketingConsent: false, customer: null })
		expect(ctx.paymentSelection).toBe('mollie:ideal')
	})
})

describe('PosTransactionForm sync', () => {
	it('refuses to open payment when checkout is not allowed', async () => {
		const syncCart = vi.fn()
		const ctx = context({ checkoutAllowed: false, onAccountError: 'No account.', syncCart, showPayment: false })
		await ctx.openPayment()
		expect(dialogs.showError).toHaveBeenCalledWith('No account.')
		expect(syncCart).not.toHaveBeenCalled()
		expect(ctx.showPayment).toBe(false)
	})

	it('stores nothing for a new sale without a storable line', async () => {
		const ctx = context({ isNew: true, lines: [{ description: ' ' }], ensureSale: vi.fn() })
		expect(await ctx.runSync(false)).toBe(null)
		expect(ctx.ensureSale).not.toHaveBeenCalled()
	})

	it('reports a sale that could not be priced, and resolves to none', async () => {
		const ctx = context({ runSync: vi.fn().mockRejectedValue(new Error('down')) })
		expect(await ctx.syncCart()).toBe(null)
		expect(dialogs.showError).toHaveBeenCalledWith('The sale could not be priced. Try again.')
	})

	it('reports a sale the store did not save, and keeps it changed', async () => {
		const objectStore = { saveObject: vi.fn().mockResolvedValue(null) }
		const ctx = context({ objectStore, isNew: true, headerDirty: true, transaction: { cashier: 'kim', total: 9 } })
		expect(await ctx.ensureSale(false)).toBe(null)
		expect(objectStore.saveObject).toHaveBeenCalledWith('posTransaction', expect.objectContaining({ cashier: 'kim', status: 'draft' }))
		expect(dialogs.showError).toHaveBeenCalledWith('Failed to save transaction.')
		expect(ctx.headerDirty).toBe(true)
	})

	it('deletes the removed lines once, and stores only the changed storable ones', async () => {
		const objectStore = {
			deleteObject: vi.fn().mockResolvedValue(),
			saveObject: vi.fn().mockResolvedValue({ id: 'new-line' }),
		}
		const ctx = context({
			objectStore,
			deletedLineIds: ['l-1', 'l-2'],
			lines: [
				{ _key: 1, _dirty: false, description: 'Kept' },
				{ _key: 2, _dirty: true, description: 'Coffee', quantity: 2, unitPrice: 3 },
				{ _key: 3, _dirty: true, description: '' },
			],
		})
		await ctx.storeLineChanges('tx')
		expect(objectStore.deleteObject.mock.calls).toEqual([['posTransactionLine', 'l-1'], ['posTransactionLine', 'l-2']])
		expect(ctx.deletedLineIds).toEqual([])
		expect(objectStore.saveObject).toHaveBeenCalledTimes(1)
		expect(objectStore.saveObject).toHaveBeenCalledWith('posTransactionLine', expect.objectContaining({ transaction: 'tx', description: 'Coffee', quantity: 2, sortOrder: 2 }))
		expect(ctx.lines[1]).toMatchObject({ id: 'new-line', _dirty: false })
	})
})

describe('PosTransactionForm figures', () => {
	it('shows one VAT row per rate, with its base, when the sale has several', () => {
		const rows = form.computed.vatRows.call({ totals: { totalTax: 5, taxBreakdown: [{ rate: 9, base: 10, tax: 0.9 }, { rate: 21, base: 20, tax: 4.2 }] } })
		expect(rows.map((r) => [r.key, r.tax])).toEqual([['9', 0.9], ['21', 4.2]])
		expect(rows[0].label).toMatch(/^VAT 9% \(base .*10,00\)$/)
	})

	it('shows the customer\'s email and loyalty balance', () => {
		expect(form.computed.customerMeta.call({ selectedCustomer: { email: 'a@b.nl' }, loyalty: { balance: 40 } })).toBe('a@b.nl · loyalty 40 points')
		expect(form.computed.customerMeta.call({ selectedCustomer: {}, loyalty: null })).toBe('')
	})
})
