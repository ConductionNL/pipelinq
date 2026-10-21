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
import { beforeAll, describe, expect, it, vi } from 'vitest'
import { STAGE_COLORS } from '../../src/services/stageColors.js'

vi.mock('@conduction/nextcloud-vue', () => ({
	CnIndexPage: {},
	openRowTarget: vi.fn(),
}))
vi.mock('../../src/store/modules/object.js', () => ({ useObjectStore: () => ({}) }))
vi.mock('../../src/store/modules/settings.js', () => ({
	useSettingsStore: () => ({ config: {} }),
}))

let LeadList

beforeAll(async () => {
	globalThis.t = (app, text) => text
	LeadList = (await import('../../src/views/leads/LeadList.vue')).default
})

/**
 * The list's computed values over its own data.
 *
 * @return {object}
 */
function list() {
	const ctx = { ...LeadList.data.call({}), settingsStore: { config: {} } }
	ctx.staleThreshold = LeadList.computed.staleThreshold.call(ctx)
	return ctx
}

describe('lead list board look', () => {
	it('offers Closes this month on open leads due this month', () => {
		const chip = LeadList.computed.quickFilters
			.call(list())
			.find((f) => f.label === 'Closes this month')
		expect(chip.filter).toEqual({
			status: 'open',
			'expectedCloseDate[gte]': '@monthStart',
			'expectedCloseDate[lte]': '@monthEnd',
		})
		expect(chip.showCount).toBe(true)
	})

	it('draws the stage as a pill coloured per stage', () => {
		const stage = list().columns.find((c) => c.key === 'stage')
		expect(stage.widget).toBe('badge')
		expect(stage.widgetProps.colorMap).toBe(STAGE_COLORS)
		expect(STAGE_COLORS.gekwalificeerd).toBe('purple')
		expect(STAGE_COLORS.won).toBe('success')
	})

	it('switches between table, cards, the pipeline board and the map', () => {
		const ctx = list()
		expect(ctx.viewSwitch).toEqual(['table', 'cards', 'board', 'map'])
		expect(ctx.viewSwitchLinks).toEqual({ board: { route: 'Pipeline' } })
	})

	it('declares the suggested colleagues side card as a record list', () => {
		const manifest = JSON.parse(
			readFileSync(resolve(__dirname, '../../src/manifest.json'), 'utf8'),
		)
		const lead = manifest.pages.find((p) => p.id === 'LeadDetail')
		expect(lead.config.widgets.find((w) => w.id === 'side-suggested').type).toBe('record-list')
	})
})

describe('lead list config', () => {
	it('does not let the page config fall through onto the index page', () => {
		expect(LeadList.inheritAttrs).toBe(false)
	})
})
