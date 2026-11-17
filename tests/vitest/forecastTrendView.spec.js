// @vitest-environment jsdom
/**
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * The forecast trend as it renders: the snapshot bars, the week-over-week
 * commit movements, the accuracy table with its bands, the rep field of the
 * standalone page and what a failed load leaves.
 *
 * @spec exclude Client-side view rendering of the forecast trend.
 */
import { flushPromises, mount } from '@vue/test-utils'
import { beforeEach, describe, expect, it, vi } from 'vitest'

const fetchSnapshots = vi.fn()
const fetchUserDisplayName = vi.fn()

vi.mock('../../src/services/forecastApi.js', () => ({ fetchSnapshots: (...args) => fetchSnapshots(...args) }))
vi.mock('../../src/services/userDisplayName.js', () => ({ fetchUserDisplayName: (...args) => fetchUserDisplayName(...args) }))
vi.mock('../../src/services/commercialFormat.js', () => ({ formatEur: (value) => `EUR ${value}` }))
vi.mock('@conduction/nextcloud-vue', () => ({
	CnDataTable: {
		name: 'CnDataTable',
		props: ['rows', 'columns'],
		template: '<table><tr v-for="row in rows" :key="row.id" class="row"><td v-for="col in columns" :key="col.key" :class="\'cell-\' + col.key"><slot :name="\'column-\' + col.key" :row="row">{{ row[col.key] }}</slot></td></tr></table>',
	},
	CnDetailCard: { name: 'CnDetailCard', props: ['title', 'subtitle'], template: '<section :data-title="title" :data-subtitle="subtitle"><slot /></section>' },
}))
vi.mock('@nextcloud/vue', () => ({
	NcEmptyContent: { name: 'NcEmptyContent', props: ['name'], template: '<p class="empty">{{ name }}</p>' },
	NcLoadingIcon: { name: 'NcLoadingIcon', template: '<i class="loading" />' },
	NcTextField: { name: 'NcTextField', props: ['modelValue'], emits: ['update:modelValue'], template: '<input class="rep">' },
}))

const { default: ForecastTrend } = await import('../../src/views/forecast/ForecastTrend.vue')

/**
 * The period id the view reads as current, worked out as the view does.
 *
 * @return {string} The id
 */
function currentPeriod() {
	const now = new Date()
	return 'Q' + (Math.floor(now.getMonth() / 3) + 1) + '-' + now.getFullYear()
}

const CURRENT = [
	{ owner_id: 'ann', as_of_date: '2026-10-05', commit: 10000, closed_won: 2000 },
	{ owner_id: 'bob', as_of_date: '2026-10-05', commit: 5000, closed_won: 0 },
	{ owner_id: 'ann', as_of_date: '2026-10-12', commit: 14000, closed_won: 6000 },
	{ owner_id: 'bob', as_of_date: '2026-10-12', commit: 1000, closed_won: 0 },
]
const CLOSED = [
	{ owner_id: 'ann', as_of_date: '2026-07-06', commit: 20000, closed_won: 0 },
	{ owner_id: 'ann', as_of_date: '2026-09-28', commit: 19000, closed_won: 19000 },
	{ owner_id: 'bob', as_of_date: '2026-07-06', commit: 10000, closed_won: 0 },
	{ owner_id: 'bob', as_of_date: '2026-09-28', commit: 6000, closed_won: 6000 },
]

/**
 * Mounts the trend.
 *
 * @param {object} props The props
 * @return {Promise<object>} The wrapper
 */
async function trend(props = { embedded: true }) {
	const wrapper = mount(ForecastTrend, {
		props,
		global: { mocks: { t: (_app, text, vars) => text.replace(/\{(\w+)\}/g, (m, k) => (vars && k in vars ? vars[k] : m)) } },
	})
	await flushPromises()
	return wrapper
}

beforeEach(() => {
	fetchSnapshots.mockReset()
	fetchSnapshots.mockImplementation(async ({ periodId }) => ({ snapshots: periodId === currentPeriod() ? CURRENT : CLOSED }))
	fetchUserDisplayName.mockReset()
	fetchUserDisplayName.mockImplementation(async (uid) => ({ ann: 'Ann de Vries' })[uid] ?? Promise.reject(new Error('unknown')))
})

