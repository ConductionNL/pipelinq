/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 * SPDX-License-Identifier: EUPL-1.2
 *
 * The till's own actions: saving and parking a sale, the selectors, and attaching
 * or clearing a customer. Each runs against a plain context so the request it makes
 * and the state it leaves are read directly.
 */
import { beforeAll, beforeEach, describe, expect, it, vi } from 'vitest'

const http = vi.hoisted(() => ({ get: vi.fn(), post: vi.fn() }))
const dialogs = vi.hoisted(() => ({ showError: vi.fn(), showSuccess: vi.fn(), showWarning: vi.fn() }))
const customers = vi.hoisted(() => ({ attachCustomer: vi.fn(), detachCustomer: vi.fn(), getCustomerHistory: vi.fn() }))

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
vi.mock('../../src/services/posCustomerApi.js', () => customers)
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
		transaction: { client: null, customer: null, marketingConsent: true, tenderType: 'cash', priceMode: 'excl' },
		$router: { push: vi.fn() },
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
	http.get.mockReset()
	http.post.mockReset()
	Object.values(dialogs).forEach((fn) => fn.mockReset())
	Object.values(customers).forEach((fn) => fn.mockReset())
})

describe('PosTransactionForm save', () => {
	it('refuses a checkout that is not allowed, with the reason', async () => {
		const persistSale = vi.fn()
		const ctx = context({ checkoutAllowed: false, onAccountError: 'This customer may not buy on account.', persistSale })

		await ctx.save()

		expect(dialogs.showError).toHaveBeenCalledWith('This customer may not buy on account.')
		expect(persistSale).not.toHaveBeenCalled()
	})

	it('confirms the sale, issues sold gift cards and opens the stored sale', async () => {
		const persistSale = vi.fn().mockResolvedValue('tx 1')
		const issueSoldGiftCards = vi.fn().mockResolvedValue()
		const ctx = context({ checkoutAllowed: true, persistSale, issueSoldGiftCards })

		await ctx.save()

		expect(persistSale).toHaveBeenCalledWith(true)
		expect(issueSoldGiftCards).toHaveBeenCalledWith('tx 1')
		expect(dialogs.showSuccess).toHaveBeenCalledWith('Transaction saved.')
		expect(ctx.$router.push).toHaveBeenCalledWith({ name: 'PosTransactionDetail', params: { id: 'tx 1' } })
		expect(ctx.saving).toBe(false)
	})

	it('stays when nothing was stored, and reports a failure', async () => {
		const ctx = context({ checkoutAllowed: true, persistSale: vi.fn().mockResolvedValue(null), issueSoldGiftCards: vi.fn() })
		await ctx.save()
		expect(ctx.$router.push).not.toHaveBeenCalled()
		expect(ctx.issueSoldGiftCards).not.toHaveBeenCalled()

		ctx.persistSale = vi.fn(() => Promise.reject(new Error('down')))
		await ctx.save()
		expect(dialogs.showError).toHaveBeenCalledWith('Failed to save transaction.')
		expect(ctx.saving).toBe(false)
	})
})

describe('PosTransactionForm park', () => {
	it('stores the sale unconfirmed, parks it and returns to the list', async () => {
		http.post.mockResolvedValue({})
		const persistSale = vi.fn().mockResolvedValue('tx 1')
		const ctx = context({ persistSale })

		await ctx.park()

		expect(persistSale).toHaveBeenCalledWith(false)
		expect(http.post).toHaveBeenCalledWith('/apps/pipelinq/api/pos-transactions/tx%201/park')
		expect(dialogs.showSuccess).toHaveBeenCalledWith('Transaction parked.')
		expect(ctx.$router.push).toHaveBeenCalledWith({ name: 'PosTransactions' })
		expect(ctx.saving).toBe(false)
	})

	it('parks nothing when nothing was stored, and reports a refused park', async () => {
		const ctx = context({ persistSale: vi.fn().mockResolvedValue(null) })
		await ctx.park()
		expect(http.post).not.toHaveBeenCalled()

		ctx.persistSale = vi.fn().mockResolvedValue('tx-1')
		http.post.mockImplementationOnce(() => Promise.reject(new Error('locked')))
		await ctx.park()
		expect(dialogs.showError).toHaveBeenCalledWith('The transaction could not be parked.')
		expect(ctx.$router.push).not.toHaveBeenCalled()
		expect(ctx.saving).toBe(false)
	})
})

