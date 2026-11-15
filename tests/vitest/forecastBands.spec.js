// SPDX-License-Identifier: EUPL-1.2
// Copyright (C) 2026 Conduction B.V.

/**
 * The forecast accuracy band and the attainment percentage.
 */

import { describe, expect, it } from 'vitest'
import { accuracyBand, attainmentPercent, isAtRisk } from '../../src/services/forecastMath.js'

describe('accuracyBand', () => {
	it('is green only above the green threshold', () => {
		expect(accuracyBand(0.91)).toBe('green')
		expect(accuracyBand(0.9)).toBe('amber')
	})

	it('is amber from the amber threshold up, red below it', () => {
		expect(accuracyBand(0.75)).toBe('amber')
		expect(accuracyBand(0.74)).toBe('red')
	})

	it('honours explicit thresholds', () => {
		expect(accuracyBand(0.6, 0.5, 0.4)).toBe('green')
		expect(accuracyBand(0.45, 0.5, 0.4)).toBe('amber')
		expect(accuracyBand(0.3, 0.5, 0.4)).toBe('red')
	})
})

describe('attainmentPercent', () => {
	it('rounds the share of the quota to a whole percent', () => {
		expect(attainmentPercent(333, 1000)).toBe(33)
		expect(attainmentPercent('1505', '1000')).toBe(151)
	})

	it('is zero without a positive quota', () => {
		expect(attainmentPercent(100, 0)).toBe(0)
		expect(attainmentPercent(100, -5)).toBe(0)
		expect(attainmentPercent(100, undefined)).toBe(0)
	})
})

describe('isAtRisk', () => {
	it('needs both low attainment and few days left', () => {
		expect(isAtRisk({ quota: 100, projected: 50, daysRemaining: 10 })).toBe(true)
		expect(isAtRisk({ quota: 100, projected: 50, daysRemaining: 40 })).toBe(false)
		expect(isAtRisk({ quota: 100, projected: 95, daysRemaining: 10 })).toBe(false)
		expect(isAtRisk({ quota: 0, projected: 0, daysRemaining: 1 })).toBe(false)
	})

	it('honours explicit thresholds', () => {
		expect(isAtRisk({ quota: 100, projected: 95, daysRemaining: 50 }, { percent: 99, days: 60 })).toBe(true)
	})
})
