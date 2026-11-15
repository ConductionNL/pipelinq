// SPDX-License-Identifier: EUPL-1.2
// Copyright (C) 2026 Conduction B.V.

/**
 * The till sends what the cashier entered and shows what the server priced.
 *
 * @spec openspec/changes/till-server-priced-lines/tasks.md#task-4
 */

import { describe, expect, it, vi } from 'vitest'

vi.mock('@nextcloud/router', () => ({ generateUrl: (url) => url }))
const post = vi.fn(async () => ({ data: { transaction: { total: 12.1 }, lines: [{ id: 'l1', lineTotal: 12.1 }] } }))
vi.mock('@nextcloud/axios', () => ({ default: { post: (...args) => post(...args) } }))

const { headerPayload, isStorable, linePayload, recalculateSale, storedTotals, withServerFigures } = await import('../../src/services/posCart.js')

describe('what the till sends', () => {
	it('stores a line without any figure the server works out', () => {
		const payload = linePayload({ id: 'l1', description: 'Afvalpas', quantity: '2', unitPrice: '12.5', discount: '', taxRate: 21, taxAmount: 9, lineTotal: 9 }, { transactionId: 'tx-1', sortOrder: 3 })
		expect(payload).toEqual({ id: 'l1', transaction: 'tx-1', product: null, description: 'Afvalpas', quantity: 2, unitPrice: 12.5, discount: 0, taxRate: 21, sortOrder: 3, notes: '' })
	})

	it('stores the sale header without its totals', () => {
		const header = headerPayload({ status: 'draft', priceMode: 'incl', subtotal: 1, discountTotal: 1, taxBreakdown: [], invoiceBreakdown: [], totalTax: 1, total: 1 })
		expect(header).toEqual({ status: 'draft', priceMode: 'incl' })
	})

	it('stores a line only once it has a description', () => {
		expect([isStorable({ description: ' ' }), isStorable({ description: 'Pen' })]).toEqual([false, true])
	})
})

describe('what the till shows', () => {
	it('reads the totals the server stored, with zeros for a new sale', () => {
		expect(storedTotals({})).toEqual({ subtotal: 0, discountTotal: 0, taxBreakdown: [], totalTax: 0, total: 0 })
		expect(storedTotals({ subtotal: 10, totalTax: 2.1, total: 12.1, taxBreakdown: [{ rate: 21 }] }).total).toBe(12.1)
	})

	it('copies the server figures onto the stored lines only', () => {
		const lines = withServerFigures([{ _key: 1, id: 'l1', lineTotal: 0 }, { _key: 2, lineTotal: 0 }], [{ '@self': { id: 'l1' }, taxAmount: 2.1, lineTotal: 12.1 }])
		expect(lines).toEqual([{ _key: 1, id: 'l1', taxAmount: 2.1, lineTotal: 12.1 }, { _key: 2, lineTotal: 0 }])
	})

	it('asks the server to price the sale', async () => {
		const sale = await recalculateSale('tx 1')
		expect(post).toHaveBeenCalledWith('/apps/pipelinq/api/pos-transactions/tx%201/recalculate')
		expect(sale).toEqual({ transaction: { total: 12.1 }, lines: [{ id: 'l1', lineTotal: 12.1 }] })
	})
})
