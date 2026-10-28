/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Board PqKassadienst as configuration: the shift's name, its status as a
 * pill, the Kassalade breadcrumb and a meta line with the cashier and the
 * hours; Approve difference as the header button of a counted shift; the
 * shift, its cash difference and its drops in an Overview tab beside
 * History; the opening float, related records and a short history aside.
 *
 * @spec openspec/specs/declarative-view-system/spec.md
 */

import fs from 'fs'
import path from 'path'
import { describe, expect, it } from 'vitest'
import { CELL_FORMATTERS, timeOfDay } from '../../src/services/cellFormatters.js'

const ROOT = path.resolve(__dirname, '../..')
const manifest = JSON.parse(fs.readFileSync(path.join(ROOT, 'src', 'manifest.json'), 'utf8'))
const nl = JSON.parse(fs.readFileSync(path.join(ROOT, 'l10n', 'nl.json'), 'utf8')).translations
const config = manifest.pages.find((p) => p.id === 'CashShiftDetail').config
const widget = (id) => config.widgets.find((w) => w.id === id)

describe('cash shift page', () => {
	it('carries the board header', () => {
		expect(config.follow).toBe(false)
		expect(config.showEditAction).toBe(false)
		expect(nl[config.breadcrumb.label]).toBe('Kassalade')
		expect(config.breadcrumb.route).toBe('CashShifts')
		expect(nl[config.statusPill.labels.closed]).toBe('Geteld')
		expect(nl[config.headerMeta]).toBe('{operator|user} · {floatAt|time} tot {closedAt|time}')
	})

	it('offers Approve difference as the primary button of a counted shift', () => {
		expect(config.primaryActionByStage.closed).toBe('shift-approve-diff')
		const approve = config.headerActions.find((a) => a.id === 'shift-approve-diff')
		expect(approve.type).toBe('api-call')
		expect(approve.url).toBe('/apps/pipelinq/api/pos-shifts/@objectId/diff/approve')
		expect(approve.payload).toBeUndefined()
		expect(nl[approve.label]).toBe('Verschil goedkeuren')
		expect(approve.visibleWhen).toEqual({ all: [
			{ field: 'status', value: 'closed' },
			{ field: 'reconciliationStatus', value: 'pending' },
		] })
	})

	it('puts the shift, the difference and the drops in an Overview tab beside History', () => {
		const tabs = widget('shift-panels').content.tabs
		expect(tabs.map((tab) => nl[tab.label])).toEqual(['Overzicht', 'Geschiedenis'])
		expect(tabs[0].widgetIds).toEqual(['shift-data', 'shift-variance', 'shift-drops'])
		expect(widget(tabs[1].widgetId).type).toBe('audit-trail')
		expect(config.layout.map((cell) => cell.widgetId)).toEqual(['shift-panels'])
	})

	it('names the shift fields as the board does', () => {
		const data = widget('shift-data').content
		expect(data.columns).toBe(3)
		expect(data.include).toEqual(['drawer', 'operator', 'floatAt', 'closedAt', 'status'])
		expect(nl[data.overrides.drawer.label]).toBe('Kassa')
		expect(nl[data.overrides.operator.label]).toBe('Kassamedewerker')
		expect(nl[data.overrides.status.enumLabels.closed]).toBe('Geteld')
	})

	it('lists the drops as Afstortingen with money and times', () => {
		const drops = widget('shift-drops')
		expect(nl[drops.title]).toBe('Afstortingen')
		const columns = drops.content.columns
		expect(columns.map((c) => nl[c.label])).toEqual(['Reden', 'Bedrag', 'Om', 'Door'])
		expect(columns[1].format.style).toBe('currency')
		expect(columns[2].formatter).toBe('timeOfDay')
		expect(columns[3].formatter).toBe('userDisplayName')
		expect(nl[drops.content.footnote]).toContain('alleen bij een open dienst')
	})

	it('draws the side column: opening float, related, history', () => {
		expect(config.sideColumn).toEqual(['shift-opening', 'shift-related', 'shift-side-history'])
		const opening = widget('shift-opening')
		expect(nl[opening.title]).toBe('Beginsaldo')
		expect(opening.content.fields[0]).toMatchObject({ as: 'title', path: 'floatAmount', format: 'currency' })
		expect(opening.content.fields[1].countFrom.schema).toBe('cashDrop')
		const related = widget('shift-related')
		expect(related.type).toBe('facts')
		expect(related.content.fields[0].countFrom).toEqual({ register: 'pipelinq', schema: 'posTransaction', filter: { shift: '@objectId' } })
		expect(widget('shift-side-history').type).toBe('audit-trail')
	})
})

describe('timeOfDay', () => {
	it('writes the hour and minute of a moment', () => {
		expect(timeOfDay('2026-10-06T12:10:00', null, null, { locale: 'nl-NL' })).toBe('12:10')
		expect(timeOfDay('', null, null)).toBe('')
		expect(CELL_FORMATTERS.timeOfDay).toBe(timeOfDay)
	})
})
