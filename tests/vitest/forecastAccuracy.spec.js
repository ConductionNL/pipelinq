// @vitest-environment jsdom
/**
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * PqForecast's accuracy table: per seller, the commit of a closed quarter's
 * first snapshot against what that quarter closed won.
 *
 * @spec openspec/changes/forecast-accuracy-rows/specs/forecasting/spec.md#requirement-the-forecast-shows-last-quarters-accuracy-per-seller
 */
import { flushPromises, mount } from '@vue/test-utils'
import { describe, expect, it, vi } from 'vitest'
import { accuracyRows, previousPeriodId } from '../../src/services/forecastMath.js'

const q3 = [
	{ owner_id: 'pieter', as_of_date: '2026-07-01', commit: 52000, closed_won: 0 },
	{ owner_id: 'fatma.yildiz', as_of_date: '2026-07-01', commit: 21000, closed_won: 0 },
	{ owner_id: 'joris.bakker', as_of_date: '2026-07-01', commit: 25000, closed_won: 0 },
	{ owner_id: 'pieter', as_of_date: '2026-09-30', commit: 49500, closed_won: 49500 },
	{ owner_id: 'fatma.yildiz', as_of_date: '2026-09-30', commit: 14700, closed_won: 14700 },
	{ owner_id: 'joris.bakker', as_of_date: '2026-09-30', commit: 26200, closed_won: 26200 },
]

describe('forecast accuracy', () => {
	it('sets each seller\'s closed-won against the first week\'s commit', () => {
		const rows = accuracyRows(q3)
		expect(rows.map((r) => [r.owner_id, r.weekOneCommit, r.actual, Math.round(r.score * 100)])).toEqual([
			['pieter', 52000, 49500, 95],
			['fatma.yildiz', 21000, 14700, 70],
			['joris.bakker', 25000, 26200, 105],
		])
	})

	it('leaves out a seller with only one snapshot or no first-week commit', () => {
		expect(accuracyRows([{ owner_id: 'x', as_of_date: '2026-07-01', commit: 10, closed_won: 0 }])).toEqual([])
		expect(accuracyRows([
			{ owner_id: 'y', as_of_date: '2026-07-01', commit: 0, closed_won: 0 },
			{ owner_id: 'y', as_of_date: '2026-09-30', commit: 5, closed_won: 5 },
		])).toEqual([])
	})

	it('names the quarter before', () => {
		expect(previousPeriodId('Q4-2026')).toBe('Q3-2026')
		expect(previousPeriodId('Q1-2027')).toBe('Q4-2026')
		expect(previousPeriodId('nonsense')).toBe('')
	})
})

describe('the forecast trend', () => {
	it('fills the accuracy table from the quarter before, by seller name', async () => {
		vi.resetModules()
		vi.doMock('../../src/services/forecastApi.js', () => ({
			fetchSnapshots: vi.fn(async ({ periodId }) => ({ snapshots: periodId.startsWith('Q') && periodId !== currentPeriod() ? q3 : [] })),
		}))
		vi.doMock('../../src/services/userDisplayName.js', () => ({
			fetchUserDisplayName: vi.fn(async (uid) => ({ pieter: 'Pieter Jansen', 'fatma.yildiz': 'Fatma Yildiz', 'joris.bakker': 'Joris Bakker' })[uid]),
		}))
		vi.doMock('@conduction/nextcloud-vue', () => ({ CnDetailCard: { template: '<div><slot /></div>' } }))
		vi.doMock('@nextcloud/vue', () => ({ NcEmptyContent: { template: '<div />' }, NcLoadingIcon: { template: '<div />' }, NcTextField: { template: '<input>' } }))
		const { default: ForecastTrend } = await import('../../src/views/forecast/ForecastTrend.vue')
		const wrapper = mount(ForecastTrend, {
			props: { embedded: true },
			global: { mocks: { t: (_app, text) => text }, stubs: {} },
		})
		await flushPromises()
		const rows = wrapper.findAll('.accuracy-table tbody tr').map((tr) => tr.findAll('td').map((td) => td.text()))
		expect(rows.map((r) => [r[0], r[3]])).toEqual([['Pieter Jansen', '95%'], ['Fatma Yildiz', '70%'], ['Joris Bakker', '105%']])
	}, 20000)
})

/**
 * The period id ForecastTrend reads as current.
 *
 * @return {string} The id.
 */
function currentPeriod() {
	const now = new Date()
	return 'Q' + (Math.floor(now.getMonth() / 3) + 1) + '-' + now.getFullYear()
}
