// @vitest-environment jsdom
/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 * SPDX-License-Identifier: EUPL-1.2
 *
 * How the forecast trend reads its snapshots: which period, level and owner
 * it asks for, the weekly bars, the commit moves and last quarter's accuracy
 * per seller, and what a failed read leaves behind. The snapshot and user
 * name reads are mocked; the forecast arithmetic runs for real.
 *
 * @spec openspec/specs/commercial-dashboard/spec.md
 */

import { flushPromises, mount } from '@vue/test-utils'
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import { h } from 'vue'
import { formatEur } from '../../src/services/commercialFormat.js'

const fetchSnapshots = vi.hoisted(() => vi.fn())
const fetchUserDisplayName = vi.hoisted(() => vi.fn())

vi.mock('../../src/services/forecastApi.js', () => ({ fetchSnapshots }))
vi.mock('../../src/services/userDisplayName.js', () => ({ fetchUserDisplayName }))
vi.mock('@conduction/nextcloud-vue', () => {
	const stub = (name) => ({
		name,
		inheritAttrs: false,
		render() {
			return h('div', { 'data-name': name }, this.$slots.default?.())
		},
	})
	return { CnDataTable: stub('CnDataTable'), CnDetailCard: stub('CnDetailCard') }
})
vi.mock('@nextcloud/vue', () => {
	const stub = (name) => ({
		name,
		inheritAttrs: false,
		render() {
			return h('div', { 'data-name': name }, this.$slots.default?.())
		},
	})
	return {
		NcEmptyContent: stub('NcEmptyContent'),
		NcLoadingIcon: stub('NcLoadingIcon'),
		NcTextField: stub('NcTextField'),
	}
})

function translate (_app, text, vars = {}) {
  return text.replace(/\{(\w+)\}/g, (_all, key) => vars[key] ?? `{${key}}`)
}
globalThis.t = translate

const { default: ForecastTrend } =
	await import('../../src/views/forecast/ForecastTrend.vue')

/**
 * Mount the trend.
 *
 * @param {object} props The props.
 * @return {object} The wrapper.
 */
function mountTrend(props = {}) {
	return mount(ForecastTrend, { props, global: { mocks: { t: translate } } })
}

const CURRENT = [
	{ as_of_date: '2026-05-04', owner_id: 'anna', commit: 1000, closed_won: 500 },
	{ as_of_date: '2026-05-04', owner_id: 'bob', commit: 3000, closed_won: 0 },
	{ as_of_date: '2026-05-11', owner_id: 'anna', commit: 4000, closed_won: 1000 },
	{ as_of_date: '2026-05-11', owner_id: 'bob', commit: 1000, closed_won: 0 },
]

const PREVIOUS = [
	{ as_of_date: '2026-01-05', owner_id: 'anna', commit: 1000, closed_won: 0 },
	{ as_of_date: '2026-03-30', owner_id: 'anna', commit: 0, closed_won: 950 },
	{ as_of_date: '2026-01-05', owner_id: 'solo', commit: 500, closed_won: 0 },
]

beforeEach(() => {
	vi.useFakeTimers({ toFake: ['Date'] })
	vi.setSystemTime(new Date(2026, 4, 15, 12))
	fetchSnapshots.mockReset()
	fetchUserDisplayName.mockReset()
	fetchUserDisplayName.mockImplementation(async (id) => id.toUpperCase())
	fetchSnapshots.mockImplementation(async ({ periodId }) => ({
		snapshots: periodId === 'Q2-2026' ? CURRENT : PREVIOUS,
	}))
})

afterEach(() => {
	vi.useRealTimers()
})

describe('ForecastTrend periods', () => {
	it('works in the current quarter and calls the one before it closed', () => {
		const wrapper = mountTrend()
		expect(wrapper.vm.periodId).toBe('Q2-2026')
		expect(wrapper.vm.accuracySubtitle).toBe('Quarter 1, closed')
	})
})

describe('ForecastTrend standalone', () => {
	it('does not load on mount and clears the series without an owner', async () => {
		const wrapper = mountTrend()
		await flushPromises()
		expect(fetchSnapshots).not.toHaveBeenCalled()
		wrapper.vm.series = [{ date: 'x' }]
		await wrapper.vm.loadTrend()
		expect(wrapper.vm.series).toEqual([])
		expect(fetchSnapshots).not.toHaveBeenCalled()
	})

	it('reads one owner at rep level for both quarters', async () => {
		const wrapper = mountTrend()
		wrapper.vm.ownerId = 'anna'
		await wrapper.vm.loadTrend()
		expect(fetchSnapshots).toHaveBeenNthCalledWith(1, {
			periodId: 'Q2-2026',
			level: 'rep',
			ownerId: 'anna',
			limit: 100,
		})
		expect(fetchSnapshots).toHaveBeenNthCalledWith(2, {
			periodId: 'Q1-2026',
			level: 'rep',
			ownerId: 'anna',
			limit: 200,
		})
		expect(wrapper.vm.loading).toBe(false)
	})
})

