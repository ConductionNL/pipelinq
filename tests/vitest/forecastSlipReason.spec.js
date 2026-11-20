// @vitest-environment jsdom
/**
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * The forecast trend lists the deals that slipped out of the quarter, each
 * with its slip reason (REQ-FRC-012-05), and the helpers behind that list.
 *
 * @spec openspec/changes/forecast-slip-reason/specs/forecast-roll-up-and-categories/spec.md#scenario-the-manager-reads-why-a-deal-slipped-req-frc-012-05
 */
import { flushPromises, mount } from '@vue/test-utils'
import { beforeEach, describe, expect, it, vi } from 'vitest'

const axiosGet = vi.fn()
const fetchSnapshots = vi.fn()

vi.mock('@nextcloud/axios', () => ({ default: { get: (...args) => axiosGet(...args) } }))
vi.mock('@nextcloud/router', () => ({ generateUrl: (url) => '/index.php' + url }))
vi.mock('../../src/services/forecastApi.js', () => ({ fetchSnapshots: (...args) => fetchSnapshots(...args) }))
vi.mock('../../src/services/userDisplayName.js', () => ({ fetchUserDisplayName: async (uid) => uid }))
vi.mock('../../src/services/commercialFormat.js', () => ({ formatEur: (value) => `EUR ${value}` }))
vi.mock('@conduction/nextcloud-vue', () => ({
	CnDataTable: {
		name: 'CnDataTable',
		props: ['rows', 'columns'],
		template: '<table><tr v-for="row in rows" :key="row.id" class="row"><td v-for="col in columns" :key="col.key" :class="\'cell-\' + col.key"><slot :name="\'column-\' + col.key" :row="row">{{ row[col.key] }}</slot></td></tr></table>',
	},
	CnDetailCard: { name: 'CnDetailCard', props: ['title', 'subtitle'], template: '<section :data-title="title"><slot /></section>' },
}))
vi.mock('@nextcloud/vue', () => ({
	NcEmptyContent: { name: 'NcEmptyContent', props: ['name'], template: '<p class="empty">{{ name }}</p>' },
	NcLoadingIcon: { name: 'NcLoadingIcon', template: '<i class="loading" />' },
	NcTextField: { name: 'NcTextField', props: ['modelValue'], emits: ['update:modelValue'], template: '<input class="rep">' },
}))

const { periodBounds, slippedDealRows, fetchSlippedDeals } = await import('../../src/services/forecastSlips.js')
const { default: ForecastTrend } = await import('../../src/views/forecast/ForecastTrend.vue')

/**
 * The period id the trend reads as current, worked out as the view does.
 *
 * @return {string} The id
 */
function currentPeriod() {
	const now = new Date()
	return 'Q' + (Math.floor(now.getMonth() / 3) + 1) + '-' + now.getFullYear()
}

const SLIPPED = {
	id: 'lead-1',
	title: 'Town hall roof',
	expectedCloseDate: '2026-07-15',
	slipped_from: '2026-06-20',
	slipped_at: '2026-06-18T09:00:00+00:00',
	slip_reason: 'Customer moved the board decision to July',
}

beforeEach(() => {
	axiosGet.mockReset()
	fetchSnapshots.mockReset()
	fetchSnapshots.mockResolvedValue({ snapshots: [] })
})

describe('periodBounds', () => {
	it('answers the first day of the quarter and the first day after it', () => {
		expect(periodBounds('Q2-2026')).toEqual({ start: '2026-04-01', end: '2026-07-01' })
		expect(periodBounds('Q4-2026')).toEqual({ start: '2026-10-01', end: '2027-01-01' })
	})

	it('answers null for an id it cannot read', () => {
		expect(periodBounds('2026-Q2')).toBeNull()
		expect(periodBounds('')).toBeNull()
	})
})

describe('slippedDealRows', () => {
	it('keeps a deal that slipped from a day in the quarter, with its reason and both dates', () => {
		expect(slippedDealRows([SLIPPED], 'Q2-2026')).toEqual([{
			id: 'lead-1',
			title: 'Town hall roof',
			slippedFrom: '2026-06-20',
			closeDate: '2026-07-15',
			reason: 'Customer moved the board decision to July',
			slippedAt: '2026-06-18T09:00:00+00:00',
		}])
	})

	it('leaves out a deal that slipped from another quarter or that carries no reason', () => {
		const otherQuarter = { ...SLIPPED, id: 'lead-2', slipped_from: '2026-03-31' }
		const noReason = { ...SLIPPED, id: 'lead-3', slip_reason: '  ' }
		const neverSlipped = { id: 'lead-4', title: 'Library', expectedCloseDate: '2026-05-01' }
		expect(slippedDealRows([otherQuarter, noReason, neverSlipped], 'Q2-2026')).toEqual([])
	})

	it('lists the latest slip first', () => {
		const earlier = { ...SLIPPED, id: 'lead-5', slipped_at: '2026-05-01T08:00:00+00:00' }
		expect(slippedDealRows([earlier, SLIPPED], 'Q2-2026').map((row) => row.id)).toEqual(['lead-1', 'lead-5'])
	})
})

describe('fetchSlippedDeals', () => {
	it('asks OpenRegister for the leads that slipped from a day in the quarter, for one seller', async () => {
		axiosGet.mockResolvedValue({ data: { results: [SLIPPED] } })

		const rows = await fetchSlippedDeals({ periodId: 'Q2-2026', ownerId: 'ann' })

		expect(axiosGet).toHaveBeenCalledTimes(1)
		const url = decodeURIComponent(axiosGet.mock.calls[0][0])
		expect(url.startsWith('/index.php/apps/openregister/api/objects/pipelinq/lead?')).toBe(true)
		expect(url).toContain('slipped_from[gte]=2026-04-01')
		expect(url).toContain('slipped_from[lt]=2026-07-01')
		expect(url).toContain('assignee=ann')
		expect(rows.map((row) => row.reason)).toEqual(['Customer moved the board decision to July'])
	})

	it('answers no rows when the read fails', async () => {
		axiosGet.mockRejectedValue(new Error('offline'))
		expect(await fetchSlippedDeals({ periodId: 'Q2-2026' })).toEqual([])
	})
})

describe('the forecast trend', () => {
	/**
	 * Mounts the embedded trend.
	 *
	 * @return {Promise<object>} The wrapper
	 */
	async function trend() {
		const wrapper = mount(ForecastTrend, {
			props: { embedded: true },
			global: { mocks: { t: (_app, text) => text } },
		})
		await flushPromises()
		return wrapper
	}

	it('shows each deal that slipped out of the quarter with its slip reason', async () => {
		const bounds = periodBounds(currentPeriod())
		axiosGet.mockResolvedValue({ data: { results: [{ ...SLIPPED, slipped_from: bounds.start }] } })

		const wrapper = await trend()

		const table = wrapper.find('[data-testid="forecast-slips"]')
		expect(table.exists()).toBe(true)
		expect(table.find('.cell-title').text()).toBe('Town hall roof')
		expect(table.find('.cell-reason').text()).toBe('Customer moved the board decision to July')
		expect(table.find('.cell-slippedFrom').text()).toBe(bounds.start)
	})

	it('says so when no deal slipped', async () => {
		axiosGet.mockResolvedValue({ data: { results: [] } })

		const wrapper = await trend()

		expect(wrapper.find('[data-testid="forecast-slips"]').exists()).toBe(false)
		expect(wrapper.text()).toContain('No commit or best-case deal slipped out of this quarter.')
	})
})
