// SPDX-License-Identifier: EUPL-1.2
// Copyright (C) 2026 Conduction B.V.

/**
 * Unit tests for src/services/posTotals.js: refund proportions, the
 * translated VAT rate names and the amount formatter.
 */

import { describe, expect, it } from 'vitest'
import {
	computeRefundTotals,
	formatEur,
	rateLabel,
	refundLineAmounts,
} from '../../src/services/posTotals.js'

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
		const formatted = formatEur(1234.5, 'EUR', 'nl-NL')
		expect(formatted.replace(/ /g, ' ')).toMatch(/€\s?1\.234,50/)
	})

	it('coerces non-numeric input to zero', () => {
		expect(formatEur('nope', 'EUR', 'nl-NL').replace(/ /g, ' ')).toMatch(
			/€\s?0,00/,
		)
	})

	it('follows the setup currency and the user locale (pipelinq-audit-admin-forms-pos)', () => {
		expect(formatEur(1234.5, 'USD', 'en-US')).toBe('$1,234.50')
		expect(formatEur(1234.5, 'GBP', 'en-GB')).toBe('£1,234.50')
	})
})

describe('rateLabel', () => {
	it('names the VAT rates in English, not in Dutch', () => {
		expect(rateLabel(0)).toBe('Zero rate (0%)')
		expect(rateLabel(9)).toBe('Reduced rate (9%)')
		expect(rateLabel(21)).toBe('Standard rate (21%)')
		expect(rateLabel(6)).toBe('6% VAT')
	})
})
