// @vitest-environment jsdom
/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 * SPDX-License-Identifier: EUPL-1.2
 *
 * A sale's lines read as the board PqKassa draws them (product with its
 * description under it, quantity, unit price, discount, VAT and line total)
 * and keep their editor: the pencil opens the inputs, the check closes them.
 *
 * @spec openspec/specs/pos-transactions/spec.md#requirement-the-sale-lines-read-as-a-table-and-stay-editable
 */

import { mount } from '@vue/test-utils'
import { describe, expect, it, vi } from 'vitest'
import { h } from 'vue'

/**
 * A stub that renders its slots and keeps its props readable.
 *
 * @param {string} name The component name.
 * @param {string} [tag] The element it renders.
 * @return {object} The stub.
 */
function passthrough(name, tag = 'div') {
	return {
		name,
		inheritAttrs: false,
		props: ['label', 'inputLabel', 'modelValue', 'options', 'variant', 'ariaLabel'],
		emits: ['click', 'update:modelValue'],
		render() {
			return h(tag, { 'data-stub': name, 'data-testid': this.$attrs['data-testid'], 'aria-label': this.ariaLabel, onClick: () => this.$emit('click') }, Object.values(this.$slots).map((fn) => fn?.()))
		},
	}
}

vi.mock('@nextcloud/vue', () => ({
	NcButton: passthrough('NcButton', 'button'),
	NcInputField: passthrough('NcInputField'),
	NcSelect: passthrough('NcSelect'),
	NcTextField: passthrough('NcTextField'),
}))
vi.mock('@nextcloud/l10n', () => ({ getLocale: () => 'nl', getLanguage: () => 'nl', translate: (app, text) => text }))

globalThis.t = (app, text, vars = {}) => String(text).replace(/\{(\w+)\}/g, (whole, key) => vars[key] ?? whole)

const { default: PosLineItemRow } = await import('../../src/components/pos/PosLineItemRow.vue')

const products = [{ id: 'p-permit', name: 'Parkeervergunning bewoners, jaar', unitPrice: 75, taxRate: 0 }]
const line = { _key: 1, product: 'p-permit', description: 'Zone Centrum, kenteken 12-ABC-3', quantity: 1, unitPrice: 75, discount: 0, taxRate: 0 }

/**
 * Mount one line in a table body.
 *
 * @param {object} props The row's props.
 * @return {object} The wrapper.
 */
function mountRow(props) {
	return mount({ render: () => h('table', [h('tbody', [h(PosLineItemRow, props)])]) }, { global: { mocks: { t: globalThis.t } } })
}

describe('a sale line (PqKassa Regels)', () => {
	it('reads as a table row: product name over its description, then the amounts', () => {
		const wrapper = mountRow({ line, products })
		const cells = wrapper.findAll('td').map((cell) => cell.text().replace(/\s+/g, ' ').trim())
		expect(wrapper.find('[data-testid="pos-line-read"]').exists()).toBe(true)
		expect(wrapper.find('.pos-line-row__name').text()).toBe('Parkeervergunning bewoners, jaar')
		expect(wrapper.find('.pos-line-row__note').text()).toBe('Zone Centrum, kenteken 12-ABC-3')
		expect(cells.slice(1, 5)).toEqual(['1', '€ 75,00', '0%', '0%'])
		expect(wrapper.findAllComponents({ name: 'NcInputField' })).toHaveLength(0)
	})

	it('draws no description line when it only repeats the product name', () => {
		const wrapper = mountRow({ line: { ...line, description: 'Parkeervergunning bewoners, jaar' }, products })
		expect(wrapper.find('.pos-line-row__note').exists()).toBe(false)
	})

	it('keeps the editor: the pencil asks to edit, an editing row has the inputs', async () => {
		const read = mountRow({ line, products })
		const row = read.findComponent(PosLineItemRow)
		await read.find('[data-testid="pos-line-edit"]').trigger('click')
		expect(row.emitted('edit')).toHaveLength(1)

		const editing = mountRow({ line, products, editing: true })
		expect(editing.find('[data-testid="pos-line-edit-row"]').exists()).toBe(true)
		expect(editing.findAllComponents({ name: 'NcInputField' }).map((field) => field.props('label'))).toEqual(['Quantity', 'Unit price', 'Discount %'])
		expect(editing.findAllComponents({ name: 'NcSelect' }).map((field) => field.props('inputLabel'))).toEqual(['Product', 'VAT rate'])
		await editing.find('[data-testid="pos-line-done"]').trigger('click')
		expect(editing.findComponent(PosLineItemRow).emitted('done')).toHaveLength(1)
	})
})
