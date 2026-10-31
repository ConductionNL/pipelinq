// @vitest-environment jsdom
/**
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * The fee table and the supply card are titled cards on the product page
 * (pl2 PqProduct: both rendered bare, without a card or a title).
 *
 * @spec openspec/specs/product-catalog/spec.md
 */
import { mount } from '@vue/test-utils'
import { readFileSync } from 'node:fs'
import { resolve } from 'node:path'
import { beforeAll, describe, expect, it } from 'vitest'


let cards

beforeAll(async () => {
	cards = await import('../../src/components/products/productCards.js')
}, 240000)

describe('product cards', () => {
	it('draws the fee table in a card titled from the widget content', () => {
		const { ProductTariffsCard } = cards
		const wrapper = mount(ProductTariffsCard, {
			props: { objectId: 'p-1', objectData: { id: 'p-1', tariffs: [{ label: 'Pas', price: 15 }] }, content: { title: 'Tariff table (fees)' } },
		})
		expect(wrapper.text()).toContain('Tariff table (fees)')
		expect(wrapper.find('[data-testid="product-tariffs"]').exists()).toBe(true)
	})

	it('draws the supply list in a card titled from the widget content', () => {
		const { ProductSupplyCard } = cards
		const wrapper = mount(ProductSupplyCard, {
			props: { objectId: 'p-1', objectData: { id: 'p-1', manufacturer: 'Kaartdrukkerij' }, content: { title: 'Delivery' } },
		})
		expect(wrapper.text()).toContain('Delivery')
		expect(wrapper.find('[data-testid="product-supply"]').exists()).toBe(true)
	})

	it('labels the product type', () => {
		const fragment = JSON.parse(readFileSync(resolve(__dirname, '../../lib/Settings/register.d/99-zz-product-type-labels.json'), 'utf8'))
		const nl = JSON.parse(readFileSync(resolve(__dirname, '../../l10n/nl.json'), 'utf8')).translations
		expect(fragment.components.schemas.product.properties.type['x-enum-labels']).toEqual({ product: 'Product', service: 'Service' })
		expect(nl.Service).toBe('Dienst')
	})
})
