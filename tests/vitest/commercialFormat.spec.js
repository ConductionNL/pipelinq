// SPDX-License-Identifier: EUPL-1.2
// Copyright (C) 2026 Conduction B.V.

/**
 * Unit tests for src/services/commercialFormat.js — the euro formatting
 * helpers shared by the commercial dashboard widgets
 * (openspec/changes/commercial-dashboard).
 */

import { describe, it, expect } from 'vitest'
import { formatEur, formatEurCompact } from '../../src/services/commercialFormat.js'

describe('formatEur', () => {
	it('renders an em dash for null/undefined/NaN', () => {
		expect(formatEur(null)).toBe('—')
		expect(formatEur(undefined)).toBe('—')
		expect(formatEur(Number.NaN)).toBe('—')
		expect(formatEur('not a number')).toBe('—')
	})

	it('formats a whole-euro amount with the euro symbol and no fractional part by default', () => {
		const out = formatEur(1234)
		expect(out).toContain('€')
		expect(out).toContain('1')
		// No decimal fraction (the digit grouping separator is allowed).
		expect(out).not.toMatch(/[.,]\d{2}\b/)
	})

	it('honours the maximumFractionDigits argument', () => {
		const out = formatEur(1234.5, 2)
		expect(out).toMatch(/50$/)
	})

	it('accepts numeric strings', () => {
		expect(formatEur('1000')).toContain('€')
	})
})

describe('formatEurCompact', () => {
	it('renders an em dash for null/undefined/NaN', () => {
		expect(formatEurCompact(null)).toBe('—')
		expect(formatEurCompact(Number.NaN)).toBe('—')
	})

	it('produces a compact euro string for large values', () => {
		const out = formatEurCompact(1500)
		expect(out).toContain('€')
		// Compact notation collapses thousands (e.g. €1.5K / €1,5K).
		expect(out.length).toBeLessThan(formatEur(1500).length + 2)
	})
})
