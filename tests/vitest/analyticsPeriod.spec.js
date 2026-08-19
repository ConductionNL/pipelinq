// SPDX-License-Identifier: EUPL-1.2
// Copyright (C) 2026 Conduction B.V.

/**
 * Unit tests for src/services/analyticsPeriod.js — the dashboard
 * date-range → analytics API period mapping used by the analytics
 * dashboard widgets (openspec/changes/decompose-unified-analytics
 * REQ-DASH-010).
 */

import { describe, it, expect } from 'vitest'
import { rangeToPeriod } from '../../src/services/analyticsPeriod.js'

describe('rangeToPeriod', () => {
	it('falls back to the backend default when no range is set', () => {
		expect(rangeToPeriod(null)).toBe('month')
		expect(rangeToPeriod(undefined)).toBe('month')
	})

	it('maps the four manifest preset ids straight through', () => {
		for (const preset of ['week', 'month', 'quarter', 'year']) {
			expect(rangeToPeriod({ from: '', to: '', preset })).toBe(preset)
		}
	})

	it('maps a custom range to the nearest trailing window by day span', () => {
		const range = (days) => ({
			from: '2026-01-01T00:00:00Z',
			to: new Date(Date.parse('2026-01-01T00:00:00Z') + days * 86400000).toISOString(),
			preset: 'custom',
		})
		expect(rangeToPeriod(range(3))).toBe('week')
		expect(rangeToPeriod(range(10))).toBe('week')
		expect(rangeToPeriod(range(11))).toBe('month')
		expect(rangeToPeriod(range(45))).toBe('month')
		expect(rangeToPeriod(range(46))).toBe('quarter')
		expect(rangeToPeriod(range(180))).toBe('quarter')
		expect(rangeToPeriod(range(181))).toBe('year')
		expect(rangeToPeriod(range(800))).toBe('year')
	})

	it('falls back to month on unparseable or inverted ranges', () => {
		expect(rangeToPeriod({ from: 'nonsense', to: 'also nonsense', preset: 'custom' })).toBe('month')
		expect(rangeToPeriod({
			from: '2026-02-01T00:00:00Z',
			to: '2026-01-01T00:00:00Z',
			preset: 'custom',
		})).toBe('month')
	})
})
