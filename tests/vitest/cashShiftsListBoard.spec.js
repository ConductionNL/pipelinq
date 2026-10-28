/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Board PqKassadiensten as configuration (the simple structure's list of
 * cash shifts): Download and Open shift in the header, the All / Open /
 * To check lenses with counts, the Filter menu, the board's columns with
 * times and money, and the count line with the blind-count note.
 *
 * @spec openspec/specs/declarative-view-system/spec.md
 */

import fs from 'fs'
import path from 'path'
import { describe, expect, it } from 'vitest'

const ROOT = path.resolve(__dirname, '../..')
const read = (file) => JSON.parse(fs.readFileSync(path.join(ROOT, file), 'utf8'))
const nl = read('l10n/nl.json').translations
const base = read('src/manifest.json').pages.find((p) => p.id === 'CashShifts').config
const config = read('src/menu-layout.simple.json').pages.find((p) => p.id === 'CashShifts').config

describe('cash shifts list', () => {
	it('has Download and Open shift as the header buttons', () => {
		expect(config.headerButtons).toEqual([
			{ action: 'export', label: 'Download' },
			{ action: 'open-shift', variant: 'primary', icon: 'Plus', label: 'Open shift' },
		])
		expect(base.headerActions.map((a) => a.id)).toContain('open-shift')
		expect(nl['Open shift']).toBe('Dienst openen')
		expect(nl[base.description]).toBe('Beheer kassadiensten: beginsaldo, afstortingen, blind tellen en afstemmen')
	})

	it('offers the board lenses with counts and the Filter menu', () => {
		expect(config.quickFilters.map((q) => nl[q.label])).toEqual(['Alle', 'Open', 'Te controleren'])
		expect(config.quickFilters.every((q) => q.showCount === true)).toBe(true)
		expect(config.quickFilters[2].filter).toEqual({ status: 'closed', reconciliationStatus: 'pending' })
		expect(config.filterMenu).toBe(true)
		expect(nl[config.searchPlaceholder]).toBe('Filter op kassamedewerker of kassa')
	})

	it('draws the board columns', () => {
		const columns = config.columns
		expect(columns.map((c) => nl[c.label])).toEqual(['Dienst', 'Kassamedewerker', 'Status', 'Geopend', 'Gesloten', 'Beginsaldo'])
		expect(columns[2].widget).toBe('badge')
		expect(columns[3].formatter).toBe('timeOfDay')
		expect(columns[4].formatter).toBe('timeOfDay')
		expect(columns[5].formatter).toBe('objectCurrency')
	})

	it('ends with the count and the blind-count note', () => {
		expect(config.countText).toBe('{shown} of {total}')
		expect(nl[config.footerNote]).toBe('Een dienst wordt gesloten met een blinde telling. Het verschil wordt getoetst aan de marge.')
	})
})
