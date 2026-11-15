/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 * SPDX-License-Identifier: EUPL-1.2
 *
 * The refund form's loading: what opening it reads for an edited refund or a
 * sale chosen in the route, the empty lists it falls back to when the store
 * refuses, the customer name in the header, and a refund without a sale.
 *
 * @spec openspec/specs/pos-refund-return/spec.md#requirement-a-refund-reads-the-sales-and-lines-the-store-holds
 */
import { beforeAll, describe, expect, it, vi } from 'vitest'

vi.mock('@nextcloud/dialogs', () => ({ showError: vi.fn(), showSuccess: vi.fn() }))
vi.mock('@conduction/nextcloud-vue', () => ({ CnDataTable: {}, CnDetailCard: {}, CnDetailGrid: {}, CnDetailPage: {} }))
vi.mock('@nextcloud/vue', () => ({ NcButton: {}, NcCheckboxRadioSwitch: {}, NcInputField: {}, NcSelect: {}, NcTextArea: {} }))
vi.mock('../../src/store/modules/object.js', () => ({ useObjectStore: () => ({}) }))

let form

beforeAll(async () => {
	globalThis.t = (app, text) => text
	form = (await import('../../src/views/pos/PosRefundForm.vue')).default
}, 60000)

/**
 * A context with the form's methods bound.
 *
 * @param {object} state The state.
 * @return {object} The context.
 */
function context(state = {}) {
	const ctx = { refund: {}, originalLines: [], candidates: [], transactions: [], reasons: [], clientName: '', loading: false, ...state }
	for (const [name, fn] of Object.entries(form.methods)) {
		if (!(name in ctx)) {
			ctx[name] = fn.bind(ctx)
		}
	}
	return ctx
}

/**
 * Loader stubs that record the order they ran in.
 *
 * @param {Array<string>} calls The record.
 * @return {object} The stubs.
 */
function loaders(calls) {
	const stub = (name) => vi.fn(async (arg) => { calls.push(arg === undefined ? name : [name, arg]) })
	return {
		loadTransactions: stub('transactions'),
		loadReasons: stub('reasons'),
		loadRefund: stub('refund'),
		loadOriginalLines: stub('lines'),
		loadClientName: stub('client'),
	}
}

describe('PosRefundForm opening', () => {
	it('reads an edited refund after the sales and reasons, then its customer', async () => {
		const calls = []
		const ctx = context({ isEdit: true, routeTransactionId: 'tx-9', ...loaders(calls) })
		await form.mounted.call(ctx)
		expect(calls).toEqual(['transactions', 'reasons', 'refund', 'client'])
		expect(ctx.refund.originalTransaction).toBeUndefined()
		expect(ctx.loading).toBe(false)
	})

	it('starts a new refund on the sale named in the route', async () => {
		const calls = []
		const ctx = context({ isEdit: false, routeTransactionId: 'tx-9', ...loaders(calls) })
		await form.mounted.call(ctx)
		expect(calls).toEqual(['transactions', 'reasons', ['lines', 'tx-9'], 'client'])
		expect(ctx.refund.originalTransaction).toBe('tx-9')
	})

	it('stops loading even when a read fails', async () => {
		const ctx = context({ isEdit: false, ...loaders([]) })
		ctx.loadReasons = vi.fn().mockRejectedValue(new Error('down'))
		await expect(form.mounted.call(ctx)).rejects.toThrow('down')
		expect(ctx.loading).toBe(false)
		expect(ctx.loadClientName).not.toHaveBeenCalled()
	})
})

describe('PosRefundForm store fallbacks', () => {
	it('lists no sales and no reasons when the store refuses', async () => {
		const objectStore = { fetchCollection: vi.fn().mockRejectedValue(new Error('down')) }
		const ctx = context({ objectStore, transactions: ['stale'], reasons: ['stale'] })
		await ctx.loadTransactions()
		await ctx.loadReasons()
		expect(ctx.transactions).toEqual([])
		expect(ctx.reasons).toEqual([])
		expect(objectStore.fetchCollection.mock.calls).toEqual([['posTransaction', { _limit: 500 }], ['refundReason', { _limit: 100 }]])
	})

	it('clears the lines and asks nothing for a refund without a sale', async () => {
		const objectStore = { fetchCollection: vi.fn() }
		const ctx = context({ objectStore, originalLines: ['old'], candidates: ['old'] })
		await ctx.loadOriginalLines('')
		expect(ctx.originalLines).toEqual([])
		expect(ctx.candidates).toEqual([])
		expect(objectStore.fetchCollection).not.toHaveBeenCalled()
	})

	it('writes a dash for a missing moment', () => {
		expect(context().formatDateTime('')).toBe('—')
		expect(context().formatDateTime(null)).toBe('—')
	})
})

describe('PosRefundForm customer name', () => {
	it('names nobody, and asks nothing, for a sale without a customer', async () => {
		const objectStore = { fetchObject: vi.fn() }
		const ctx = context({ objectStore, transaction: { id: 'tx' }, clientName: 'old' })
		await ctx.loadClientName()
		expect(ctx.clientName).toBe('')
		expect(objectStore.fetchObject).not.toHaveBeenCalled()
	})

	it('reads a contact customer before a client, by name or first and last name', async () => {
		const objectStore = { fetchObject: vi.fn().mockResolvedValue({ firstName: 'Kim', lastName: 'Jansen' }) }
		const ctx = context({ objectStore, transaction: { customer: 'k-1', client: 'c-1' } })
		await ctx.loadClientName()
		expect(objectStore.fetchObject).toHaveBeenCalledWith('contact', 'k-1')
		expect(ctx.clientName).toBe('Kim Jansen')

		objectStore.fetchObject.mockResolvedValue({ name: 'Bakkerij' })
		ctx.transaction = { client: 'c-1' }
		await ctx.loadClientName()
		expect(objectStore.fetchObject).toHaveBeenLastCalledWith('client', 'c-1')
		expect(ctx.clientName).toBe('Bakkerij')
	})

	it('names nobody when the customer cannot be read', async () => {
		const objectStore = { fetchObject: vi.fn().mockRejectedValue(new Error('gone')) }
		const ctx = context({ objectStore, transaction: { client: 'c-1' } })
		await ctx.loadClientName()
		expect(ctx.clientName).toBe('')
	})
})
