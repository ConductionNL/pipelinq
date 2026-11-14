// @vitest-environment jsdom
/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 * SPDX-License-Identifier: EUPL-1.2
 *
 * The forecast trend card: weekly bars of won plus committed scaled to the
 * best week, the week's commit movements named by seller (falling back to the
 * user id), the rep field that loads nothing until a rep is entered, a failed
 * read that empties the chart, and an embedded card that reloads when the
 * page switches level.
 *
 * @spec openspec/specs/commercial-dashboard/spec.md
 */
import { flushPromises, mount } from '@vue/test-utils'
import { beforeEach, describe, expect, it, vi } from 'vitest'
import { h } from 'vue'
import { formatEur } from '../../src/services/commercialFormat.js'

const fetchSnapshots = vi.fn()
const fetchUserDisplayName = vi.fn()
vi.mock('../../src/services/forecastApi.js', () => ({
	fetchSnapshots: (...args) => fetchSnapshots(...args),
}))
vi.mock('../../src/services/userDisplayName.js', () => ({
	fetchUserDisplayName: (...args) => fetchUserDisplayName(...args),
}))
vi.mock('@conduction/nextcloud-vue', () => ({
	CnDetailCard: {
		name: 'CnDetailCard',
		props: ['title', 'subtitle'],
		render() {
			return h('section', this.$slots.default?.())
		},
	},
	CnDataTable: {
		name: 'CnDataTable',
		props: ['columns', 'rows'],
		render() {
			const slots = this.$slots
			return h(
				'table',
				(this.rows || []).map((row) =>
					h(
						'tr',
						(this.columns || []).map((c) =>
							h(
								'td',
								slots['column-' + c.key]?.({ row }) ?? row[c.key],
							),
						),
					),
				),
			)
		},
	},
}))
vi.mock('@nextcloud/vue', () => ({
	NcEmptyContent: {
		name: 'NcEmptyContent',
		props: ['name'],
		render() {
			return h('div', { class: 'empty' }, this.name)
		},
	},
	NcLoadingIcon: {
		name: 'NcLoadingIcon',
		render: () => h('div', { class: 'loading' }),
	},
	NcTextField: {
		name: 'NcTextField',
		props: ['modelValue'],
		emits: ['update:modelValue'],
		render: () => h('input'),
	},
}))

function t(app, text, vars) {
	return text.replace(/\{(\w+)\}/g, (m, k) => (vars && k in vars ? vars[k] : m))
}
globalThis.t = t
const global = { mocks: { t } }

const { default: ForecastTrend } =
	await import('../../src/views/forecast/ForecastTrend.vue')

/**
 * The period id the card reads as current, computed the way it does.
 *
 * @return {string} The id.
 */
function currentPeriod() {
	const now = new Date()
	return 'Q' + (Math.floor(now.getMonth() / 3) + 1) + '-' + now.getFullYear()
}

const weeks = [
	{ owner_id: 'ann', as_of_date: '2026-01-05', commit: 20000, closed_won: 0 },
	{ owner_id: 'bob', as_of_date: '2026-01-05', commit: 10000, closed_won: 0 },
	{ owner_id: 'ann', as_of_date: '2026-01-12', commit: 35000, closed_won: 25000 },
	{ owner_id: 'bob', as_of_date: '2026-01-12', commit: 4000, closed_won: 0 },
]

