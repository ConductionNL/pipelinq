// @vitest-environment jsdom
/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 * SPDX-License-Identifier: EUPL-1.2
 *
 * The lead analytics section of the rapportage page: one fetch of the
 * pipeline stats shared by its four widgets, the empty defaults each widget
 * gets, the date range the win/loss widget sends back, and the error toast
 * of a failed fetch.
 *
 * @spec openspec/specs/contactmomenten-rapportage/spec.md#requirement-kpi-dashboard
 */

import { flushPromises, mount } from '@vue/test-utils'
import { beforeEach, describe, expect, it, vi } from 'vitest'
import { h } from 'vue'

const axiosMock = vi.hoisted(() => ({ get: vi.fn() }))
const dialogs = vi.hoisted(() => ({ showError: vi.fn() }))

/**
 * A widget stub that keeps its `data` prop and can emit a range change.
 *
 * @param {string} name The widget name.
 * @return {object} A module with the stub as default export.
 */
function widget(name) {
	return {
		default: {
			name,
			props: ['data'],
			emits: ['rangeChange'],
			render: () => h('div', { class: name }),
		},
	}
}

vi.mock('@nextcloud/axios', () => ({ default: axiosMock }))
vi.mock('@nextcloud/dialogs', () => dialogs)
vi.mock('@nextcloud/router', () => ({ generateUrl: (path) => '/index.php' + path }))
vi.mock('@nextcloud/vue', () => ({
	NcLoadingIcon: { name: 'NcLoadingIcon', render: () => h('div', { class: 'loading-stub' }) },
}))
vi.mock('../../src/views/rapportage/LeadAgingWidget.vue', () => widget('LeadAgingWidget'))
vi.mock('../../src/views/rapportage/PipelineFunnelWidget.vue', () => widget('PipelineFunnelWidget'))
vi.mock('../../src/views/rapportage/SourcePerformanceWidget.vue', () => widget('SourcePerformanceWidget'))
vi.mock('../../src/views/rapportage/WinLossWidget.vue', () => widget('WinLossWidget'))

globalThis.t = (app, text) => text
const mountOptions = { global: { mocks: { t: globalThis.t } } }

const { default: LeadAnalyticsSection } =
	await import('../../src/components/rapportage/LeadAnalyticsSection.vue')

const URL = '/index.php/apps/pipelinq/api/rapportage/pipeline-stats'

/**
 * The `data` prop each widget received.
 *
 * @param {import('@vue/test-utils').VueWrapper} wrapper The section.
 * @return {object} Widget name to data.
 */
function widgetData(wrapper) {
	const out = {}
	for (const name of ['PipelineFunnelWidget', 'SourcePerformanceWidget', 'LeadAgingWidget', 'WinLossWidget']) {
		out[name] = wrapper.findComponent({ name }).props('data')
	}
	return out
}

beforeEach(() => {
	axiosMock.get.mockReset()
	dialogs.showError.mockReset()
})

describe('LeadAnalyticsSection', () => {
	it('shows a loader during the single stats fetch', async () => {
		let resolve
		axiosMock.get.mockReturnValueOnce(new Promise((r) => { resolve = r }))
		const wrapper = mount(LeadAnalyticsSection, mountOptions)
		await wrapper.vm.$nextTick()

		expect(wrapper.find('.loading-stub').exists()).toBe(true)
		expect(axiosMock.get).toHaveBeenCalledTimes(1)
		expect(axiosMock.get).toHaveBeenCalledWith(URL, { params: {} })

		resolve({ data: {} })
		await flushPromises()
		expect(wrapper.find('.loading-stub').exists()).toBe(false)
		expect(wrapper.findAll('h3').map((h3) => h3.text()))
			.toEqual(['Pipeline funnel', 'Source performance', 'Lead aging', 'Win/loss'])
	})

	it('hands each widget its slice of the stats', async () => {
		const stats = {
			stageValues: [{ stage: 'new', value: 3 }],
			sourcePerformance: [{ source: 'web' }],
			agingBuckets: [{ bucket: '0-7' }],
			winLoss: { won: 2, lost: 1 },
		}
		axiosMock.get.mockResolvedValueOnce({ data: stats })
		const wrapper = mount(LeadAnalyticsSection, mountOptions)
		await flushPromises()

		expect(widgetData(wrapper)).toEqual({
			PipelineFunnelWidget: stats.stageValues,
			SourcePerformanceWidget: stats.sourcePerformance,
			LeadAgingWidget: stats.agingBuckets,
			WinLossWidget: stats.winLoss,
		})
	})

	it('gives the widgets empty defaults for missing stats or no body', async () => {
		axiosMock.get.mockResolvedValueOnce({})
		const wrapper = mount(LeadAnalyticsSection, mountOptions)
		await flushPromises()

		expect(wrapper.vm.stats).toEqual({})
		expect(widgetData(wrapper)).toEqual({
			PipelineFunnelWidget: [],
			SourcePerformanceWidget: [],
			LeadAgingWidget: [],
			WinLossWidget: {},
		})
	})

	it('refetches with the range the win/loss widget picks', async () => {
		axiosMock.get.mockResolvedValue({ data: {} })
		const wrapper = mount(LeadAnalyticsSection, mountOptions)
		await flushPromises()

		wrapper.findComponent({ name: 'WinLossWidget' }).vm.$emit('rangeChange', { from: '2026-01-01', to: '2026-03-31' })
		await flushPromises()

		expect(wrapper.vm.dateRange).toEqual({ from: '2026-01-01', to: '2026-03-31' })
		expect(axiosMock.get).toHaveBeenLastCalledWith(URL, { params: { dateFrom: '2026-01-01', dateTo: '2026-03-31' } })
	})

	it('sends only the ends of the range that are set, and none for a cleared range', async () => {
		axiosMock.get.mockResolvedValue({ data: {} })
		const wrapper = mount(LeadAnalyticsSection, mountOptions)
		await flushPromises()

		wrapper.vm.onRangeChange({ from: '2026-02-01' })
		await flushPromises()
		expect(axiosMock.get).toHaveBeenLastCalledWith(URL, { params: { dateFrom: '2026-02-01' } })

		wrapper.vm.onRangeChange({ to: '2026-02-28' })
		await flushPromises()
		expect(axiosMock.get).toHaveBeenLastCalledWith(URL, { params: { dateTo: '2026-02-28' } })

		wrapper.vm.onRangeChange(null)
		await flushPromises()
		expect(axiosMock.get).toHaveBeenLastCalledWith(URL, { params: {} })
	})

	it('toasts the failure and clears the stats', async () => {
		axiosMock.get.mockResolvedValueOnce({ data: { stageValues: [1] } })
		const wrapper = mount(LeadAnalyticsSection, mountOptions)
		await flushPromises()
		axiosMock.get.mockRejectedValueOnce(new Error('500'))

		wrapper.vm.onRangeChange(null)
		await flushPromises()

		expect(dialogs.showError).toHaveBeenCalledWith('Failed to load lead analytics. Please try again.')
		expect(wrapper.vm.stats).toEqual({})
		expect(wrapper.vm.loading).toBe(false)
	})
})
