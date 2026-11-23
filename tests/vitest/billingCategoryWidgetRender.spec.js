// @vitest-environment jsdom
/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 * SPDX-License-Identifier: EUPL-1.2
 *
 * The billing-category donut on the dashboard, mounted: categories matched by
 * id, uuid or slug, the series it hands the chart, the empty state when no
 * hours are recorded, a failed time-entry fetch, and where a segment click
 * navigates.
 *
 * @spec openspec/specs/time-registration/spec.md
 */

import { flushPromises, mount } from '@vue/test-utils'
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import { h } from 'vue'

const categoryStore = vi.hoisted(() => ({ categories: [], fetchCategories: vi.fn() }))
const stores = vi.hoisted(() => ({ initializeStores: vi.fn() }))

vi.mock('@conduction/nextcloud-vue', () => ({
	CnChartWidget: {
		name: 'CnChartWidget',
		props: ['type', 'title', 'labels', 'series', 'colors'],
		emits: ['segmentClick'],
		render: () => h('div', { class: 'chart-stub' }),
	},
}))
vi.mock('@nextcloud/vue', () => ({
	NcEmptyContent: { name: 'NcEmptyContent', props: ['name', 'description'], render() { return h('div', { class: 'empty-stub' }, this.name) } },
}))
vi.mock('@nextcloud/router', () => ({ generateUrl: (path) => '/index.php' + path }))
vi.mock('../../src/services/requestHeaders.js', () => ({ jsonRequestHeaders: () => ({ Accept: 'application/json' }) }))
vi.mock('../../src/store/modules/billingCategory.js', () => ({ useBillingCategoryStore: () => categoryStore }))
vi.mock('../../src/store/store.js', () => stores)

globalThis.t = (app, text) => text

const { default: BillingCategoryWidget } = await import('../../src/components/dashboard/BillingCategoryWidget.vue')

const TIME_ENTRY = { register: 'pipelinq', schema: 'timeEntry' }

/**
 * Mount the widget over the given categories, registry and fetch answer.
 *
 * @param {object} opts The setup.
 * @param {Array} [opts.categories] Billing categories in the store.
 * @param {object} [opts.registry] The object type registry.
 * @param {object} [opts.response] What fetch resolves with.
 * @return {Promise<object>} The settled wrapper and its router.
 */
async function mountWidget({ categories = [], registry = { timeEntry: TIME_ENTRY }, response = { ok: true, json: async () => ({ results: [] }) } } = {}) {
	categoryStore.categories = categories
	stores.initializeStores.mockResolvedValue({ objectStore: { objectTypeRegistry: registry } })
	vi.stubGlobal('fetch', vi.fn(async () => response))
	const router = { push: vi.fn(() => Promise.resolve()) }
	const wrapper = mount(BillingCategoryWidget, { global: { mocks: { t: globalThis.t, $router: router } } })
	await flushPromises()
	return { wrapper, router }
}

const entries = (results) => ({ ok: true, json: async () => ({ results }) })

beforeEach(() => {
	categoryStore.fetchCategories.mockReset()
	stores.initializeStores.mockReset()
})

afterEach(() => {
	vi.unstubAllGlobals()
	vi.restoreAllMocks()
})