describe('the embedded forecast trend', () => {
	it('asks for this period at the given level, then the period before per rep', async () => {
		await trend({ embedded: true, level: 'team' })
		expect(fetchSnapshots).toHaveBeenNthCalledWith(1, { periodId: currentPeriod(), level: 'team', ownerId: null, limit: 100 })
		expect(fetchSnapshots).toHaveBeenNthCalledWith(2, expect.objectContaining({ level: 'rep', ownerId: null, limit: 200 }))
		expect(fetchSnapshots.mock.calls[1][0].periodId).not.toBe(currentPeriod())
	})

	it('draws one bar per snapshot date, scaled to the highest won plus commit', async () => {
		const wrapper = await trend()
		const bars = wrapper.findAll('.forecast-trend__bar')
		expect(bars.map((bar) => [
			bar.find('.forecast-trend__bar-value').text(),
			bar.find('.forecast-trend__bar-fill').attributes('style'),
			bar.find('.forecast-trend__bar-fill').attributes('title'),
			bar.find('.forecast-trend__bar-week').text(),
		])).toEqual([
			['17k', 'height: 81%;', 'EUR 17000', 'w41'],
			['21k', 'height: 100%;', 'EUR 21000', 'w42'],
		])
		expect(wrapper.find('.empty').exists()).toBe(false)
	})

	it('lists who moved into or out of commit, by name or user id', async () => {
		const wrapper = await trend()
		const table = wrapper.find('[data-testid="forecast-movements"]')
		expect(table.findAll('.row').map((row) => [
			row.find('.cell-name').text(),
			row.find('.forecast-trend__move').classes(),
			row.find('.forecast-trend__move').text(),
			row.find('.cell-amount').text(),
		])).toEqual([
			['Ann de Vries', ['forecast-trend__move', 'forecast-trend__move--into'], 'Moved into commit', 'EUR 4000'],
			['bob', ['forecast-trend__move', 'forecast-trend__move--out'], 'Moved out of commit', 'EUR 4000'],
		])
	})

	it('scores last quarter per rep and bands the score', async () => {
		const wrapper = await trend()
		const rows = wrapper.find('.accuracy-table').findAll('.row')
		expect(rows.map((row) => [
			row.find('.cell-name').text(),
			row.find('.cell-weekOneCommit').text(),
			row.find('.cell-actual').text(),
			row.find('.cell-score span').text(),
			row.find('.cell-score span').classes(),
		])).toEqual([
			['Ann de Vries', 'EUR 20000', 'EUR 19000', '95%', ['accuracy--green']],
			['bob', 'EUR 10000', 'EUR 6000', '60%', ['accuracy--red']],
		])
		const card = wrapper.findAll('section').find((s) => s.attributes('data-title') === 'Forecast accuracy')
		expect(card.attributes('data-subtitle')).toMatch(/^Quarter [1-4], closed$/)
	})

	it('says so when there are no snapshots, no movements and no closed period', async () => {
		fetchSnapshots.mockResolvedValue({})
		const wrapper = await trend()
		expect(wrapper.findAll('.forecast-trend__bar')).toHaveLength(0)
		expect(wrapper.findAll('.empty').map((e) => e.text())).toEqual(['Not enough snapshots', 'No closed periods yet'])
		expect(wrapper.find('.forecast-trend__none').text()).toBe('No commit moved since last week.')
	})

	it('shows an empty trend when loading fails', async () => {
		fetchSnapshots.mockRejectedValue(new Error('down'))
		const wrapper = await trend()
		expect(wrapper.vm.series).toEqual([])
		expect(wrapper.vm.loading).toBe(false)
		expect(wrapper.find('.loading').exists()).toBe(false)
		expect(wrapper.find('.empty').text()).toBe('Not enough snapshots')
	})

	it('keeps the trend when only the accuracy fails', async () => {
		fetchSnapshots.mockImplementation(async ({ periodId }) => {
			if (periodId === currentPeriod()) return { snapshots: CURRENT }
			throw new Error('down')
		})
		const wrapper = await trend()
		expect(wrapper.findAll('.forecast-trend__bar')).toHaveLength(2)
		expect(wrapper.vm.accuracyRows).toEqual([])
	})

	it('reloads when the level changes', async () => {
		const wrapper = await trend({ embedded: true, level: 'rep' })
		fetchSnapshots.mockClear()
		await wrapper.setProps({ level: 'org' })
		await flushPromises()
		expect(fetchSnapshots).toHaveBeenCalledWith(expect.objectContaining({ level: 'org', limit: 100 }))
	})

	it('shows the loading icon while the snapshots load', async () => {
		let release
		fetchSnapshots.mockReturnValue(new Promise((resolve) => { release = resolve }))
		const wrapper = mount(ForecastTrend, { props: { embedded: true }, global: { mocks: { t: (_a, text) => text } } })
		await wrapper.vm.$nextTick()
		expect(wrapper.find('.loading').exists()).toBe(true)
		release({ snapshots: [] })
		await flushPromises()
		expect(wrapper.find('.loading').exists()).toBe(false)
	})
})

describe('the standalone forecast trend', () => {
	it('loads nothing until a rep is entered', async () => {
		const wrapper = await trend({ embedded: false })
		expect(fetchSnapshots).not.toHaveBeenCalled()
		expect(wrapper.find('h2').text()).toBe('Forecast trend & accuracy')
		await wrapper.vm.loadTrend()
		expect(wrapper.vm.series).toEqual([])
		expect(fetchSnapshots).not.toHaveBeenCalled()
	})

	it('loads the entered rep\'s trend from the rep field', async () => {
		const wrapper = await trend({ embedded: false })
		const field = wrapper.findComponent({ name: 'NcTextField' })
		field.vm.$emit('update:modelValue', 'ann')
		await flushPromises()
		expect(wrapper.vm.ownerId).toBe('ann')
		expect(fetchSnapshots).toHaveBeenCalledWith({ periodId: currentPeriod(), level: 'rep', ownerId: 'ann', limit: 100 })
		expect(fetchSnapshots).toHaveBeenCalledWith(expect.objectContaining({ level: 'rep', ownerId: 'ann', limit: 200 }))
	})

	it('does not reload on a level change', async () => {
		const wrapper = await trend({ embedded: false, level: 'rep' })
		await wrapper.setProps({ level: 'org' })
		await flushPromises()
		expect(fetchSnapshots).not.toHaveBeenCalled()
	})
})
