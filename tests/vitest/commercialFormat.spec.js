// SPDX-License-Identifier: EUPL-1.2
// Copyright (C) 2026 Conduction B.V.

/**
 * Unit tests for src/services/commercialFormat.js — the euro formatting
 * helpers shared by the commercial dashboard widgets
 * (openspec/changes/commercial-dashboard).
 *
 * Assertions are deliberately locale- and ICU-agnostic: they pin the
 * helpers' own logic (em-dash for empty values, numeric passthrough)
 * rather than the exact currency glyph / grouping separator / compact
 * notation, which vary with the runtime's ICU build (the CI node image
 * may ship small-icu).
 */

import { describe, it, expect } from 'vitest'
import { formatEur, formatEurCompact } from '../../src/services/commercialFormat.js'

describe('formatEur', () => {
	it('renders an em dash for null/undefined/NaN/non-numeric', () => {
		expect(formatEur(null)).toBe('—')
		expect(formatEur(undefined)).toBe('—')
		expect(formatEur(Number.NaN)).toBe('—')
		expect(formatEur('not a number')).toBe('—')
	})

	it('renders the amount digits for a real number (not an em dash)', () => {
		const out = formatEur(1234)
		expect(out).not.toBe('—')
		// Digits present, with an optional grouping separator.
		expect(out).toMatch(/1.?234/)
	})

	it('keeps two decimals when asked', () => {
		expect(formatEur(1234.5, 2)).toMatch(/50/)
	})

	it('accepts numeric strings', () => {
		const out = formatEur('1000')
		expect(out).not.toBe('—')
		expect(out).toMatch(/1.?000/)
	})
})

describe('formatEurCompact', () => {
	it('renders an em dash for null/NaN', () => {
		expect(formatEurCompact(null)).toBe('—')
		expect(formatEurCompact(undefined)).toBe('—')
		expect(formatEurCompact(Number.NaN)).toBe('—')
	})

	it('returns a non-empty string carrying the magnitude for large values', () => {
		const out = formatEurCompact(1500)
		expect(out).not.toBe('—')
		expect(typeof out).toBe('string')
		expect(out.length).toBeGreaterThan(0)
		// Some digit from the value survives regardless of compact/long form.
		expect(out).toMatch(/\d/)
	})
})
