/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 * SPDX-License-Identifier: EUPL-1.2
 *
 * The contract and product pages as the PqContracten, PqContract,
 * PqProducten and PqProduct boards draw them.
 *
 * @spec openspec/specs/product-catalog/spec.md
 */

import { readFileSync } from 'node:fs'
import { resolve } from 'node:path'
import { describe, expect, it } from 'vitest'

function read(file) {
	return JSON.parse(readFileSync(resolve(__dirname, '../../src', file), 'utf8'))
}
const base = read('manifest.json')
const contracts = read('manifest.d/96-contracts.json')
const page = (pages, id) => pages.find((p) => p.id === id)
const widget = (p, id) => p.config.widgets.find((w) => w.id === id)

describe('contract list', () => {
	const list = page(contracts.pages, 'Contracts')

	it('shows three KPI tiles above the list', () => {
		expect(list.config.statRow.map((tile) => tile.id)).toEqual([
			'contracts-mrr',
			'contracts-to-renew',
			'contracts-auto-renew',
		])
	})

	it('filters on status with counts and sorts ending soonest first', () => {
		expect(list.config.quickFilters.map((f) => f.label)).toEqual([
			'All',
			'To renew',
			'Active',
			'Terminated',
		])
		expect(list.config.sortKey).toBe('endDate')
		expect(list.config.sortOrder).toBe('asc')
	})

	it('names the client instead of its id', () => {
		const client = list.config.columns.find((c) => c.key === 'clientRef')
		expect(client.labelField).toBe('name')
	})
})

describe('contract page', () => {
	const detail = page(contracts.pages, 'ContractDetail')

	it('puts the KPI row above one tab strip, with client and owner beside it', () => {
		expect(detail.config.layout.map((item) => item.widgetId)).toEqual([
			'contract-kpi-recurring',
			'contract-kpi-days',
			'contract-kpi-invoiced',
			'contract-panels',
		])
		expect(detail.config.sideColumn).toEqual([
			'side-client',
			'side-owner',
			'side-history',
		])
		expect(widget(detail, 'contract-panels').content.tabs[0].widgetIds).toEqual([
			'contract-terms',
			'contract-billing',
			'contract-related',
			'contract-files',
		])
	})

	it('draws the billing card as the fields and a table of the lines, with the product names', () => {
		const billing = widget(detail, 'contract-billing')
		expect(billing.type).toBe('sections')
		expect(billing.content.sections.map((s) => s.widgetId)).toEqual(['contract-billing-fields', 'contract-invoice', 'contract-lines'])
		expect(widget(detail, 'contract-billing-fields').content.include).not.toContain('lineItems')
		const lines = widget(detail, 'contract-lines').content
		expect(lines.presentation).toBe('table')
		expect(lines.objectRows.refs).toEqual([{ field: 'productRef', register: 'pipelinq', schema: 'product', as: 'productRecord' }])
		// A line names its product by reference (the schema) or by name (older data).
		expect(lines.columns.map((c) => c.value)).toEqual([['{productRecord.name}', '{product}'], '{description}', '{quantity}', ['{unitValue|currency}', '{unitPrice|currency}']])
	})

	it('keeps sending to invoicing and the status transitions', () => {
		expect(detail.config.bodyWidgets[0].component).toBe(
			'ContractInvoicingSection',
		)
		expect(detail.config.lifecycleActions).toEqual({ field: 'status' })
	})

	it('offers the renewal lead as the next step of an expiring contract', () => {
		expect(detail.config.primaryActionByStage).toEqual({
			expiring: 'open-renewal-lead',
		})
		expect(detail.config.nextStep.stages.expiring.checklist).toHaveLength(2)
	})
})

describe('product status', () => {
	const fragment = JSON.parse(readFileSync(resolve(__dirname, '../../lib/Settings/register.d/93-product-status.json'), 'utf8'))
	const status = fragment.components.schemas.product.properties.status

	it('knows draft, active and discontinued, and still reads the old inactive', () => {
		expect(status.enum).toEqual(['active', 'draft', 'discontinued', 'inactive'])
		expect(status['x-enum-labels'].inactive).toBe(status['x-enum-labels'].discontinued)
	})
})