describe('BillingCategoryWidget', () => {
	it('fetches the categories and up to 500 time entries of the registered type', async () => {
		await mountWidget()

		expect(categoryStore.fetchCategories).toHaveBeenCalledTimes(1)
		expect(fetch).toHaveBeenCalledWith(
			'/index.php/apps/openregister/api/objects/pipelinq/timeEntry?_limit=500',
			{ headers: { Accept: 'application/json' } },
		)
	})

	it('matches categories by id, uuid or slug and hands the chart labels, hours and colours', async () => {
		const { wrapper } = await mountWidget({
			categories: [
				{ id: 'cat-dev', name: 'Development', color: '#112233' },
				{ name: 'Support', '@self': { uuid: 'uuid-sup' } },
				{ name: 'Travel', color: '#445566', '@self': { slug: 'travel' } },
			],
			response: entries([
				{ billingCategory: 'cat-dev', hours: 2.5 },
				{ billingCategory: 'uuid-sup', hours: '1.25' },
				{ billingCategory: 'travel', hours: 1 },
				{ hours: 0.5 },
			]),
		})

		const chart = wrapper.findComponent({ name: 'CnChartWidget' })
		expect(chart.props('type')).toBe('donut')
		expect(chart.props('title')).toBe('Hours by billing category')
		expect(chart.props('labels')).toEqual(['Development', 'Support', 'Travel', 'Uncategorized'])
		expect(chart.props('series')).toEqual([2.5, 1.25, 1, 0.5])
		expect(chart.props('colors')).toEqual(['#112233', '#6c757d', '#445566', '#6c757d'])
		expect(wrapper.find('.empty-stub').exists()).toBe(false)
	})

	it('accepts a bare array answer', async () => {
		const { wrapper } = await mountWidget({ response: { ok: true, json: async () => [{ hours: 3 }] } })

		expect(wrapper.findComponent({ name: 'CnChartWidget' }).props('series')).toEqual([3])
	})

	it('shows the empty state when every entry has zero hours', async () => {
		const { wrapper } = await mountWidget({ response: entries([{ billingCategory: 'x', hours: 0 }]) })

		expect(wrapper.find('.empty-stub').text()).toBe('No hours recorded')
		expect(wrapper.find('.chart-stub').exists()).toBe(false)
	})

	it('keeps no entries when the time-entry fetch is refused', async () => {
		const { wrapper } = await mountWidget({ response: { ok: false, json: async () => ({ results: [{ hours: 9 }] }) } })

		expect(wrapper.vm.timeEntries).toEqual([])
		expect(wrapper.find('.empty-stub').exists()).toBe(true)
	})

	it('fetches no entries when the time-entry type is not registered', async () => {
		const { wrapper } = await mountWidget({ registry: {} })

		expect(fetch).not.toHaveBeenCalled()
		expect(wrapper.vm.loading).toBe(false)
		expect(wrapper.find('.empty-stub').exists()).toBe(true)
	})

	it('logs a failed load and stops loading', async () => {
		const error = vi.spyOn(console, 'error').mockImplementation(() => {})
		categoryStore.fetchCategories.mockRejectedValueOnce(new Error('offline'))

		const { wrapper } = await mountWidget()

		expect(error).toHaveBeenCalledWith('BillingCategoryWidget load error:', expect.objectContaining({ message: 'offline' }))
		expect(wrapper.vm.loading).toBe(false)
	})

	it('opens the time entries of a clicked category, or of no category for the uncategorised segment', async () => {
		const { wrapper, router } = await mountWidget({
			categories: [{ id: 'cat-dev', name: 'Development' }],
			response: entries([{ billingCategory: 'cat-dev', hours: 1 }, { hours: 2 }]),
		})
		const chart = wrapper.findComponent({ name: 'CnChartWidget' })

		chart.vm.$emit('segmentClick', 0)
		chart.vm.$emit('segmentClick', 1)
		chart.vm.$emit('segmentClick', 7)

		expect(router.push.mock.calls).toEqual([
			[{ path: '/time-entries', query: { billingCategory: 'cat-dev' } }],
			[{ path: '/time-entries', query: { billingCategory: 'null' } }],
		])
	})

	it('swallows a rejected navigation', async () => {
		const { wrapper, router } = await mountWidget({ response: entries([{ billingCategory: 'x', hours: 1 }, { hours: 1 }]) })
		router.push.mockReturnValue(Promise.reject(new Error('NavigationDuplicated')))

		wrapper.vm.onSegmentClick(0)
		wrapper.vm.onSegmentClick(1)
		await flushPromises()

		expect(router.push).toHaveBeenCalledTimes(2)
	})
})
