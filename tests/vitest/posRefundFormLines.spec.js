/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 * SPDX-License-Identifier: EUPL-1.2
 *
 * The refund form's take-back list: loading a stored refund against its sale's lines,
 * the quantity bound, and choosing the sale or reason.
 *
 * @spec openspec/specs/pos-refund-return/spec.md
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
 * An object store over fixed collections.
 *
 * @param {object} refund The refund fetchObject answers.
 * @param {object} collections Collection name to rows.
 * @return {object} The store, with `fetched` recording the collection reads.
 */
function store(refund, collections) {
	return {
		fetched: [],
		fetchObject: vi.fn().mockResolvedValue(refund),
		async fetchCollection(name, params) {
			this.fetched.push([name, params])
		},
		getCollection: (name) => ({ results: collections[name] || [] }),
	}
}

/**
 * A context with the form's methods bound.
 *
 * @param {object} state The state.
 * @return {object} The context.
 */
function context(state = {}) {
	const ctx = { refund: {}, originalLines: [], candidates: [], ...state }
	for (const [name, fn] of Object.entries(form.methods)) {
		if (!(name in ctx)) {
			ctx[name] = fn.bind(ctx)
		}
	}
	return ctx
}

const SALE_LINES = [
	{ id: 'l-2', transaction: 'tx-1', quantity: 1, sortOrder: 2 },
	{ id: 'l-1', transaction: 'tx-1', quantity: 3, sortOrder: 1 },
	{ id: 'l-x', transaction: 'tx-other', quantity: 9 },
]

describe('PosRefundForm loadRefund', () => {
	it('reads the refund, its sale\'s lines in order, and marks the lines already taken back', async () => {
		const objectStore = store(
			{ id: 'rf-1', originalTransaction: 'tx-1', refundReason: 'defect' },
			{
				posTransactionLine: SALE_LINES,
				posRefundLine: [
					{ id: 'rl-1', refund: 'rf-1', originalLine: 'l-2', returnedQuantity: 1, returnReason: 'wrong', restock: false },
					{ id: 'rl-x', refund: 'rf-other', originalLine: 'l-1', returnedQuantity: 3 },
				],
			}
		)
		const ctx = context({ objectStore, refundId: 'rf-1' })

		await ctx.loadRefund()

		expect(objectStore.fetchObject).toHaveBeenCalledWith('posRefund', 'rf-1')
		expect(objectStore.fetched).toEqual([
			['posTransactionLine', { transaction: 'tx-1', _limit: 500 }],
			['posRefundLine', { refund: 'rf-1', _limit: 500 }],
		])
		expect(ctx.originalLines.map((l) => l.id)).toEqual(['l-1', 'l-2'])
		expect(ctx.candidates).toEqual([
			{ originalLine: 'l-1', selected: false, returnedQuantity: 3, returnReason: 'defect', restock: true, valid: true },
			{ id: 'rl-1', originalLine: 'l-2', selected: true, returnedQuantity: 1, returnReason: 'wrong', restock: false, valid: true },
		])
	})

	it('restocks a stored line that does not say otherwise', async () => {
		const objectStore = store(
			{ id: 'rf-1', originalTransaction: 'tx-1' },
			{ posTransactionLine: [SALE_LINES[0]], posRefundLine: [{ id: 'rl-1', refund: 'rf-1', originalLine: 'l-2', returnedQuantity: 1 }] }
		)
		const ctx = context({ objectStore, refundId: 'rf-1' })
		await ctx.loadRefund()
		expect(ctx.candidates[0].restock).toBe(true)
	})
})

describe('PosRefundForm lines', () => {
	it('bounds the quantity between one and what was sold', () => {
		const ctx = context({
			originalLines: [{ id: 'l-1', quantity: 3 }],
			candidates: [{ originalLine: 'l-1', returnedQuantity: 3, valid: true }],
		})

		ctx.setQuantity(0, '2')
		expect(ctx.candidates[0]).toMatchObject({ returnedQuantity: '2', valid: true })
		ctx.setQuantity(0, '3')
		expect(ctx.candidates[0].valid).toBe(true)
		ctx.setQuantity(0, '4')
		expect(ctx.candidates[0].valid).toBe(false)
		ctx.setQuantity(0, '0')
		expect(ctx.candidates[0].valid).toBe(false)
		ctx.setQuantity(0, 'twee')
		expect(ctx.candidates[0].valid).toBe(false)
	})

	it('never accepts a quantity for a line the sale no longer has', () => {
		const ctx = context({ candidates: [{ originalLine: 'gone' }] })
		ctx.setQuantity(0, '1')
		expect(ctx.candidates[0].valid).toBe(false)
	})

	it('sets the reason, or clears it', () => {
		const ctx = context()
		ctx.onReasonSelect({ id: 'defect' })
		expect(ctx.refund.refundReason).toBe('defect')
		ctx.onReasonSelect(null)
		expect(ctx.refund.refundReason).toBeNull()
	})

	it('choosing the sale takes its payment details and loads its lines and client', async () => {
		const loadOriginalLines = vi.fn()
		const loadClientName = vi.fn()
		const ctx = context({ loadOriginalLines, loadClientName, refund: { paymentMethod: 'cash', paymentReference: 'old' } })
		ctx.transaction = { paymentMethod: 'pin' }

		await ctx.onTransactionSelect({ id: 'tx-1' })

		expect(ctx.refund).toEqual({ originalTransaction: 'tx-1', paymentMethod: 'pin', paymentReference: 'old' })
		expect(loadOriginalLines).toHaveBeenCalledWith('tx-1')
		expect(loadClientName).toHaveBeenCalled()
	})

	it('clearing the sale keeps the payment details and loads no lines', async () => {
		const loadOriginalLines = vi.fn()
		const ctx = context({ loadOriginalLines, loadClientName: vi.fn(), refund: { paymentMethod: 'cash' } })
		ctx.transaction = null

		await ctx.onTransactionSelect(null)

		expect(ctx.refund).toEqual({ originalTransaction: null, paymentMethod: 'cash' })
		expect(loadOriginalLines).toHaveBeenCalledWith(null)
	})
})
