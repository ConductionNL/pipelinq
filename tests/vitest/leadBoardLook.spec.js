// @vitest-environment jsdom
/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 * SPDX-License-Identifier: EUPL-1.2
 *
 * The lead list as the PqLeads board draws it: the Closes this month chip,
 * the stage as a coloured pill and the four-segment view switch whose board
 * segment opens the Pipeline page.
 *
 * @spec openspec/specs/lead-management/spec.md
 */

import { readFileSync } from 'node:fs'
import { resolve } from 'node:path'
import { describe, expect, it } from 'vitest'

const manifest = JSON.parse(readFileSync(resolve(__dirname, '../../src/manifest.json'), 'utf8'))
const leads = manifest.pages.find((p) => p.route === '/leads')

describe('lead list board look', () => {
	it('offers Closes this month on open leads due this month', () => {
		const chip = leads.config.quickFilters.find((f) => f.label === 'Closes this month')
		expect(chip.filter).toEqual({
			status: 'open',
			'expectedCloseDate[gte]': '@monthStart',
			'expectedCloseDate[lte]': '@monthEnd',
		})
		expect(chip.showCount).toBe(true)
	})

	it('draws the stage as a pill coloured per stage', () => {
		const stage = leads.config.columns.find((c) => c.key === 'stage')
		expect(stage.widget).toBe('badge')
		expect(stage.widgetProps.colorMap.gekwalificeerd).toBe('purple')
		expect(stage.widgetProps.colorMap.won).toBe('success')
		expect(stage.widgetProps.colorMap.lost).toBe('error')
	})

	it('switches between table, cards, the pipeline board and the map', () => {
		expect(leads.config.viewSwitch).toEqual(['table', 'cards', 'board', 'map'])
		expect(leads.config.viewSwitchLinks).toEqual({ board: { route: 'Pipeline' } })
	})

	it('declares the suggested colleagues side card as a record list', () => {
		const manifest = JSON.parse(
			readFileSync(resolve(__dirname, '../../src/manifest.json'), 'utf8'),
		)
		const lead = manifest.pages.find((p) => p.id === 'LeadDetail')
		expect(lead.config.widgets.find((w) => w.id === 'side-suggested').type).toBe('record-list')
	})
})
