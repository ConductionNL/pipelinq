/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 * SPDX-License-Identifier: EUPL-1.2
 *
 * The product page as the PqProduct board draws it: the fee table shows the
 * tariffs in force and names the next date a new set goes live, the
 * publication card reads its languages and audience, and a light item's
 * weight reads in grams.
 *
 * @spec openspec/specs/product-catalog/spec.md
 */

import { readFileSync } from 'node:fs'
import { resolve } from 'node:path'
import { beforeAll, describe, expect, it } from 'vitest'

let tariffs
let formatters

beforeAll(async () => {
	tariffs = await import('../../src/services/productTariffs.js')
	formatters = await import('../../src/services/cellFormatters.js')
})

const SET = [
	{ label: 'First pass', price: 0, validFrom: '2026-01-01' },
	{ label: 'Replacement pass', price: 15, validFrom: '2026-01-01' },
	{ label: 'Replacement pass', price: 16, validFrom: '2027-01-01' },
	{ label: 'Business pass', price: 48 },
	{ label: 'Night pass', price: 9, validFrom: '2027-03-01' },
]

describe('product tariffs', () => {
	it('shows the tariff in force for each label, in the order given', () => {
		const shown = tariffs.currentTariffs(SET, '2026-10-07')
		expect(shown.map((r) => [r.label, r.price])).toEqual([
			['First pass', 0],
			['Replacement pass', 15],
			['Business pass', 48],
		])
	})

	it('switches to the new tariff on its valid-from day', () => {
		const shown = tariffs.currentTariffs(SET, '2027-01-01')
		expect(shown.find((r) => r.label === 'Replacement pass').price).toBe(16)
	})

	it('names the first day a scheduled tariff goes live', () => {
		expect(tariffs.nextTariffDate(SET, '2026-10-07')).toBe('2027-01-01')
		expect(tariffs.nextTariffDate(SET, '2027-02-01')).toBe('2027-03-01')
		expect(tariffs.nextTariffDate(SET, '2027-03-01')).toBe(null)
		expect(tariffs.nextTariffDate(undefined, '2027-03-01')).toBe(null)
	})

	it('writes a weight under a kilogram in grams', () => {
		expect(tariffs.productWeight({ weightGrams: 5 })).toBe('5 g')
		expect(tariffs.productWeight({ weightGrams: 2500, weight: 9 })).toBe('2.5 kg')
		expect(tariffs.productWeight({ weight: 0.25 })).toBe('250 g')
		expect(tariffs.productWeight({ weight: 1.2 })).toBe('1.2 kg')
		expect(tariffs.productWeight({})).toBe('')
	})
})

describe('publication formatters', () => {
	it('writes language codes in capitals', () => {
		expect(formatters.languageCodes(['nl', 'en'])).toBe('NL, EN')
		expect(formatters.languageCodes([])).toBe('')
	})

	it('writes a list of enum labels as one phrase', () => {
		const property = { 'x-enum-labels': { resident: 'Resident', business: 'Business' } }
		expect(formatters.labelList(['resident', 'business'], {}, property)).toBe('Resident, business')
		expect(formatters.labelList(['other'], {}, property)).toBe('other')
	})

	it('registers both for the manifest', () => {
		expect(formatters.CELL_FORMATTERS.languageCodes).toBe(formatters.languageCodes)
		expect(formatters.CELL_FORMATTERS.labelList).toBe(formatters.labelList)
		expect(formatters.CELL_FORMATTERS.longDate).toBe(formatters.longDate)
	})
})

describe('product page', () => {
	const manifest = JSON.parse(readFileSync(resolve(__dirname, '../../src/manifest.json'), 'utf8'))
	const page = manifest.pages.find((p) => p.id === 'ProductDetail')
	const widget = (id) => page.config.widgets.find((w) => w.id === id)

	it('draws the fee table and the publication card on the overview', () => {
		expect(widget('product-tariffs').type).toBe('ProductTariffsWidget')
		expect(widget('product-publication').content.include).toEqual(['status', 'publishedAt', 'languages', 'audience'])
		const overview = widget('product-panels').content.tabs[0].widgetIds
		expect(overview).toEqual([
			'product-data',
			'product-tiers',
			'product-tariffs',
			'product-variants',
			'product-publication',
			'product-lines',
			'product-files',
		])
	})
})
