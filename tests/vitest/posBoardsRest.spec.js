/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 * SPDX-License-Identifier: EUPL-1.2
 *
 * The rest of boards PqKassabon, PqKassadienst and PqKassadiensten as
 * configuration over the summary endpoints: the receipt card's print, mail
 * and layout rows, related records as plain links with Show all, the
 * reversal as an outlined red button, the cash difference as figures with a
 * conditional band and a rejection reason, the margin and the drops taken
 * out of the drawer, and the shifts list's drops, difference and outcome.
 *
 * @spec openspec/changes/pos-shift-summary/specs/pos-cash-management/spec.md#requirement-a-shift-summary-reads-its-drops-and-difference
 */

import fs from 'fs'
import path from 'path'
import { describe, expect, it } from 'vitest'

const ROOT = path.resolve(__dirname, '../..')
const read = (file) => JSON.parse(fs.readFileSync(path.join(ROOT, file), 'utf8'))
const nl = read('l10n/nl.json').translations
const pages = read('src/manifest.json').pages
const page = (id) => pages.find((p) => p.id === id).config
const widget = (config, id) => config.widgets.find((w) => w.id === id)
const routes = fs.readFileSync(path.join(ROOT, 'appinfo/routes.php'), 'utf8')

describe('receipt page', () => {
	const config = page('PosTransactionDetail')

	it('reads the receipt card from the receipt log endpoint', () => {
		const receipt = widget(config, 'pos-tx-receipt').content
		expect(receipt.endpointSource).toEqual({ url: '/apps/pipelinq/api/pos-transactions/@objectId/receipt/log', responsePath: 'log' })
		expect(receipt.fields.map((f) => nl[f.label])).toEqual(['Geprint', 'Gemaild', 'Opmaak', 'Opmaak'])
		expect(nl[receipt.fields[1].emptyValue]).toBe('Niet verstuurd')
		expect(routes).toContain("'url' => '/api/pos-transactions/{id}/receipt/log'")
	})

	it('lists related records as plain links, with Show all for the rest', () => {
		const related = widget(config, 'pos-tx-related').content
		expect(related.layout).toBe('links')
		expect(related.referenceSections[0]).toMatchObject({ field: 'shift', schema: 'cashShift', labelTemplate: 'Shift {floatAt|date}, {drawer}' })
		expect(related.extraSources.map((s) => s.key)).toEqual(['refunds', 'audit'])
		expect(related.showFiles).toBeUndefined()
	})

	it('puts the shift first in Gerelateerd', () => {
		expect(widget(config, 'pos-tx-related').content.sectionOrder).toEqual(['shift', 'refunds', 'audit'])
	})

	it('draws the status in the transaction card as a pill', () => {
		const status = widget(config, 'pos-tx-data').content.overrides.status
		expect(status.pill.variantMap).toEqual({ draft: 'default', parked: 'warning', confirmed: 'info', settled: 'success', refunded: 'default' })
	})

	it('draws the reversal as an outlined red button', () => {
		const reverse = widget(config, 'pos-tx-provider').content.actions.find((a) => a.id === 'payment-reverse')
		expect(reverse.variant).toBe('error-outline')
	})
})

describe('cash shift page', () => {
	const config = page('CashShiftDetail')
	const summary = { url: '/apps/pipelinq/api/pos-shifts/@objectId/summary' }

	it('shows the margin in the shift card', () => {
		const data = widget(config, 'shift-data')
		expect(data.type).toBe('facts')
		expect(data.content.endpointSource).toEqual(summary)
		expect(data.content.columns).toBe(3)
		const margin = data.content.fields.find((f) => f.key === 'margin')
		expect(nl[margin.label]).toBe('Marge')
		expect(routes).toContain("'url' => '/api/pos-shifts/{id}/summary'")
	})

	it('draws the shift status as a pill', () => {
		const status = widget(config, 'shift-data').content.fields.find((f) => f.key === 'status')
		expect(status.pill.variantMap).toEqual({ open: 'info', closed: 'warning', reconciled: 'success' })
	})

	it('draws the cash difference as figures with a band and a reason', () => {
		const variance = widget(config, 'shift-variance').content
		expect(variance.layout).toBe('tiles')
		expect(variance.tileStyle).toBe('figures')
		expect(variance.fields.find((f) => f.path === 'diffAmount').tone[0].variant).toBe('error')
		expect(variance.notice.visibleWhen).toBeDefined()
		expect(variance.input).toMatchObject({ key: 'reason', visibleWhen: { field: 'status', value: 'pending' } })
		const reject = variance.actions.find((a) => a.id === 'diff-reject')
		expect(reject.type).toBe('api-call')
		expect(reject.payload).toEqual({ diffId: '@source.id', reason: '@input.reason' })
	})

	it('writes the opening float with the drops taken out of the drawer', () => {
		const opening = widget(config, 'shift-opening').content
		expect(opening.endpointSource).toEqual(summary)
		expect(opening.fields.map((f) => f.template).filter(Boolean)).toContain('{dropCount} drops, {dropTotal:currency} out of the drawer')
	})

	it('links the sales and the Z report as plain links', () => {
		const related = widget(config, 'shift-related')
		expect(related.type).toBe('related')
		expect(related.content.layout).toBe('links')
		expect(related.content.extraSources.map((s) => s.path)).toEqual(['sales', 'zReports'])
	})
})

describe('cash shifts list', () => {
	const columns = read('src/menu-layout.simple.json').pages.find((p) => p.id === 'CashShifts').config.columns

	it('reads drops, difference and outcome from the summary endpoint', () => {
		const byKey = Object.fromEntries(columns.map((c) => [c.key, c]))
		expect(byKey.status.aggregate).toEqual({ endpoint: '/apps/pipelinq/api/pos-shifts/summary/outcome', key: 'id' })
		expect(byKey.dropCount.aggregate.endpoint).toBe('/apps/pipelinq/api/pos-shifts/summary/dropCount')
		expect(byKey.difference.aggregate.endpoint).toBe('/apps/pipelinq/api/pos-shifts/summary/difference')
		expect(columns.map((c) => nl[c.label])).toEqual(['Dienst', 'Kassamedewerker', 'Status', 'Geopend', 'Gesloten', 'Beginsaldo', 'Afstortingen', 'Verschil'])
		expect(nl['Outside the margin']).toBe('Buiten de marge')
		expect(routes).toContain("'url' => '/api/pos-shifts/summary/{measure}'")
	})
})
