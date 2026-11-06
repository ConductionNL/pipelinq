/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Board PqVerkoopoverzicht (pl8 1-9): the revenue charts carry a subtitle
 * under their title, and every card is as tall as what it holds, the two
 * columns stacking their cards without the empty rows a fixed grid height
 * left under them (live cards sat 24 to 241px lower).
 *
 * @spec openspec/changes/sales-overview-subtitles-fit/specs/dashboard-widgets/spec.md#requirement-the-sales-overview-cards-carry-the-board-subtitles-and-end-at-their-content
 */
import { readFileSync } from 'node:fs'
import { resolve } from 'node:path'
import { describe, expect, it } from 'vitest'

const manifest = JSON.parse(readFileSync(resolve(__dirname, '../../src/manifest.json'), 'utf8'))
const nl = JSON.parse(readFileSync(resolve(__dirname, '../../l10n/nl.json'), 'utf8')).translations
const page = manifest.pages.find((candidate) => candidate.id === 'Dashboard')
const widget = (id) => page.config.widgets.find((candidate) => candidate.id === id)

describe('sales overview', () => {
	it.each(['revenue-over-time', 'revenue-by-category', 'top-customers'])('%s carries the subtitle "Won deals"', (id) => {
		expect(widget(id).subtitle).toBe('Won deals')
		expect(nl['Won deals']).toBe('Gewonnen deals')
	})

	it('packs the grid so a card that ends at its content pulls the cards under it up', () => {
		expect(page.config.gridFloat).toBe(false)
	})

	it('lets every card but the revenue chart end at its content', () => {
		for (const item of page.config.layout) {
			if (item.widgetId === 'revenue-over-time') {
				expect(item.sizeToContent, item.widgetId).toBeUndefined()
			} else {
				expect(item.sizeToContent, item.widgetId).toBe(true)
			}
		}
	})
})
