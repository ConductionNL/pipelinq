// @vitest-environment jsdom
/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 * SPDX-License-Identifier: EUPL-1.2
 *
 * The forecast dashboard, mounted with the forecast API doubled: which
 * snapshots it loads per level, the summary figures and tiles, the at-risk
 * warning, owner names, overrides, exchange rates and the CSV export.
 *
 * @spec openspec/specs/forecast-roll-up-and-categories/spec.md
 */

import { flushPromises, shallowMount } from '@vue/test-utils'
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'

vi.hoisted(() => {
	globalThis.t = (app, text, vars = {}) => String(text).replace(/\{(\w+)\}/g, (m, k) => (k in vars ? String(vars[k]) : m))
	globalThis.n = (app, one, many, count, vars = {}) => globalThis.t(app, count === 1 ? one : many, vars).replace('%n', String(count))
})

const stub = (name) => ({ name, render: () => null })
vi.mock('@conduction/nextcloud-vue', () => ({
	CnBuildiqEditButton: stub('CnBuildiqEditButton'),
	CnDataTable: stub('CnDataTable'),
	CnDetailCard: stub('CnDetailCard'),
	CnPageHeader: stub('CnPageHeader'),
	CnSegmentedControl: stub('CnSegmentedControl'),
}))
vi.mock('@nextcloud/vue', () => ({ NcButton: stub('NcButton'), NcEmptyContent: stub('NcEmptyContent'), NcLoadingIcon: stub('NcLoadingIcon'), NcNoteCard: stub('NcNoteCard') }))
vi.mock('../../src/modals/ForecastOverrideModal.vue', () => ({ default: stub('ForecastOverrideModal') }))
vi.mock('../../src/views/forecast/ForecastTrend.vue', () => ({ default: stub('ForecastTrend') }))
vi.mock('../../src/services/forecastApi.js', () => ({ csvExportUrl: (q) => `/export?${q.periodId}&${q.level}`, fetchSnapshots: vi.fn() }))
vi.mock('../../src/services/userDisplayName.js', () => ({ fetchUserDisplayName: vi.fn() }))

const forecastApi = await import('../../src/services/forecastApi.js')
const users = await import('../../src/services/userDisplayName.js')
const { formatEur } = await import('../../src/services/commercialFormat.js')
const { default: ForecastDashboard } = await import('../../src/views/forecast/ForecastDashboard.vue')

globalThis.t = (app, text, vars = {}) => text.replace(/\{(\w+)\}/g, (m, k) => (k in vars ? String(vars[k]) : m))

const snapshots = [
	{ owner_id: 'ann', as_of_date: '2026-05-04', commit: 400, best_case: 200, pipeline: 900, closed_won: 100, quota: 1000, currency: 'EUR', exchange_rates: { USD: { rate: 0.9 } } },
	{ owner_id: 'bob', as_of_date: '2026-05-05', commit: 100, best_case: 0, pipeline: 100, closed_won: 0, quota: 1000, commit_override_reason: 'Deal slipped', original_commit: 300, currency: 'EUR', exchange_rates: { GBP: { rate: 1.1 } } },
]

/**
 * Mount the dashboard and let it load.
 *
 * @return {Promise<object>} The wrapper.
 */
async function mountDashboard() {
	const w = shallowMount(ForecastDashboard, { global: { mocks: { t: globalThis.t, n: globalThis.n } } })
	await flushPromises()
	return w
}

beforeEach(() => {
	vi.useFakeTimers({ now: new Date(2026, 4, 10, 12), toFake: ['Date'] })
	vi.clearAllMocks()
	forecastApi.fetchSnapshots.mockResolvedValue({ snapshots })
	users.fetchUserDisplayName.mockImplementation(async (id) => (id === 'ann' ? 'Ann de Vries' : Promise.reject(new Error('x'))))
})

afterEach(() => {
	vi.useRealTimers()
})