describe('the forecast trend card', () => {
	beforeEach(() => {
		fetchSnapshots.mockReset()
		fetchUserDisplayName.mockReset()
	})

	it('draws one bar per week, scaled to the best week', async () => {
		fetchSnapshots.mockImplementation(async ({ periodId }) => ({
			snapshots: periodId === currentPeriod() ? weeks : [],
		}))
		fetchUserDisplayName.mockImplementation(async (uid) => {
			if (uid === 'bob') {
				throw new Error('unknown')
			}
			return 'Ann Smit'
		})
		const wrapper = mount(ForecastTrend, {
			props: { embedded: true, level: 'team' },
			global,
		})
		await flushPromises()

		expect(fetchSnapshots).toHaveBeenNthCalledWith(1, {
			periodId: currentPeriod(),
			level: 'team',
			ownerId: null,
			limit: 100,
		})
		// 30000 then 64000: the second week is the top.
		expect(wrapper.vm.bars).toEqual([
			{
				date: '2026-01-05',
				percent: 47,
				week: 'w2',
				short: '30k',
				full: formatEur(30000),
			},
			{
				date: '2026-01-12',
				percent: 100,
				week: 'w3',
				short: '64k',
				full: formatEur(64000),
			},
		])
		const fills = wrapper.findAll('.forecast-trend__bar-fill')
		expect(fills.map((f) => f.attributes('style'))).toEqual([
			'height: 47%;',
			'height: 100%;',
		])
		expect(wrapper.find('h2').exists()).toBe(false)
	})

	it('lists the commit movements by seller, the largest first', async () => {
		fetchSnapshots.mockImplementation(async ({ periodId }) => ({
			snapshots: periodId === currentPeriod() ? weeks : [],
		}))
		fetchUserDisplayName.mockImplementation(async (uid) => {
			if (uid === 'bob') {
				throw new Error('unknown')
			}
			return 'Ann Smit'
		})
		const wrapper = mount(ForecastTrend, { props: { embedded: true }, global })
		await flushPromises()

		expect(wrapper.vm.movementRows).toEqual([
			{ id: 'ann', name: 'Ann Smit', direction: 'into', amount: 15000 },
			{ id: 'bob', name: 'bob', direction: 'out', amount: 6000 },
		])
		const cells = wrapper
			.findAll('[data-testid="forecast-movements"] tr')
			.map((tr) => tr.findAll('td').map((td) => td.text()))
		expect(cells).toEqual([
			['Ann Smit', 'Moved into commit', formatEur(15000)],
			['bob', 'Moved out of commit', formatEur(6000)],
		])
	})

	it('says nothing moved and no period closed when there is one week', async () => {
		fetchSnapshots.mockResolvedValue({ snapshots: [weeks[0]] })
		const wrapper = mount(ForecastTrend, { props: { embedded: true }, global })
		await flushPromises()

		expect(wrapper.find('.forecast-trend__none').text()).toBe(
			'No commit moved since last week.',
		)
		expect(
			wrapper
				.findAllComponents({ name: 'NcEmptyContent' })
				.map((c) => c.props('name')),
		).toEqual(['No closed periods yet'])
	})

	it('shows the accuracy score band and percentage per rep', async () => {
		const previous = [
			{
				owner_id: 'ann',
				as_of_date: '2025-10-06',
				commit: 40000,
				closed_won: 0,
			},
			{
				owner_id: 'ann',
				as_of_date: '2025-12-29',
				commit: 0,
				closed_won: 30000,
			},
		]
		fetchSnapshots.mockImplementation(async ({ periodId }) => ({
			snapshots: periodId === currentPeriod() ? [] : previous,
		}))
		fetchUserDisplayName.mockResolvedValue('Ann Smit')
		const wrapper = mount(ForecastTrend, { props: { embedded: true }, global })
		await flushPromises()

		const score = wrapper.find('.accuracy-table [class^="accuracy--"]')
		expect(score.text()).toBe('75%')
		expect(score.classes()).toEqual(['accuracy--amber'])
		expect(wrapper.vm.accuracySubtitle).toMatch(/^Quarter [1-4], closed$/)
		expect(
			wrapper
				.findAllComponents({ name: 'NcEmptyContent' })
				.map((c) => c.props('name')),
		).toEqual(['Not enough snapshots'])
	})

	it('loads nothing on its own page until a rep is entered, then reads that rep', async () => {
		fetchSnapshots.mockResolvedValue({ snapshots: weeks })
		fetchUserDisplayName.mockResolvedValue('x')
		const wrapper = mount(ForecastTrend, { global })
		await flushPromises()
		expect(fetchSnapshots).not.toHaveBeenCalled()
		expect(wrapper.find('h2').text()).toBe('Forecast trend & accuracy')

		const field = wrapper.findComponent({ name: 'NcTextField' })
		await field.vm.$emit('update:modelValue', 'ann')
		await flushPromises()
		expect(fetchSnapshots).toHaveBeenNthCalledWith(1, {
			periodId: currentPeriod(),
			level: 'rep',
			ownerId: 'ann',
			limit: 100,
		})
		expect(fetchSnapshots.mock.calls[1][0]).toMatchObject({
			level: 'rep',
			ownerId: 'ann',
			limit: 200,
		})
		expect(wrapper.vm.series).toHaveLength(2)

		await field.vm.$emit('update:modelValue', '')
		await flushPromises()
		expect(wrapper.vm.series).toEqual([])
		expect(fetchSnapshots).toHaveBeenCalledTimes(2)
	})

	it('empties the chart when the read fails and stops loading', async () => {
		fetchSnapshots.mockRejectedValue(new Error('down'))
		const wrapper = mount(ForecastTrend, { props: { embedded: true }, global })
		await flushPromises()
		expect(wrapper.vm.series).toEqual([])
		expect(wrapper.vm.loading).toBe(false)
		expect(wrapper.find('.loading').exists()).toBe(false)
	})

	it('reloads when the embedding page switches level, and only when embedded', async () => {
		fetchSnapshots.mockResolvedValue({ snapshots: [] })
		const embedded = mount(ForecastTrend, {
			props: { embedded: true, level: 'rep' },
			global,
		})
		await flushPromises()
		await embedded.setProps({ level: 'team' })
		await flushPromises()
		const levels = fetchSnapshots.mock.calls
			.filter((c) => c[0].limit === 100)
			.map((c) => c[0].level)
		expect(levels).toEqual(['rep', 'team'])

		fetchSnapshots.mockClear()
		const standalone = mount(ForecastTrend, { props: { level: 'rep' }, global })
		await standalone.setProps({ level: 'team' })
		await flushPromises()
		expect(fetchSnapshots).not.toHaveBeenCalled()
	})
})
