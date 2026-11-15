// SPDX-License-Identifier: EUPL-1.2
// Copyright (C) 2026 Conduction B.V.

/**
 * The refund and original-transaction line readers: what they ask the store
 * for, and that only the lines of the asked-for parent come back.
 */

import { describe, expect, it, vi } from 'vitest'
import { fetchOriginalLines, fetchRefundLines } from '../../src/services/posRefundLines.js'

function store(rows) {
	return {
		fetchCollection: vi.fn().mockResolvedValue(undefined),
		getCollection: vi.fn(() => rows),
	}
}

describe('fetchRefundLines', () => {
	it('returns nothing and asks nothing without a refund id', async () => {
		const s = store([])
		expect(await fetchRefundLines(s, '')).toEqual([])
		expect(s.fetchCollection).not.toHaveBeenCalled()
	})

	it('asks for the refund lines and keeps only that refund', async () => {
		const s = store([{ id: 1, refund: 'r1' }, { id: 2, refund: 'r2' }, { id: 3, refund: 'r1' }])
		expect(await fetchRefundLines(s, 'r1')).toEqual([{ id: 1, refund: 'r1' }, { id: 3, refund: 'r1' }])
		expect(s.fetchCollection).toHaveBeenCalledWith('posRefundLine', { refund: 'r1', _limit: 500 })
		expect(s.getCollection).toHaveBeenCalledWith('posRefundLine')
	})
})

describe('fetchOriginalLines', () => {
	it('returns nothing and asks nothing without a transaction id', async () => {
		const s = store([])
		expect(await fetchOriginalLines(s, null)).toEqual([])
		expect(s.fetchCollection).not.toHaveBeenCalled()
	})

	it('asks for the transaction lines and keeps only that transaction', async () => {
		const s = store([{ id: 1, transaction: 't1' }, { id: 2, transaction: 't2' }])
		expect(await fetchOriginalLines(s, 't2')).toEqual([{ id: 2, transaction: 't2' }])
		expect(s.fetchCollection).toHaveBeenCalledWith('posTransactionLine', { transaction: 't2', _limit: 500 })
		expect(s.getCollection).toHaveBeenCalledWith('posTransactionLine')
	})
})