describe('ForecastDashboard', () => {
	it('loads the current quarter one level below the chosen one, and names the owners', async () => {
		const w = await mountDashboard()
		expect(forecastApi.fetchSnapshots).toHaveBeenCalledWith({ periodId: 'Q2-2026', level: 'rep' })
		expect(w.vm.quarterRows.map((r) => [r.id, r.name])).toEqual([['ann', 'Ann de Vries'], ['bob', 'bob']])
		expect(w.vm.quarterTitle).toBe('Quarter 2 of 2026')
		expect(w.vm.subtitle).toMatch(/^Quarter 2 of 2026 · snapshot of .+ · level team$/)
		w.vm.setLevel('company')
		await flushPromises()
		expect(forecastApi.fetchSnapshots).toHaveBeenLastCalledWith({ periodId: 'Q2-2026', level: 'division' })
		w.vm.level = 'other'
		expect(w.vm.childLevel).toBe('rep')
	})

	it('sums the team figures, projecting half of best case', async () => {
		const w = await mountDashboard()
		expect(w.vm.summary).toEqual({ commit: 500, bestCase: 200, closedWon: 100, quota: 2000, projected: 700, gap: 1300 })
		const tiles = Object.fromEntries(w.vm.tiles.map((tile) => [tile.id, tile]))
		expect([tiles.quota.value, tiles.won.value, tiles.commit.value, tiles.gap.value]).toEqual([formatEur(2000), formatEur(100), formatEur(500), formatEur(1300)])
		expect([tiles.gap.warn, tiles.gap.caption]).toEqual([true, '65% below quota'])
		expect(w.vm.progressLabel).toBe(`${formatEur(600)} of ${formatEur(2000)} on track (30%)`)
		expect([w.vm.pct(1500), w.vm.pct(5000)]).toEqual([75, 100])
	})

	it('warns when the team is far below quota close to the end of the quarter', async () => {
		const w = await mountDashboard()
		expect(w.vm.atRisk).toBe(false)
		w.vm.daysRemaining = 10
		expect(w.vm.atRisk).toBe(true)
		expect(w.vm.atRiskMessage).toBe('This team is 65% below quota with 10 days to close. Action recommended.')
	})

	it('lists the overridden commits and the exchange rates used', async () => {
		const w = await mountDashboard()
		expect(w.vm.overriddenRows.map((r) => r.owner_id)).toEqual(['bob'])
		expect(w.vm.overrideTitle(snapshots[1])).toBe(`Rep submitted: ${formatEur(300)} — Deal slipped`)
		expect(w.vm.exchangeRates).toEqual([
			{ rate: 1.1, currency: 'GBP', reportingCurrency: 'EUR' },
			{ rate: 0.9, currency: 'USD', reportingCurrency: 'EUR' },
		])
	})

	it('shows nothing to do without snapshots, and survives a failed load', async () => {
		forecastApi.fetchSnapshots.mockResolvedValueOnce({})
		const w = await mountDashboard()
		expect([w.vm.rows, w.vm.snapshotDate, w.vm.summary.gap]).toEqual([[], '', 0])
		expect(w.vm.tiles.find((tile) => tile.id === 'gap')).toMatchObject({ warn: false, caption: '' })
		expect(w.vm.subtitle).toBe('Quarter 2 of 2026 · level team')
		forecastApi.fetchSnapshots.mockRejectedValueOnce(new Error('x'))
		await w.vm.loadSnapshots()
		expect([w.vm.rows, w.vm.loading]).toEqual([[], false])
	})

	it('reloads after an override is saved and exports the quarter', async () => {
		const w = await mountDashboard()
		w.vm.openOverride(snapshots[0])
		expect(w.vm.overrideTarget).toEqual(snapshots[0])
		w.vm.onOverrideSaved()
		await flushPromises()
		expect([w.vm.overrideTarget, forecastApi.fetchSnapshots.mock.calls.length]).toEqual([null, 2])
		const original = window.location
		Object.defineProperty(window, 'location', { value: { href: '' }, configurable: true })
		w.vm.exportCsv()
		expect(window.location.href).toBe('/export?Q2-2026&rep')
		Object.defineProperty(window, 'location', { value: original, configurable: true })
		expect(w.vm.quarterColumns.map((c) => c.key)).toEqual(['name', 'commit', 'best_case', 'pipeline', 'closed_won', 'quota', 'override'])
		expect(w.vm.levelOptions.map((o) => o.value)).toEqual(['rep', 'team', 'division', 'company'])
	})
})
