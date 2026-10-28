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
			'contract-invoice',
			'contract-lines',
			'contract-related',
			'contract-files',
		])
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
})