describe('PosTransactionForm selectors', () => {
	it('sets the client, the tender type and the price mode, defaulting each', () => {
		const scheduleSync = vi.fn()
		const ctx = context({ scheduleSync })

		ctx.onClientSelect('c-1')
		expect(ctx.transaction.client).toBe('c-1')
		ctx.onClientSelect('')
		expect(ctx.transaction.client).toBeNull()

		ctx.onTenderSelect({ id: 'pin' })
		expect(ctx.transaction.tenderType).toBe('pin')
		ctx.onTenderSelect(null)
		expect(ctx.transaction.tenderType).toBe('cash')

		ctx.onPriceModeSelect({ id: 'incl' })
		expect(ctx.transaction.priceMode).toBe('incl')
		expect(ctx.headerDirty).toBe(true)
		expect(scheduleSync).toHaveBeenCalledWith(0)
		ctx.onPriceModeSelect(null)
		expect(ctx.transaction.priceMode).toBe('excl')
	})

	it('settles cash at once and leaves other providers pending', () => {
		const ctx = context()
		ctx.onPaymentSelectionChange({ providerName: 'mollie', paymentMethod: 'ideal' })
		expect(ctx.transaction).toMatchObject({ paymentProvider: 'mollie', paymentMethod: 'ideal', paymentStatus: 'pending' })

		ctx.onPaymentSelectionChange({ providerName: 'adyen' })
		expect(ctx.transaction.paymentMethod).toBe('adyen')

		ctx.onPaymentSelectionChange({})
		expect(ctx.transaction).toMatchObject({ paymentProvider: 'cash', paymentMethod: 'cash', paymentStatus: 'settled' })
	})
})

describe('PosTransactionForm customer', () => {
	it('opens and closes the lookup', () => {
		const ctx = context()
		ctx.openCustomerLookup()
		expect(ctx.showCustomerModal).toBe(true)
		ctx.closeCustomerLookup()
		expect(ctx.showCustomerModal).toBe(false)
	})

	it('takes the chosen customer, withdraws consent for one who may not be contacted, and loads their facts', async () => {
		const loadCustomerFacts = vi.fn().mockResolvedValue()
		const ctx = context({ loadCustomerFacts, showCustomerModal: true })

		await ctx.onCustomerSelected({ id: 'p-1', doNotContact: true })

		expect(ctx.selectedCustomer).toEqual({ id: 'p-1', doNotContact: true })
		expect(ctx.transaction.customer).toBe('p-1')
		expect(ctx.transaction.marketingConsent).toBe(false)
		expect(dialogs.showWarning).toHaveBeenCalledWith('This customer does not wish to be contacted.')
		expect(ctx.showCustomerModal).toBe(false)
		expect(loadCustomerFacts).toHaveBeenCalledWith('p-1')
	})

	it('keeps consent for a customer who may be contacted', async () => {
		const ctx = context({ loadCustomerFacts: vi.fn() })
		await ctx.onCustomerSelected({ id: 'p-2' })
		expect(ctx.transaction.marketingConsent).toBe(true)
		expect(dialogs.showWarning).not.toHaveBeenCalled()
	})

	it('clears the customer, the client too when the customer was one, and detaches a stored sale', async () => {
		customers.detachCustomer.mockResolvedValue({})
		const ctx = context({ isNew: false, transactionId: 'tx-1', selectedCustomer: { id: 'c-1', isClient: true }, history: [1], loyalty: {} })
		ctx.transaction.client = 'c-1'
		ctx.transaction.customer = 'c-1'

		await ctx.clearCustomer()

		expect(ctx.transaction).toMatchObject({ client: null, customer: null, marketingConsent: false })
		expect(ctx.selectedCustomer).toBeNull()
		expect(ctx.history).toEqual([])
		expect(ctx.loyalty).toBeNull()
		expect(customers.detachCustomer).toHaveBeenCalledWith('tx-1')
	})

	it('keeps the client for a person, skips the detach on a new sale, and survives a failed detach', async () => {
		const fresh = context({ isNew: true, selectedCustomer: { id: 'p-1' } })
		fresh.transaction.client = 'c-9'
		await fresh.clearCustomer()
		expect(fresh.transaction.client).toBe('c-9')
		expect(customers.detachCustomer).not.toHaveBeenCalled()

		customers.detachCustomer.mockImplementationOnce(() => Promise.reject(new Error('gone')))
		const stored = context({ isNew: false, transactionId: 'tx-1', selectedCustomer: null })
		await expect(stored.clearCustomer()).resolves.toBeUndefined()
		expect(stored.transaction.customer).toBeNull()
	})

	it('loads the history and loyalty, keeping an empty history when it cannot be read', async () => {
		const loadLoyalty = vi.fn().mockResolvedValue({ accountId: 'l-1' })
		customers.getCustomerHistory.mockResolvedValueOnce([{ id: 'tx-0' }])
		const ctx = context({ loadLoyalty })

		await ctx.loadCustomerFacts('p-1')
		expect(customers.getCustomerHistory).toHaveBeenCalledWith('p-1', 0)
		expect(ctx.history).toEqual([{ id: 'tx-0' }])
		expect(ctx.loyalty).toEqual({ accountId: 'l-1' })

		customers.getCustomerHistory.mockImplementationOnce(() => Promise.reject(new Error('down')))
		await ctx.loadCustomerFacts('p-1')
		expect(ctx.history).toEqual([])
	})
})
