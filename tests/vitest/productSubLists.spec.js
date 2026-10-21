/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 * SPDX-License-Identifier: EUPL-1.2
 *
 * A product's price tiers, variants and modifier groups are edited in the
 * product form again, and shown as tables on the product page (PL-ABSTRACT
 * Q10). They were `visible: false`, which hid them from the form too.
 *
 * @spec openspec/changes/pl2-product-sub-lists/specs/pos-product-catalogue/spec.md#requirement-a-products-price-tiers-variants-and-modifier-groups-are-edited-in-its-form
 */
import { readFileSync } from 'node:fs'
import { resolve } from 'node:path'
import { describe, expect, it } from 'vitest'

const root = resolve(__dirname, '../..')
const manifest = JSON.parse(readFileSync(resolve(root, 'src/manifest.json'), 'utf8'))
const register = JSON.parse(readFileSync(resolve(root, 'lib/Settings/pipelinq_register.json'), 'utf8'))
const LISTS = ['priceTiers', 'variants', 'modifierGroups']

describe('product sub-lists', () => {
	it('are visible in the schema, so the form can show them', () => {
		const props = register.components.schemas.product.properties
		for (const key of LISTS) {
			expect(props[key].visible).toBeUndefined()
		}
	})

	it.each(['Products', 'ProductDetail'])('are edited as rows in the %s form', (id) => {
		const overrides = manifest.pages.find((p) => p.id === id).config.fieldOverrides
		for (const key of LISTS) {
			expect(overrides[key].widget).toBe('sub-objects')
		}
		expect(overrides.priceTiers.inline).toBe(true)
		expect(overrides.modifierGroups.itemOverrides.options.widget).toBe('sub-objects')
	})

	it('are shown as tables in the product page Overview', () => {
		const page = manifest.pages.find((p) => p.id === 'ProductDetail')
		const widget = (id) => page.config.widgets.find((w) => w.id === id)
		for (const [id, field] of [['product-tiers', 'priceTiers'], ['product-variants', 'variants'], ['product-modifiers', 'modifierGroups']]) {
			expect(widget(id)).toMatchObject({ type: 'field-list', content: { field, variant: 'table', readOnly: true } })
		}
		const overview = widget('product-panels').content.tabs[0].widgetIds
		expect(overview).toContain('product-modifiers')
	})
})
