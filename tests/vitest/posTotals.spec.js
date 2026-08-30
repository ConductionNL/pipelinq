// SPDX-License-Identifier: EUPL-1.2
// Copyright (C) 2026 Conduction B.V.

/**
 * Unit tests for src/services/posTotals.js — the POS total / BTW calculation
 * helpers. Exact-output assertions on the excl/incl price-mode extraction, the
 * discount clamp, the per-rate tax breakdown, refund proportions and the
 * nl-NL EUR formatter. These mirror PosTransactionService / PosRefundService.
 */

import { describe, it, expect } from 'vitest'
import {
	normalizePriceMode,
	rateDescription,
	recalculateLine,
	computeTotals,
	refundLineAmounts,
	computeRefundTotals,
	formatEur,
} from '../../src/services/posTotals.js'

describe('normalizePriceMode', () => {
	it('accepts excl / incl case-insensitively, defaults to excl', () => {
		expect(normalizePriceMode('incl')).toBe('incl')
		expect(normalizePriceMode(' EXCL ')).toBe('excl')
		expect(normalizePriceMode('nonsense')).toBe('excl')
		expect(normalizePriceMode(null)).toBe('excl')
	})
})

describe('rateDescription', () => {
	it('maps the known Dutch BTW rates', () => {
		expect(rateDescription(0)).toBe('Nultarief (0%)')
		expect(rateDescription(9)).toBe('Verlaagd tarief (9%)')
		expect(rateDescription(21)).toBe('Standaardtarief (21%)')
	})

	it('falls back to "<rate>% BTW" for other rates', () => {
		expect(rateDescription(6)).toBe('6% BTW')
	})
})

describe('recalculateLine', () => {
	it('adds BTW on top in excl mode', () => {
		const line = recalculateLine(
			{ quantity: 2, unitPrice: 10, taxRate: 21 },
			'excl',
		)
		expect(line.net).toBe(20)
		expect(line.taxAmount).toBe(4.2)
		expect(line.lineTotal).toBe(24.2)
	})

	it('extracts BTW out of the price in incl mode', () => {
		const line = recalculateLine(
			{ quantity: 1, unitPrice: 121, taxRate: 21 },
			'incl',
		)
		expect(line.net).toBe(100)
		expect(line.taxAmount).toBe(21)
		expect(line.lineTotal).toBe(121)
	})

	it('applies a percentage discount to the net base', () => {
		const line = recalculateLine(
			{ quantity: 1, unitPrice: 100, discount: 10, taxRate: 21 },
			'excl',
		)
		expect(line.net).toBe(90)
		expect(line.taxAmount).toBe(18.9)
	})

	it('clamps negative inputs and defaults the rate to 21', () => {
		const line = recalculateLine(
			{ quantity: -5, unitPrice: -3, discount: 200 },
			'excl',
		)
		expect(line.quantity).toBe(0)
		expect(line.unitPrice).toBe(0)
		expect(line.discount).toBe(100)
		expect(line.taxRate).toBe(21)
		expect(line.net).toBe(0)
	})
})

describe('computeTotals', () => {
	it('aggregates mixed-rate lines with a sorted per-rate breakdown', () => {
		const totals = computeTotals(
			[
				{ quantity: 1, unitPrice: 100, taxRate: 21 },
				{ quantity: 2, unitPrice: 50, taxRate: 9 },
			],
			'excl',
		)
		expect(totals.priceMode).toBe('excl')
		expect(totals.subtotal).toBe(200)
		expect(totals.totalTax).toBe(30) // 21 + 9
		expect(totals.total).toBe(230)
		// breakdown sorted ascending by rate
		expect(totals.taxBreakdown.map((b) => b.rate)).toEqual([9, 21])
		expect(totals.invoiceBreakdown[1]).toEqual({
			rate: 21,
			base: 100,
			tax: 21,
			description: 'Standaardtarief (21%)',
		})
	})

	it('accumulates the discount total across lines', () => {
		const totals = computeTotals(
			[{ quantity: 1, unitPrice: 100, discount: 25, taxRate: 21 }],
			'excl',
		)
		expect(totals.subtotal).toBe(75)
		expect(totals.discountTotal).toBe(25)
	})
})

describe('refundLineAmounts', () => {
	it('returns a proportional share clamped to [0, 1]', () => {
		const orig = { quantity: 4, taxAmount: 8, lineTotal: 48 }
		expect(refundLineAmounts(orig, 2)).toEqual({
			ratio: 0.5,
			taxAmount: 4,
			lineTotal: 24,
		})
		// over-quantity return cannot inflate the refund
		expect(refundLineAmounts(orig, 10)).toEqual({
			ratio: 1,
			taxAmount: 8,
			lineTotal: 48,
		})
	})

	it('returns a zero refund when the original quantity is zero', () => {
		expect(
			refundLineAmounts({ quantity: 0, taxAmount: 8, lineTotal: 48 }, 2),
		).toEqual({ ratio: 0, taxAmount: 0, lineTotal: 0 })
	})
})

describe('computeRefundTotals', () => {
	it('sums lines and derives the tax-exclusive refund amount', () => {
		const totals = computeRefundTotals([
			{ taxAmount: 4, lineTotal: 24 },
			{ taxAmount: 2, lineTotal: 12 },
		])
		expect(totals.totalTax).toBe(6)
		expect(totals.total).toBe(36)
		expect(totals.refundAmount).toBe(30)
	})
})

describe('formatEur', () => {
	it('formats nl-NL EUR with two decimals', () => {
		// Use a non-breaking-space-tolerant assertion: strip whitespace + currency symbol position.
		const formatted = formatEur(1234.5)
		expect(formatted.replace(/ /g, ' ')).toMatch(/€\s?1\.234,50/)
	})

	it('coerces non-numeric input to zero', () => {
		expect(formatEur('nope').replace(/ /g, ' ')).toMatch(/€\s?0,00/)
	})
})
