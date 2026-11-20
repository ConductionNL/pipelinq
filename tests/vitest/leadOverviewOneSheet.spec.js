/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Board PqLead "Overzicht" as configuration: one sheet with the stage strip
 * (untitled), the deal, its products, its contacts and its flow runs as
 * sections, and the products table read as the board draws it: plain line
 * text and sortable heads.
 *
 * @spec openspec/specs/lead-overview-one-sheet/spec.md
 */
import { readFileSync } from 'node:fs'
import { resolve } from 'node:path'
import { describe, expect, it } from 'vitest'

const manifest = JSON.parse(readFileSync(resolve(__dirname, '../../src/manifest.json'), 'utf8'))
const config = manifest.pages.find((page) => page.id === 'LeadDetail').config
const widget = (id) => config.widgets.find((w) => w.id === id)

describe('the lead overview (board PqLead)', () => {
	it('draws the overview as one sheet of sections', () => {
		const overview = widget('lead-panels').content.tabs[0]
		expect(overview.widgetIds).toEqual(['lead-stages', 'lead-deal', 'lead-lines', 'lead-exchange-rate', 'lead-contacts', 'lead-flow-runs'])
		expect(overview.sections).toBe(true)
	})

	it('draws the stage strip without a title', () => {
		expect(widget('lead-stages').showTitle).toBe(false)
	})

	it('reads the product lines as plain text under sortable heads', () => {
		const lines = widget('lead-lines').content
		expect(lines.rowTitle).toBe('text')
		expect(lines.sortable).toBe(true)
		expect(lines.filterable).toBe(true)
	})
})
