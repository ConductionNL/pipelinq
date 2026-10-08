// SPDX-License-Identifier: EUPL-1.2
// Copyright (C) 2026 Conduction B.V.

/**
 * A deal can carry its currency and the rate table has a screen (pipelinq#2040).
 *
 * The forecast converted `deal.currency` at a rate, but no form offered a
 * currency and the rates could only be set with occ, so a USD deal counted as
 * EUR. These checks pin the helpers the lead form and Forecast settings use,
 * and that both screens actually use them.
 */

import { readFileSync } from 'node:fs'
import { resolve } from 'node:path'
import { describe, expect, it } from 'vitest'
import {
	isCurrencyCode,
	normaliseCurrency,
	ratesFromRows,
	rowsFromRates,
} from '../../src/services/leadCurrency.js'

const root = resolve(__dirname, '../..')
const read = (file) => readFileSync(resolve(root, file), 'utf8')

describe('lead currency helpers', () => {
	it('normalises and checks a currency code', () => {
		expect(normaliseCurrency(' usd ')).toBe('USD')
		expect(isCurrencyCode('usd')).toBe(true)
		expect(isCurrencyCode('DOLLAR')).toBe(false)
		expect(isCurrencyCode('')).toBe(false)
	})

	it('round-trips the rate table through editable rows', () => {
		const rows = rowsFromRates({ USD: 0.9, GBP: 1.18 })
		expect(rows).toEqual([
			{ currency: 'GBP', rate: '1.18' },
			{ currency: 'USD', rate: '0.9' },
		])
		expect(ratesFromRows([...rows, { currency: '', rate: '' }])).toEqual({
			rates: { GBP: 1.18, USD: 0.9 },
			error: '',
		})
	})

	it('refuses a bad code or a rate that is not above zero', () => {
		expect(ratesFromRows([{ currency: 'DOLLAR', rate: '1' }]).error).toBe('code')
		expect(ratesFromRows([{ currency: 'USD', rate: '0' }]).error).toBe('rate')
	})
})

describe('the screens use them', () => {
	it('the lead form edits the currency next to the value', () => {
		const form = read('src/views/leads/LeadForm.vue')
		expect(form).toContain(':modelValue="form.currency"')
		expect(form).toMatch(/currency: this\.lead\.currency/)
	})

	it('Forecast settings edits and saves the rate table', () => {
		const settings = read('src/components/admin/ForecastSettings.vue')
		expect(settings).toContain('data-testid="forecast-exchange-rates"')
		expect(settings).toContain('exchange_rates: rates')
	})

	it('the leads list shows the currency next to the value', () => {
		const manifest = JSON.parse(read('src/manifest.json'))
		const columns = manifest.pages.find((p) => p.id === 'Leads').config.columns
		expect(columns.indexOf('currency')).toBe(columns.indexOf('value') + 1)
	})
})
