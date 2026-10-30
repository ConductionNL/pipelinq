/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Board PqWerkplekKlant in a call: a row of six tiles opens each next step
 * for the client on the line, the line under the title carries the call's
 * pills, and the in-call cards carry the board's titles.
 *
 * @spec openspec/changes/werkplek-in-call-tiles-and-header/specs/kcc-werkplek/spec.md#requirement-the-in-call-page-offers-the-next-steps-as-tiles
 * @spec openspec/changes/werkplek-in-call-tiles-and-header/specs/kcc-werkplek/spec.md#requirement-the-in-call-header-marks-the-call
 * @spec openspec/changes/werkplek-in-call-tiles-and-header/specs/kcc-werkplek/spec.md#requirement-the-in-call-cards-carry-the-boards-titles
 */

import { readFileSync } from 'node:fs'
import { resolve } from 'node:path'
import { describe, expect, it } from 'vitest'

const simple = JSON.parse(readFileSync(resolve(__dirname, '../../src/menu-layout.simple.json'), 'utf8'))
const nl = JSON.parse(readFileSync(resolve(__dirname, '../../l10n/nl.json'), 'utf8')).translations
const en = JSON.parse(readFileSync(resolve(__dirname, '../../l10n/en.json'), 'utf8')).translations
const page = simple.pages.find((p) => p.id === 'KccWerkplek')
const inCall = page.config.layoutWhen[0].layout
const callActive = { workspace: 'callActive', op: 'eq', value: true }

describe('werkplek in-call tiles (PqWerkplekKlant)', () => {
	const tiles = page.configAppend.widgets.find((w) => w.type === 'action-tiles')

	it('shows six tiles in the board\'s order, only in a call', () => {
		expect(tiles.visibleWhen).toEqual(callActive)
		expect(tiles.content.tiles.map((tile) => nl[tile.label])).toEqual([
			'Nieuw verzoek', 'Terugbellen', 'Afspraak boeken', 'Naar backoffice', 'Klacht registreren', 'Ander kanaal',
		])
	})

	it('opens every step for the client on the line', () => {
		for (const tile of tiles.content.tiles) {
			expect(JSON.stringify(tile.action), tile.label).toContain('@workspace.selectedClient')
			expect(['open-form', 'open-modal'], tile.label).toContain(tile.action.type)
			expect(tile.icon, tile.label).toBeTruthy()
		}
	})

	it('draws the row across the top of the call, as a bare row, with the cards under it', () => {
		const row = inCall.find((item) => item.widgetId === tiles.id)
		expect(row).toMatchObject({ gridX: 0, gridY: 0, gridWidth: 12, showTitle: false, borderless: true })
		for (const item of inCall.filter((i) => i !== row)) {
			expect(item.gridY, item.widgetId).toBeGreaterThanOrEqual(row.gridY + row.gridHeight)
		}
	})
})

describe('werkplek in-call header (PqWerkplekKlant)', () => {
	it('opens the line under the title with the call\'s pills', () => {
		const pills = page.config.headerPills
		expect(pills.map((pill) => nl[pill.label])).toEqual(['Telefoon, inkomend', 'Geïdentificeerd'])
		expect(pills[0].when).toEqual(callActive)
		expect(pills[1].when).toEqual({ workspace: 'callIdentified', op: 'eq', value: true })
	})
})

describe('werkplek in-call card titles (PqWerkplekKlant)', () => {
	it('titles the in-call cards as the board does', () => {
		const title = (widgetId) => {
			const item = inCall.find((i) => i.widgetId === widgetId)
			return nl[item.title] || item.title
		}
		expect(title('interaction')).toBe('Dit contactmoment')
		expect(title('client-cases')).toBe('Openstaande zaken')
		expect(title('client-contacts')).toBe('Eerder contact')
	})

	it('has an English source and a Dutch text for every new string', () => {
		const tiles = page.configAppend.widgets.find((w) => w.type === 'action-tiles')
		const strings = [
			...tiles.content.tiles.flatMap((tile) => [tile.label, tile.action.formTitle].filter(Boolean)),
			...page.config.headerPills.map((pill) => pill.label),
			...inCall.map((item) => item.title).filter(Boolean),
		]
		for (const text of strings) {
			expect(en[text], text).toBe(text)
			expect(nl[text], text).toBeTruthy()
		}
	})
})
