// @vitest-environment node
/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 * SPDX-License-Identifier: EUPL-1.2
 *
 * PqContract's Facturering as configuration: the billing fields, the invoice
 * reference with a copy button and who sent it, the line items as a table,
 * and the fields that record when and by whom a contract went to invoicing.
 *
 * @spec openspec/changes/contract-billing-as-drawn/specs/contract-renewal-tracking/spec.md
 */

import { readFileSync } from 'node:fs'
import { resolve } from 'node:path'
import { describe, expect, it } from 'vitest'

const read = (...parts) => JSON.parse(readFileSync(resolve(__dirname, '../..', ...parts), 'utf8'))
const contracts = read('src', 'manifest.d', '96-contracts.json')
const fragment = read('lib', 'Settings', 'register.d', '99-zz-contract-invoiced-by.json')

function findPage(node) {
	if (Array.isArray(node)) {
		for (const item of node) {
			const hit = findPage(item)
			if (hit) return hit
		}
		return null
	}
	if (node && typeof node === 'object') {
		if (node.id === 'ContractDetail') return node
		for (const value of Object.values(node)) {
			const hit = findPage(value)
			if (hit) return hit
		}
	}
	return null
}

const config = findPage(contracts).config
const widget = (id) => config.widgets.find((w) => w.id === id)

describe('the contract page\'s billing', () => {
	it('draws the billing fields, the invoice reference and the lines as one Facturering card', () => {
		expect(widget('contract-billing').content.sections.map((s) => s.widgetId)).toEqual(['contract-billing-fields', 'contract-invoice', 'contract-lines'])
		expect(widget('contract-billing-fields').content.include).toEqual(['billingInterval', 'valuePerInterval', 'value', 'currency'])
	})

	it('shows the invoice reference with a copy button, and when and by whom it went', () => {
		const fields = widget('contract-invoice').content.fields
		expect(fields[0]).toEqual({ label: 'Invoice reference', path: 'invoiceReference', copy: true })
		expect(fields[1].template).toBe('On {invoicedAt:date-long} sent to invoicing by {invoicedBy:user}')
	})

	it('lists the line items as a table from the contract\'s own rows', () => {
		const lines = widget('contract-lines').content
		expect(lines.objectRows.path).toBe('lineItems')
		expect(lines.presentation).toBe('table')
		expect(lines.columns.map((column) => column.label)).toEqual(['Product', 'Description', 'Quantity', 'Unit value'])
	})

	it('places the billing card in the Overview tab after the terms', () => {
		const overview = widget('contract-panels').content.tabs[0].widgetIds
		expect(overview.slice(0, 2)).toEqual(['contract-terms', 'contract-billing'])
	})

	it('records when and by whom a contract went to invoicing', () => {
		const props = fragment.components.schemas.salesContract.properties
		expect(props.invoicedAt.format).toBe('date-time')
		expect(props.invoicedBy.format).toBe('user')
	})
})
