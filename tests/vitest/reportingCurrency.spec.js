// SPDX-License-Identifier: EUPL-1.2
// Copyright (C) 2026 Conduction B.V.

/**
 * Money follows the reporting currency chosen in setup
 * (openspec/changes/review-finish). Expected strings are built with the same
 * Intl call, so the assertions hold on any ICU build.
 */

import { beforeEach, describe, expect, it, vi } from 'vitest'

let state = {}
vi.mock('@nextcloud/initial-state', () => ({
	loadState: (_app, _key, fallback) => state ?? fallback,
}))

const { currencyOr, reportingCurrency } = await import('../../src/services/reportingCurrency.js')
const { objectCurrency } = await import('../../src/services/cellFormatters.js')
const { formatEur, formatEurCompact } = await import('../../src/services/commercialFormat.js')
const { formatCurrency } = await import('../../src/services/localeUtils.js')

function intl (value, currency, digits) {
  return new Intl.NumberFormat(undefined, {
	style: 'currency',
	currency,
	minimumFractionDigits: digits,
	maximumFractionDigits: digits,
}).format(value)
}

describe('reportingCurrency', () => {
	beforeEach(() => {
		state = { currency: 'usd' }
	})

	it('reads the setup choice, upper-cased', () => {
		expect(reportingCurrency()).toBe('USD')
	})

	it('falls back to EUR when unset or malformed', () => {
		state = {}
		expect(reportingCurrency()).toBe('EUR')
		state = { currency: 'dollars' }
		expect(reportingCurrency()).toBe('EUR')
	})

	it('prefers an object currency over the reporting one', () => {
		expect(currencyOr('gbp')).toBe('GBP')
		expect(currencyOr('')).toBe('USD')
		expect(currencyOr(null)).toBe('USD')
	})
})

describe('formatters follow the reporting currency', () => {
	beforeEach(() => {
		state = { currency: 'USD' }
	})

	it('formatEur uses the reporting currency, not EUR', () => {
		expect(formatEur(1234)).toBe(intl(1234, 'USD', 0))
		expect(formatEur(1234)).not.toBe(intl(1234, 'EUR', 0))
	})

	it('formatEurCompact uses the reporting currency', () => {
		const expected = new Intl.NumberFormat(undefined, {
			style: 'currency', currency: 'USD', notation: 'compact', maximumFractionDigits: 1,
		}).format(1500000)
		expect(formatEurCompact(1500000)).toBe(expected)
	})

	it('formatCurrency prefixes the reporting currency code', () => {
		expect(formatCurrency(10)).toMatch(/^USD /)
		expect(formatCurrency(10, 'GBP')).toMatch(/^GBP /)
	})

	it('objectCurrency uses the row currency, else the reporting currency', () => {
		expect(objectCurrency(25, { currency: 'GBP' }, null, { decimals: 2 })).toBe(intl(25, 'GBP', 2))
		expect(objectCurrency(25, {}, null, {})).toBe(intl(25, 'USD', 2))
		expect(objectCurrency('', {}, null, {})).toBe('')
		expect(objectCurrency('n/a', {}, null, {})).toBe('n/a')
	})
})
