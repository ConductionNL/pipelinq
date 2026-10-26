// SPDX-License-Identifier: EUPL-1.2
// Copyright (C) 2026 Conduction B.V.

/**
 * A deal can carry its currency and the rate table has a screen (pipelinq#2040).
 *
 * The forecast converted `deal.currency` at a rate, but no form offered a
 * currency and the rates could only be set with occ, so a USD deal counted as
 * EUR. These checks pin the helpers the lead form uses, and what both screens
 * offer.
 */

import { readFileSync } from 'node:fs'
import { resolve } from 'node:path'
import { describe, expect, it } from 'vitest'
import { isCurrencyCode, normaliseCurrency } from '../../src/services/leadCurrency.js'
import sections from '../../src/views/settings/adminSections.json'

const root = resolve(__dirname, '../..')
const read = (file) => readFileSync(resolve(root, file), 'utf8')

describe('lead currency helpers', () => {
	it('normalises and checks a currency code', () => {
		expect(normaliseCurrency(' usd ')).toBe('USD')
		expect(isCurrencyCode('usd')).toBe(true)
		expect(isCurrencyCode('DOLLAR')).toBe(false)
		expect(isCurrencyCode('')).toBe(false)
	})
})

describe('the screens use them', () => {
	it('the lead form edits the currency next to the value', () => {
		const form = read('src/views/leads/LeadForm.vue')
		expect(form).toContain(':modelValue="form.currency"')
		expect(form).toMatch(/currency: this\.lead\.currency/)
	})

	it('Forecast settings edits the rate table as currency-to-rate rows, refusing a bad code or a rate not above zero', () => {
		const rates = sections.forecast.fields.find((field) => field.key === 'exchange_rates')
		expect(rates).toMatchObject({ type: 'pairs', valueType: 'number', valueAbove: 0, keyMaxLength: 3 })
		const code = new RegExp(rates.pattern)
		expect(code.test('USD')).toBe(true)
		expect(code.test('DOLLAR')).toBe(false)
		expect(sections.forecast.url).toBe('/apps/pipelinq/api/settings/forecast')
	})

	it('the leads list shows the currency next to the value', () => {
		const manifest = JSON.parse(read('src/manifest.json'))
		const columns = manifest.pages.find((p) => p.id === 'Leads').config.columns
		expect(columns.indexOf('currency')).toBe(columns.indexOf('value') + 1)
	})
})
