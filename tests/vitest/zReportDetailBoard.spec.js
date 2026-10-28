/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Board PqZRapport as configuration: the reference names the report, the
 * meta line carries the till, the day and the transactions, the status is a
 * coloured pill, the report sits in an Overview tab beside History, and the
 * day total is a side card.
 *
 * @spec openspec/specs/declarative-view-system/spec.md
 */

import fs from 'fs'
import path from 'path'
import { describe, expect, it } from 'vitest'

const ROOT = path.resolve(__dirname, '../..')
const manifest = JSON.parse(fs.readFileSync(path.join(ROOT, 'src', 'manifest.json'), 'utf8'))
const nl = JSON.parse(fs.readFileSync(path.join(ROOT, 'l10n', 'nl.json'), 'utf8')).translations
const config = manifest.pages.find((p) => p.id === 'ZReportDetail').config
const widget = (id) => config.widgets.find((w) => w.id === id)

describe('Z report page', () => {
	it('names the report by its reference and carries the board header', () => {
		expect(config.titleField).toBe('reference')
		expect(config.headerCard).toBe(true)
		expect(nl[config.headerMeta]).toContain('transacties')
		expect(config.statusPill.colorMap.failed).toBe('error')
	})

	it('puts the report in an Overview tab beside History, and the day total aside', () => {
		const tabs = widget('z-panels').content.tabs
		expect(tabs.map((tab) => tab.widgetIds || [tab.widgetId])).toEqual([['z-data', 'z-bookkeeping'], ['z-history']])
		expect(config.layout.map((cell) => cell.widgetId)).toEqual(['z-panels'])
		expect(config.sideColumn.slice(0, 3)).toEqual(['z-day-total', 'z-day-tax', 'z-day-count'])
	})

	it('labels the fields as the board does', () => {
		const overrides = widget('z-data').content.overrides
		expect(nl[overrides.reference.label]).toBe('Kenmerk')
		expect(nl[overrides.terminalId.label]).toBe('Kassa')
	})

	it('offers "Journaalpost opnieuw doorgeven" in the header on a day still to raise', () => {
		const action = config.headerActions.find((a) => a.id === 'raise-journal-entry')
		expect(nl[action.label]).toBe('Journaalpost opnieuw doorgeven')
		expect(action.type).toBe('api-call')
		expect(action.url).toBe('/apps/pipelinq/api/pos-bookkeeping/post')
		expect(action.payload).toEqual({ zReportId: '@objectId' })
		expect(action.visibleWhen.all).toEqual([
			{ field: 'bookkeepingStatus', op: 'in', value: ['pending', 'failed'] },
			{ field: 'transactionCount', op: 'gt', value: 0 },
		])
	})

	it('counts the History tab', () => {
		const history = widget('z-panels').content.tabs.find((tab) => tab.widgetId === 'z-history')
		expect(history.countFrom).toBe('widget')
	})

	it('puts the bookkeeping in a Boekhouding card under the report, not in a loose section', () => {
		const card = widget('z-bookkeeping')
		expect(card.type).toBe('ZReportBookkeepingWidget')
		expect(nl[card.title]).toBe('Boekhouding')
		expect(config.bodyWidgets || []).toEqual([])
	})

	it('draws "Journaalpost opnieuw doorgeven" as a header button, and no Edit for a generated report', () => {
		expect(config.quickActions).toEqual(['raise-journal-entry'])
		expect(config.showEditAction).toBe(false)
	})

	it('lists the latest history in a Geschiedenis side card under the day total', () => {
		expect(config.sideColumn).toEqual(['z-day-total', 'z-day-tax', 'z-day-count', 'z-side-history'])
		const history = widget('z-side-history')
		expect(history.type).toBe('audit-trail')
		expect(nl[history.title]).toBe('Geschiedenis')
		expect(history.content.excludeActions).toEqual(['read'])
	})

	it('draws Dagtotaal, Btw and Transacties as three stat tiles off the report (pl3 item 5)', () => {
		const tiles = ['z-day-total', 'z-day-tax', 'z-day-count'].map(widget)
		expect(tiles.map((tile) => tile.type)).toEqual(['stat', 'stat', 'stat'])
		expect(tiles.map((tile) => nl[tile.content.label])).toEqual(['Dagtotaal', 'Btw', 'Transacties'])
		expect(tiles.map((tile) => tile.content.objectField)).toEqual(['total', 'totalTax', 'transactionCount'])
		expect(tiles[0].content.format).toEqual({ style: 'currency', currency: '@config.currency', decimals: 2 })
		expect(tiles[1].content.format).toEqual({ style: 'currency', currency: '@config.currency', decimals: 2 })
	})
})
