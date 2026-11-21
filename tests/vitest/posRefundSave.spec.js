// @vitest-environment jsdom
/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Saving a refund at the till: what it refuses, the refund it writes, the
 * returned lines it writes after it, and where it goes next.
 *
 * @spec openspec/specs/pos/spec.md
 */
import { beforeAll, beforeEach, describe, expect, it, vi } from 'vitest'

const dialogs = vi.hoisted(() => ({ showError: vi.fn(), showSuccess: vi.fn() }))
vi.mock('@nextcloud/dialogs', () => dialogs)
vi.mock('@nextcloud/vue', () => ({ NcButton: {}, NcCheckboxRadioSwitch: {}, NcInputField: {}, NcSelect: {}, NcTextArea: {} }))
vi.mock('@conduction/nextcloud-vue', () => ({ CnDataTable: {}, CnDetailCard: {}, CnDetailGrid: {}, CnDetailPage: {} }))
vi.mock('../../src/store/modules/object.js', () => ({ useObjectStore: () => ({}) }))

let form

beforeAll(async () => {
	globalThis.t = (app, text) => text
	form = (await import('../../src/views/pos/PosRefundForm.vue')).default
}, 60000)

beforeEach(() => {
	dialogs.showError.mockReset()
	dialogs.showSuccess.mockReset()
	window.OC = { getCurrentUser: () => ({ uid: 'kassa1' }) }
})

/**
 * @param {object} state The form state.
 * @return {object} A form context with `save` bound.
 */
function bind(state) {
	const ctx = {
		saving: false,
		refundId: 'route-id',
		refund: { originalTransaction: 'tx1', refundReason: 'damaged', status: '', cashier: '' },
		objectStore: { saveObject: vi.fn(async (type, data) => (type === 'posRefund' ? { id: 'rf1' } : data)) },
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

const line = (extra = {}) => ({ originalLine: 'ol1', returnedQuantity: '2', taxAmount: 1, lineTotal: 10, valid: true, ...extra })

describe('PosRefundForm.save', () => {
	it('refuses no lines, no receipt and a quantity over the original, in that order', async () => {
		const cases = [
			[{ selectedLines: [] , refund: {} }, 'Select at least one item to return'],
			[{ selectedLines: [line({ valid: false })], refund: {} }, 'Original receipt is required'],
			[{ selectedLines: [line(), line({ valid: false })] }, 'Returned quantity may not exceed the original quantity'],
		]
		for (const [state, message] of cases) {
			const ctx = bind(state)
			await ctx.save()
			expect(dialogs.showError).toHaveBeenLastCalledWith(message)
			expect(ctx.objectStore.saveObject).not.toHaveBeenCalled()
			expect(ctx.saving).toBe(false)
		}
	})

	it('writes the refund as pending by the current cashier, then each line, then opens the refund', async () => {
		const ctx = bind({ selectedLines: [line({ id: 'rl1', restock: false, returnReason: 'wrong' }), line({ originalLine: 'ol2', returnedQuantity: 'x' })] })
		await ctx.save()
		expect(ctx.objectStore.saveObject.mock.calls).toEqual([
			['posRefund', { originalTransaction: 'tx1', refundReason: 'damaged', status: 'pending', cashier: 'kassa1' }],
			['posRefundLine', { refund: 'rf1', originalLine: 'ol1', returnedQuantity: 2, returnReason: 'wrong', restock: false, taxAmount: 1, lineTotal: 10, id: 'rl1' }],
			['posRefundLine', { refund: 'rf1', originalLine: 'ol2', returnedQuantity: 0, returnReason: 'damaged', restock: true, taxAmount: 1, lineTotal: 10 }],
		])
		expect(dialogs.showSuccess).toHaveBeenCalledWith('Refund saved.')
		expect(ctx.$router.push).toHaveBeenCalledWith({ name: 'PosRefundDetail', params: { id: 'rf1' } })
		expect(ctx.saving).toBe(false)
	})

	it('keeps a set status and cashier, falls back to the route id, and to no cashier without a user', async () => {
		window.OC = undefined
		const saveObject = vi.fn(async (type) => (type === 'posRefund' ? {} : {}))
		const ctx = bind({ selectedLines: [line()], objectStore: { saveObject } })
		await ctx.save()
		expect(saveObject.mock.calls[0][1]).toMatchObject({ status: 'pending', cashier: '' })
		expect(saveObject.mock.calls[1][1].refund).toBe('route-id')
		const kept = bind({ selectedLines: [line()], refund: { originalTransaction: 'tx', status: 'done', cashier: 'me' } })
		await kept.save()
		expect(kept.objectStore.saveObject.mock.calls[0][1]).toEqual({ originalTransaction: 'tx', status: 'done', cashier: 'me' })
	})

	it('says it failed when the refund is not saved or a save throws', async () => {
		const refused = bind({ selectedLines: [line()], objectStore: { saveObject: vi.fn(async () => null) } })
		await refused.save()
		expect(dialogs.showError).toHaveBeenLastCalledWith('Failed to save refund.')
		expect(refused.objectStore.saveObject).toHaveBeenCalledTimes(1)
		expect(refused.saving).toBe(false)
		const thrown = bind({ selectedLines: [line()], objectStore: { saveObject: vi.fn().mockResolvedValueOnce({ id: 'r' }).mockRejectedValueOnce(new Error('x')) } })
		await thrown.save()
		expect(dialogs.showError).toHaveBeenLastCalledWith('Failed to save refund.')
		expect(thrown.$router.push).not.toHaveBeenCalled()
		expect(dialogs.showSuccess).not.toHaveBeenCalled()
	})
})
