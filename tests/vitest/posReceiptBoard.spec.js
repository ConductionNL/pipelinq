/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Board PqKassabon as configuration: the reference names the receipt, the
 * meta line carries the amount, the client and the moment, the status is a
 * pill, the transaction, its lines and the payment sit in an Overview tab
 * beside History, and the totals, the receipt, the related records and a
 * short history are the side column.
 *
 * @spec openspec/specs/declarative-view-system/spec.md
 * @spec openspec/specs/pos-receipt-transaction-card/spec.md#requirement-the-receipts-transaction-card-reads-as-the-board
 */

import fs from 'fs'
import path from 'path'
import { describe, expect, it } from 'vitest'

const ROOT = path.resolve(__dirname, '../..')
const manifest = JSON.parse(fs.readFileSync(path.join(ROOT, 'src', 'manifest.json'), 'utf8'))
const nl = JSON.parse(fs.readFileSync(path.join(ROOT, 'l10n', 'nl.json'), 'utf8')).translations
const config = manifest.pages.find((p) => p.id === 'PosTransactionDetail').config
const widget = (id) => config.widgets.find((w) => w.id === id)

describe('POS receipt page', () => {
	it('carries the board header: reference, status pill, breadcrumb and meta line', () => {
		expect(config.titleField).toBe('reference')
		expect(config.follow).toBe(false)
		expect(config.showEditAction).toBe(false)
		expect(nl[config.breadcrumb.label]).toBe('Transacties')
		expect(config.breadcrumb.route).toBe('PosTransactions')
		expect(nl[config.statusPill.labels.settled]).toBe('Afgerekend')
		expect(config.statusPill.colorMap.settled).toBe('success')
		expect(config.headerMeta).toContain('{total|currency')
		expect(config.headerMeta).toContain('{client.name}')
		expect(config.extend).toContain('client')
	})

	it('keeps the receipt and refund buttons in the header', () => {
		expect(config.quickActions).toEqual(['tx-print-receipt', 'tx-email-receipt', 'tx-register-refund'])
	})

	it('puts the transaction, lines and payment in an Overview tab beside History', () => {
		const tabs = widget('pos-tx-panels').content.tabs
		expect(tabs.map((tab) => nl[tab.label])).toEqual(['Overzicht', 'Geschiedenis'])
		expect(tabs[0].widgetIds).toEqual(['pos-tx-data', 'pos-tx-lines', 'pos-tx-provider', 'pos-tx-payment'])
		expect(widget(tabs[1].widgetId).type).toBe('audit-trail')
		expect(config.layout.map((cell) => cell.widgetId)).toEqual(['pos-tx-panels'])
	})

	it('draws the side column as the board: totals, receipt, related, history', () => {
		expect(config.sideColumn).toEqual(['pos-tx-totals', 'pos-tx-receipt', 'pos-tx-related', 'pos-tx-side-history'])
		expect(widget('pos-tx-totals').content.layout).toBe('summary')
		expect(widget('pos-tx-side-history').type).toBe('audit-trail')
		expect(nl[widget('pos-tx-receipt').title]).toBe('Bon')
	})

	it('labels the transaction fields as the board does, three to a row', () => {
		const data = widget('pos-tx-data').content
		expect(data.columns).toBe(3)
		expect(data.include).toEqual(['reference', 'status', 'cashier', 'terminalId', 'priceMode', 'client', 'confirmedAt', 'settledAt'])
		expect(nl[data.overrides.reference.label]).toBe('Kenmerk')
		expect(nl[data.overrides.confirmedAt.label]).toBe('Aangemaakt')
		expect(nl[data.overrides.settledAt.label]).toBe('Afgerekend')
	})

	it('writes the lines as money and percentages under the board headings', () => {
		const columns = widget('pos-tx-lines').content.columns
		expect(columns.map((c) => nl[c.label])).toEqual(['Omschrijving', 'Aantal', 'Stukprijs', 'Korting %', 'Btw %', 'Regeltotaal'])
		expect(columns[2].format.style).toBe('currency')
		expect(columns[3].format.style).toBe('percent')
		expect(nl[widget('pos-tx-lines').title]).toBe('Regels')
	})

	it('shows the payment as one card with its status, amount and the reverse button', () => {
		const provider = widget('pos-tx-provider')
		expect(nl[provider.title]).toBe('Betaling')
		const paths = provider.content.fields.map((f) => f.path)
		expect(paths).toEqual(['paymentStatus', 'paymentProvider', 'paymentMethod', 'total', 'paymentSessionId'])
		const reverse = provider.content.actions.find((a) => a.id === 'payment-reverse')
		expect(reverse.icon).toBe('Undo')
	})

	it('shows the tender list while tenders can be added, or when no provider took the payment', () => {
		const tenders = widget('pos-tx-payment')
		expect(tenders.visibleWhen.any).toEqual([
			{ field: 'status', op: 'in', value: ['draft', 'parked', 'confirmed'] },
			{ field: 'paymentProvider', op: 'empty' },
		])
	})

	it('orders the transaction fields as the board reads them, row by row', () => {
		const { include, overrides } = widget('pos-tx-data').content
		expect(include.map((key) => overrides[key].order)).toEqual([0, 1, 2, 3, 4, 5, 6, 7])
	})

	it('shows the created and settled moments, which the schema hides elsewhere', () => {
		const { overrides } = widget('pos-tx-data').content
		for (const key of ['confirmedAt', 'settledAt']) {
			expect(overrides[key].hidden).toBe(false)
			expect(overrides[key].editable).toBe(false)
		}
	})

	it('draws the receipt card buttons as the board\'s light-blue pills', () => {
		const actions = widget('pos-tx-receipt').content.actions
		expect(actions.map((a) => [a.id, a.variant])).toEqual([['receipt-print', 'tonal'], ['receipt-email', 'tonal']])
	})
})

