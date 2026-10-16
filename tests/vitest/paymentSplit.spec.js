// SPDX-License-Identifier: EUPL-1.2
// Copyright (C) 2026 Conduction B.V.

/**
 * Splitting the amount due at checkout (openspec/changes/screens-kassa-till-board).
 */

import { describe, expect, it } from 'vitest'
import { loyaltyValue, maxLoyaltyPoints, splitPayment, tenderTypeId } from '../../src/services/paymentSplit.js'

describe('loyaltyValue and maxLoyaltyPoints', () => {
	it('values points at the programme rate, rounded down to cents', () => {
		expect(loyaltyValue(200, 20)).toBe(10)
		expect(loyaltyValue(19, 20)).toBe(0.95)
		expect(loyaltyValue(200, 0)).toBe(0)
	})

	it('caps the points at the balance and at what the amount is worth', () => {
		expect(maxLoyaltyPoints(340, 20, 96.41)).toBe(340)
		expect(maxLoyaltyPoints(5000, 20, 10)).toBe(200)
		expect(maxLoyaltyPoints(340, 0, 10)).toBe(0)
	})
})

describe('splitPayment', () => {
	it('splits as the board does: points, then a gift card, then the rest', () => {
		const split = splitPayment(96.41, {
			loyalty: { points: 200, pointsPerEuro: 20 },
			giftCards: [{ serial: 'ZD-GC-7731', balance: 25 }],
		})
		expect(split.parts.map((p) => [p.kind, p.amount])).toEqual([['loyalty', 10], ['giftCard', 25]])
		expect(split.parts[1].balanceAfter).toBe(0)
		expect(split.rest).toBe(61.41)
		expect(split.covered).toBe(35)
	})

	it('never takes more from a gift card than is still open', () => {
		const split = splitPayment(20, { giftCards: [{ serial: 'A', balance: 50 }, { serial: 'B', balance: 10 }] })
		expect(split.parts).toHaveLength(1)
		expect(split.parts[0]).toMatchObject({ serial: 'A', amount: 20, balanceAfter: 30 })
		expect(split.rest).toBe(0)
	})

	it('leaves everything for the rest without parts', () => {
		expect(splitPayment(12.5)).toEqual({ parts: [], rest: 12.5, covered: 0, due: 12.5 })
	})
})

describe('tenderTypeId', () => {
	const types = [{ id: 'c1', code: 'CARD' }, { id: 'v1', code: 'VOUCHER', isActive: false }, { '@self': { id: 'k1' }, code: 'cash' }]

	it('finds an active type by code, any case', () => {
		expect(tenderTypeId(types, 'card')).toBe('c1')
		expect(tenderTypeId(types, 'CASH')).toBe('k1')
	})

	it('skips an inactive or missing type', () => {
		expect(tenderTypeId(types, 'VOUCHER')).toBe('')
		expect(tenderTypeId(types, 'OTHER')).toBe('')
	})
})
