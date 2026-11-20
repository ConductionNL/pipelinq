// @vitest-environment jsdom
/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 * SPDX-License-Identifier: EUPL-1.2
 *
 * One editable sale line: what it reads as, and what it emits when a product
 * or tax rate is picked.
 */

import { shallowMount } from '@vue/test-utils'
import { describe, expect, it, vi } from 'vitest'

vi.mock('../../src/services/localeUtils.js', () => ({ getUserLocale: () => 'en-US' }))

globalThis.t = (_app, text) => text

const { default: PosLineItemRow } =
	await import('../../src/components/pos/PosLineItemRow.vue')

const PRODUCTS = [
	{ id: 'p1', name: 'Koffie', sku: 'KOF', unitPrice: 2.5, taxRate: 9 },
	{ '@self': { id: 'p2' }, id: 'p2-local', name: 'Thee', unitPrice: 2 },
]

/**
 * Mount a row.
 *
 * @param {object} line The line.
 * @param {object} props More props.
 * @return {object} The wrapper.
 */
function row(line, props = {}) {
	return shallowMount(PosLineItemRow, {
		props: { line, products: PRODUCTS, ...props },
		global: { mocks: { t: (_app, text) => text } },
	})
}

describe('PosLineItemRow', () => {
	it('names the line after its product, by id or by @self id', () => {
		expect(row({ product: 'p1' }).vm.productName).toBe('Koffie')
		expect(row({ product: 'p2' }).vm.productName).toBe('Thee')
		expect(row({ description: 'Losse verkoop' }).vm.productName).toBe('Losse verkoop')
		expect(row({}).vm.productName).toBe('Item')
	})

	it('shows the description as a note only when it differs from the name', () => {
		expect(row({ product: 'p1', description: 'Koffie' }).vm.lineNote).toBe('')
		expect(row({ product: 'p1', description: ' extra shot ' }).vm.lineNote).toBe('extra shot')
		expect(row({ description: 'Losse verkoop' }).vm.lineNote).toBe('')
	})

	it('reads a missing or invalid total as zero and formats numbers in the user locale', () => {
		expect(row({ lineTotal: '12.5' }).vm.readTotal).toBe(12.5)
		expect(row({ lineTotal: 'x' }).vm.readTotal).toBe(0)
		expect(row({}).vm.formatNumber(1234.56789)).toBe('1,234.568')
		expect(row({}).vm.formatNumber('n/a')).toBe('0')
	})

	it('starts editing from the line, with defaults for what is missing', () => {
		expect(row({}).vm.local).toEqual({ description: '', quantity: 1, unitPrice: 0, discount: 0, taxRate: 21, product: null })
		expect(row({ quantity: 0, unitPrice: 3, taxRate: 0, product: 'p1' }).vm.local).toMatchObject({ quantity: 0, unitPrice: 3, taxRate: 0, product: 'p1' })
	})

	it('labels products with their sku and picks the line\'s product and tax rate', () => {
		const wrapper = row({ product: 'p1', taxRate: 9 })
		expect(wrapper.vm.productOptions.map((o) => o.label)).toEqual(['Koffie (KOF)', 'Thee'])
		expect(wrapper.vm.selectedProduct.id).toBe('p1')
		expect(wrapper.vm.selectedTaxRate.label).toBe('9%')
		expect(row({ taxRate: 6 }).vm.selectedTaxRate.label).toBe('21%')
	})

	it('picking a product copies its name, price and tax rate and emits the line', () => {
		const wrapper = row({ id: 'l1', quantity: 2, unitPrice: 1 })
		wrapper.vm.onProductSelect(wrapper.vm.productOptions[0])
		expect(wrapper.emitted('update:line')[0][0]).toEqual({
			id: 'l1', description: 'Koffie', quantity: 2, unitPrice: 2.5, discount: 0, taxRate: 9, product: 'p1',
		})

		wrapper.vm.onProductSelect(wrapper.vm.productOptions[1])
		expect(wrapper.emitted('update:line')[1][0]).toMatchObject({ product: 'p2-local', unitPrice: 2, taxRate: 21 })

		wrapper.vm.onProductSelect(null)
		expect(wrapper.emitted('update:line')[2][0]).toMatchObject({ product: null, description: 'Thee' })
	})

	it('picking a tax rate emits it, clearing it falls back to 21%', () => {
		const wrapper = row({ id: 'l1' })
		wrapper.vm.onTaxRateSelect({ id: 0, label: '0%' })
		expect(wrapper.emitted('update:line')[0][0].taxRate).toBe(0)
		wrapper.vm.onTaxRateSelect(null)
		expect(wrapper.emitted('update:line')[1][0].taxRate).toBe(21)
	})

	it('resets the edit copy from the line when editing starts', async () => {
		const wrapper = row({ description: 'A' })
		wrapper.vm.local.description = 'changed'
		await wrapper.setProps({ line: { description: 'B' }, editing: true })
		expect(wrapper.vm.local.description).toBe('B')
	})
})