describe('product pages', () => {
	it('filters the list on type and status and counts the deals a product is on', () => {
		const list = page(base.pages, 'Products')
		expect(list.config.quickFilters.map((f) => f.label)).toEqual([
			'All',
			'Products',
			'Services',
			'Draft',
			'Discontinued',
		])
		const discontinued = list.config.quickFilters.find((f) => f.label === 'Discontinued')
		expect(discontinued.filter.status).toEqual(['discontinued', 'inactive'])
		const onDeals = list.config.columns.find((c) => c.key === 'onDeals')
		expect(onDeals.aggregate).toMatchObject({
			schema: 'leadProduct',
			op: 'count',
		})
	})

	it('draws the product page as KPI tiles, a tab strip and side cards', () => {
		const detail = page(base.pages, 'ProductDetail')
		expect(detail.config.layout.map((item) => item.widgetId)).toEqual([
			'product-kpi-deals',
			'product-kpi-value',
			'product-kpi-counter',
			'product-panels',
		])
		expect(detail.config.sideColumn).toEqual([
			'product-supply',
			'product-related',
			'side-history',
		])
	})

	it('puts the price tiers and the fee table side by side, as PqProduct draws them', () => {
		const detail = page(base.pages, 'ProductDetail')
		const overview = widget(detail, 'product-panels').content.tabs[0]
		expect(overview.layout).toEqual([
			{ widgetId: 'product-tiers', gridWidth: 6 },
			{ widgetId: 'product-tariffs', gridWidth: 6 },
		])
		const ids = overview.widgetIds
		expect(ids.indexOf('product-tariffs')).toBe(ids.indexOf('product-tiers') + 1)
	})

	it('draws the price tiers as a small table of quantity, unit price and label', () => {
		const tiers = widget(page(base.pages, 'ProductDetail'), 'product-tiers')
		expect(tiers.type).toBe('record-list')
		expect(tiers.content.presentation).toBe('table')
		expect(tiers.content.objectRows).toEqual({ path: 'priceTiers' })
		expect(tiers.content.columns.map((c) => c.value)).toEqual(['{minQuantity}', '{unitPrice|currency}', '{label}'])
	})

	it('draws the variants as a table with a status pill', () => {
		const variants = widget(page(base.pages, 'ProductDetail'), 'product-variants')
		expect(variants.content.objectRows).toEqual({ path: 'variants' })
		expect(variants.content.columns.find((c) => c.label === 'Status').tone.map).toEqual({ active: 'success' })
	})

	it('draws the price tiers and variants as data tables whose heads sort and filter, as PqProduct draws them', () => {
		const detail = page(base.pages, 'ProductDetail')
		for (const id of ['product-tiers', 'product-variants']) {
			const content = widget(detail, id).content
			expect(content.tableLook).toBe('data')
			expect(content.sortable).toBe(true)
			expect(content.filterable).toBe(true)
		}
	})

	it('shows each variant\'s characteristics after its name', () => {
		const variants = widget(page(base.pages, 'ProductDetail'), 'product-variants')
		expect(variants.content.columns.map((c) => c.label)).toEqual(['SKU', 'Name', 'Characteristics', 'Price', 'Barcode', 'Status'])
		expect(variants.content.columns[2].value).toBe('{attributes|values}')
	})

	it('lays the publication out in three columns: status, publication date, languages, then audience', () => {
		const publication = widget(page(base.pages, 'ProductDetail'), 'product-publication')
		expect(publication.content.columns).toBe(3)
		expect(publication.content.include).toEqual(['status', 'publishedAt', 'languages', 'audience'])
	})

	it('draws a service in the teal pill, a product in the blue one', () => {
		const detail = page(base.pages, 'ProductDetail')
		expect(detail.config.typePill.colorMap).toEqual({ product: 'info', service: 'teal' })
	})
})