describe('ForecastTrend embedded', () => {
	it('loads at once for its level and sums the weeks into bars', async () => {
		const wrapper = mountTrend({ embedded: true, level: 'team' })
		await flushPromises()
		expect(fetchSnapshots).toHaveBeenNthCalledWith(1, {
			periodId: 'Q2-2026',
			level: 'team',
			ownerId: null,
			limit: 100,
		})
		expect(
			wrapper.vm.series.map((p) => [p.date, p.commit, p.closed_won]),
		).toEqual([
			['2026-05-04', 4000, 500],
			['2026-05-11', 5000, 1000],
		])
		expect(wrapper.vm.bars).toEqual([
			{
				date: '2026-05-04',
				percent: 75,
				week: 'w19',
				short: '5k',
				full: formatEur(4500),
			},
			{
				date: '2026-05-11',
				percent: 100,
				week: 'w20',
				short: '6k',
				full: formatEur(6000),
			},
		])
	})

	it('lists who moved into and out of commit, by display name', async () => {
		const wrapper = mountTrend({ embedded: true })
		await flushPromises()
		expect(wrapper.vm.movementRows).toEqual([
			{ id: 'anna', name: 'ANNA', direction: 'into', amount: 3000 },
			{ id: 'bob', name: 'BOB', direction: 'out', amount: 2000 },
		])
	})

	it('keeps the user id when the display name cannot be read', async () => {
		fetchUserDisplayName.mockRejectedValue(new Error('gone'))
		const wrapper = mountTrend({ embedded: true })
		await flushPromises()
		expect(wrapper.vm.movementRows.map((r) => r.name)).toEqual(['anna', 'bob'])
		expect(wrapper.vm.accuracyTableRows.map((r) => r.name)).toEqual(['anna'])
	})

	it('scores last quarter per seller with two snapshots and a first commit', async () => {
		const wrapper = mountTrend({ embedded: true })
		await flushPromises()
		expect(fetchSnapshots).toHaveBeenNthCalledWith(2, {
			periodId: 'Q1-2026',
			level: 'rep',
			ownerId: null,
			limit: 200,
		})
		expect(wrapper.vm.accuracyTableRows).toEqual([
			{
				id: 'anna',
				name: 'ANNA',
				weekOneCommit: 1000,
				actual: 950,
				score: 0.95,
			},
		])
		expect(wrapper.vm.bandFor(0.95)).toBe('green')
		expect(wrapper.vm.bandFor(0.8)).toBe('amber')
		expect(wrapper.vm.bandFor(0.5)).toBe('red')
	})

	it('reloads when the level changes', async () => {
		const wrapper = mountTrend({ embedded: true, level: 'rep' })
		await flushPromises()
		fetchSnapshots.mockClear()
		await wrapper.setProps({ level: 'org' })
		await flushPromises()
		expect(fetchSnapshots.mock.calls[0][0].level).toBe('org')
	})

	it('empties the series when the read fails', async () => {
		fetchSnapshots.mockRejectedValue(new Error('down'))
		const wrapper = mountTrend({ embedded: true })
		await flushPromises()
		expect(wrapper.vm.series).toEqual([])
		expect(wrapper.vm.loading).toBe(false)
	})

	it('empties only the accuracy table when last quarter fails', async () => {
		fetchSnapshots.mockImplementation(async ({ periodId }) => {
			if (periodId === 'Q1-2026') {
				throw new Error('down')
			}
			return { snapshots: CURRENT }
		})
		const wrapper = mountTrend({ embedded: true })
		wrapper.vm.accuracyRows = [{ owner_id: 'stale' }]
		await flushPromises()
		expect(wrapper.vm.accuracyRows).toEqual([])
		expect(wrapper.vm.series).toHaveLength(2)
	})

	it('treats a missing snapshot list as empty', async () => {
		fetchSnapshots.mockResolvedValue({})
		const wrapper = mountTrend({ embedded: true })
		await flushPromises()
		expect(wrapper.vm.series).toEqual([])
		expect(wrapper.vm.movements).toEqual([])
		expect(wrapper.vm.accuracyRows).toEqual([])
		expect(wrapper.vm.bars).toEqual([])
	})
})

describe('ForecastTrend tables and money', () => {
	it('offers sortable seller, movement and value columns', () => {
		const wrapper = mountTrend()
		expect(wrapper.vm.movementColumns.map((c) => c.key)).toEqual([
			'name',
			'direction',
			'amount',
		])
		expect(wrapper.vm.movementColumns[1].enumLabels).toEqual({
			into: 'Moved into commit',
			out: 'Moved out of commit',
		})
		expect(wrapper.vm.accuracyColumns.map((c) => c.key)).toEqual([
			'name',
			'weekOneCommit',
			'actual',
			'score',
		])
	})

	it('formats a missing amount as zero', () => {
		const wrapper = mountTrend()
		expect(wrapper.vm.formatMoney(undefined)).toBe(formatEur(0))
		expect(wrapper.vm.formatMoney('1250')).toBe(formatEur(1250))
	})
})
