/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 * SPDX-License-Identifier: EUPL-1.2
 *
 * The object store keeps a collection as a plain array; readers that took
 * `.results` from it read nothing. The refund line loaders read both shapes.
 *
 * @spec openspec/specs/pos-refund-return/spec.md#requirement-a-refund-reads-the-sales-and-lines-the-store-holds
 */

import { describe, expect, it, vi } from 'vitest'
import { collectionRows } from '../../src/services/collectionRows.js'
import { fetchOriginalLines, fetchRefundLines } from '../../src/services/posRefundLines.js'

describe('collectionRows', () => {
	it('reads a plain array, a results page and nothing', () => {
		expect(collectionRows([{ id: 1 }])).toEqual([{ id: 1 }])
		expect(collectionRows({ results: [{ id: 2 }] })).toEqual([{ id: 2 }])
		expect(collectionRows(null)).toEqual([])
	})
})

describe('the refund line loaders', () => {
	it('read the lines from the store\'s plain array', async () => {
		const rows = {
			posTransactionLine: [{ id: 'l1', transaction: 'tx1' }, { id: 'l9', transaction: 'tx9' }],
			posRefundLine: [{ id: 'r1', refund: 'ref1' }],
		}
		const store = { fetchCollection: vi.fn(async () => {}), getCollection: (type) => rows[type] || [] }
		expect((await fetchOriginalLines(store, 'tx1')).map((line) => line.id)).toEqual(['l1'])
		expect((await fetchRefundLines(store, 'ref1')).map((line) => line.id)).toEqual(['r1'])
	})
})
