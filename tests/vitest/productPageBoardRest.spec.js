/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 * SPDX-License-Identifier: EUPL-1.2
 *
 * The rest of the product page as the PqProduct board draws it: the side
 * card is "Levering" (Supply), the tiers and variants are data tables whose
 * heads sort and filter, the product card lists the board's ten fields, the
 * publication sits in three columns, Related names the category as a link,
 * and history drops read entries.
 *
 * @spec openspec/changes/product-page-board-rest/specs/product-catalog/spec.md#requirement-the-product-page-follows-the-pqproduct-board
 */

import { readFileSync } from 'node:fs'
import { resolve } from 'node:path'
import { describe, expect, it } from 'vitest'

const manifest = JSON.parse(readFileSync(resolve(__dirname, '../../src/manifest.json'), 'utf8'))
const config = manifest.pages.find((p) => p.id === 'ProductDetail').config
const widget = (id) => config.widgets.find((w) => w.id === id)

describe('PqProduct: the rest of the product page', () => {
	it('titles the supply card Supply (Levering), not Delivery (Verzending)', () => {
		expect(widget('product-supply').title).toBe('Supply')
		expect(widget('product-supply').content.title).toBe('Supply')
	})

	it('draws tiers and variants as data tables with sorting and filtering heads', () => {
		for (const id of ['product-tiers', 'product-variants']) {
			expect(widget(id).content).toMatchObject({ presentation: 'table', tableLook: 'data', sortable: true, filterable: true })
		}
	})

	it('lists the board\'s ten product fields, without the description', () => {
		expect(widget('product-data').content.include).toEqual([
			'name', 'sku', 'barcode', 'unitPrice', 'cost', 'taxRate', 'vatClass', 'category', 'type', 'unit',
		])
	})

	it('sets the publication in three columns', () => {
		expect(widget('product-publication').content.columns).toBe(3)
	})

	it('shows Related as links naming the category, without files or an add footer', () => {
		const content = widget('product-related').content
		expect(content.layout).toBe('links')
		expect(content.showFiles).toBe(false)
		expect(content.showAddFooter).toBe(false)
		expect(content.referenceSections[0]).toMatchObject({ key: 'category', field: 'category', schema: 'productCategory' })
	})

	it('shows history without read entries', () => {
		for (const id of ['side-history', 'product-history']) {
			expect(widget(id).type).toBe('timeline')
			expect(widget(id).content.excludeActions).toEqual(['read'])
		}
		expect(widget('side-history').content.limit).toBe(4)
	})
})
