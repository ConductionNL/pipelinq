// @vitest-environment jsdom
// SPDX-License-Identifier: EUPL-1.2
// SPDX-FileCopyrightText: 2026 Conduction B.V.

/**
 * The product page shows what the product's variants differ in: each declared
 * variant attribute with the values it allows, beside the variant list
 * (specify-capability-rows task 29.1).
 *
 * @spec openspec/changes/specify-capability-rows/specs/product-catalog/spec.md#requirement-a-product-keeps-attributes-and-variants-with-their-own-code-and-price
 */
import { fillTemplate } from '@conduction/nextcloud-vue/dist/esm/utils/fillTemplate.js'
import { readFileSync } from 'node:fs'
import { resolve } from 'node:path'
import { describe, expect, it } from 'vitest'

const manifest = JSON.parse(readFileSync(resolve(__dirname, '../../src/manifest.json'), 'utf8'))
const page = manifest.pages.find((p) => p.id === 'ProductDetail')
const widgets = page.config.widgets
const card = widgets.find((w) => w.id === 'product-variant-attributes')
const overview = widgets.find((w) => w.id === 'product-panels').content.tabs.find((tab) => tab.label === 'Overview')

describe('Product page: variant attributes', () => {
	it('lists the product\'s declared attributes as a table', () => {
		expect(card.type).toBe('record-list')
		expect(card.title).toBe('Variant attributes')
		expect(card.content.presentation).toBe('table')
		expect(card.content.objectRows).toEqual({ path: 'variantAttributes' })
		expect(card.content.columns.map((column) => column.label)).toEqual(['Attribute', 'Values'])
	})

	it('writes each attribute\'s allowed values on one line', () => {
		const [name, values] = card.content.columns.map((column) => column.value)
		const size = { name: 'Size', values: ['S', 'M', 'L'] }
		expect(fillTemplate(name, size)).toBe('Size')
		expect(fillTemplate(values, size)).toBe('S · M · L')
		expect(fillTemplate(values, { name: 'Colour', values: [] })).toBe('')
	})

	it('says so when the product declares no attributes', () => {
		expect(card.content.emptyText).toBe('No variant attributes set. A variant may name any characteristic.')
	})

	it('sits on the Overview tab just before the variants', () => {
		const ids = overview.widgetIds
		expect(ids).toContain('product-variant-attributes')
		expect(ids.indexOf('product-variant-attributes')).toBe(ids.indexOf('product-variants') - 1)
	})

	it('has its empty text in Dutch', () => {
		const nl = JSON.parse(readFileSync(resolve(__dirname, '../../l10n/nl.json'), 'utf8')).translations
		expect(nl[card.content.emptyText]).toBe('Geen variantkenmerken vastgelegd. Een variant mag elk kenmerk noemen.')
		expect(nl[card.title]).toBe('Variantkenmerken')
	})
})
