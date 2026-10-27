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
		const tabs = widget('z-panels').content.tabs.map((tab) => tab.widgetId)
		expect(tabs).toEqual(['z-data', 'z-history'])
		expect(config.layout.map((cell) => cell.widgetId)).toEqual(['z-panels'])
		expect(config.sideColumn).toEqual(['z-day-total'])
		expect(widget('z-day-total').content.include).toEqual(['total', 'totalTax', 'transactionCount'])
	})

	it('labels the fields as the board does', () => {
		const overrides = widget('z-data').content.overrides
		expect(nl[overrides.reference.label]).toBe('Kenmerk')
		expect(nl[overrides.terminalId.label]).toBe('Kassa')
	})
})
