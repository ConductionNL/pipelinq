/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 * SPDX-License-Identifier: EUPL-1.2
 *
 * The leads list as board PqLeads draws it: it opens on the default pipeline
 * as a removable filter, the count line names the pipeline's value and the
 * weighted forecast, each lead reads its client above the source, and the
 * Verouderd lens counts.
 *
 * @spec openspec/changes/leads-list-board-line/specs/leads-list-board-line/spec.md#requirement-the-leads-list-is-the-board
 */

import { readFileSync } from 'node:fs'
import { resolve } from 'node:path'
import { beforeAll, describe, expect, it, vi } from 'vitest'

vi.mock('@conduction/nextcloud-vue', () => ({ CnIndexPage: {}, openRowTarget: vi.fn() }))
vi.mock('../../src/store/modules/object.js', () => ({ useObjectStore: () => ({}) }))
vi.mock('../../src/store/modules/settings.js', () => ({ useSettingsStore: () => ({ config: {} }) }))

let LeadList
const source = readFileSync(resolve(__dirname, '../../src/views/leads/LeadList.vue'), 'utf8')

beforeAll(async () => {
	globalThis.t = (app, text) => text
	LeadList = (await import('../../src/views/leads/LeadList.vue')).default
})

const state = () => ({ ...LeadList.data.call({}), settingsStore: { config: {} } })

describe('the leads list as the board', () => {
	it('opens on the default pipeline once it is read', async () => {
		const ctx = state()
		expect(ctx.defaultFilters).toEqual({ pipeline: '@config.defaultPipeline?' })
		expect(LeadList.computed.appConfig.call(ctx)).toEqual({})
		const fetchCollection = vi.fn(async () => [{ id: 'p-other' }, { id: 'p-sales', isDefault: true, stages: [] }])
		Object.assign(ctx, { objectStore: { fetchCollection } })
		await LeadList.methods.loadDefaultPipeline.call(ctx)
		expect(LeadList.computed.appConfig.call(ctx)).toEqual({ defaultPipeline: 'p-sales' })
	})

	it('names the pipeline value and the weighted forecast on the count line', () => {
		const ctx = state()
		expect(ctx.countValues.pipeline).toEqual({ metric: 'sum', field: 'value', filter: { status: 'open' } })
		expect(ctx.countValues.weighted).toEqual({ endpoint: '/apps/pipelinq/api/analytics/commercial', path: 'weightedForecast', followFilters: true })
		expect(source).toContain('{pipeline|currency:EUR} in the pipeline, {weighted|currency:EUR} weighted')
		expect(source).toContain(':countValues="countValues"')
	})

	it('reads each lead\'s client above its source', () => {
		const title = state().columns.find((column) => column.key === 'title')
		expect(title.secondary).toBe('{client.name} · {source}')
		expect(source).toContain(':extend="[\'client\']"')
	})

	it('counts the Verouderd lens', () => {
		const ctx = state()
		ctx.staleThreshold = 14
		const lens = LeadList.computed.quickFilters.call(ctx).find((filter) => filter.label === 'Out of date')
		expect(lens.showCount).toBe(true)
	})
})
