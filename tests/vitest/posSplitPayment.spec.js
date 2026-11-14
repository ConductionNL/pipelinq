/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 * SPDX-License-Identifier: EUPL-1.2
 *
 * How a sale's payment splits into loyalty points, gift cards and the rest
 * (PqKassaAfrekenen).
 *
 * @spec openspec/specs/pos-transactions/spec.md#requirement-a-payment-can-be-split
 */
import { describe, expect, it } from 'vitest'
import { maxPoints, pointsAmount, splitPayment, tenderTypeFor } from '../../src/services/posSplitPayment.js'

describe('the split payment', () => {
	it('values points at the programme point value', () => {
		expect(pointsAmount(200, 0.05)).toBe(10)
		expect(pointsAmount(0, 0.05)).toBe(0)
	})

	it('uses at most the balance and never more than is still due', () => {
		expect(maxPoints(340, 0.05, 96.41)).toBe(340)
		expect(maxPoints(340, 0.05, 10)).toBe(200)
		expect(maxPoints(340, 0, 10)).toBe(0)
	})

	it('pays the rest after points and gift cards, and closes when the parts add up', () => {
		const split = splitPayment(96.41, { pointsValue: 10, giftCards: [{ amount: 25 }] })
		expect(split.rest).toBe(61.41)
		expect(split.parts).toEqual([10, 25, 61.41])
		expect(split.closes).toBe(true)
	})

	it('does not let a gift card pay more than is still due', () => {
		const split = splitPayment(20, { pointsValue: 0, giftCards: [{ amount: 25 }] })
		expect(split.giftCards[0].amount).toBe(20)
		expect(split.rest).toBe(0)
		expect(split.parts).toEqual([20])
	})

	it('finds the tender type for a part by its code', () => {
		const types = [{ id: 'a', code: 'CONTANT' }, { id: 'b', code: 'PIN' }, { id: 'c', code: 'BON' }, { id: 'd', code: 'LOYALTY' }]
		expect(tenderTypeFor('card', types).id).toBe('b')
		expect(tenderTypeFor('cash', types).id).toBe('a')
		expect(tenderTypeFor('giftCard', types).id).toBe('c')
		expect(tenderTypeFor('points', types).id).toBe('d')
		expect(tenderTypeFor('onAccount', types)).toBeNull()
	})
})
