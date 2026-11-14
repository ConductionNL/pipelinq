// @vitest-environment jsdom
/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 * SPDX-License-Identifier: EUPL-1.2
 *
 * The social performance page: one request fills the ranking, a failed one
 * says so, every row is flattened to the numbers its columns show, and the
 * rows sort by any column or second-line value in either direction, with a
 * post that has no engagement rate yet always last.
 *
 * @spec openspec/specs/social-metrics/spec.md#requirement-posts-are-ranked-by-engagement-rate-per-network
 */
import { flushPromises, mount } from '@vue/test-utils'
import { beforeEach, describe, expect, it, vi } from 'vitest'
import { h } from 'vue'

const api = vi.hoisted(() => ({ fetchPerformance: vi.fn() }))
vi.mock('../../src/services/socialApi.js', () => api)

vi.mock('@conduction/nextcloud-vue', () => ({
	CnDataTable: { name: 'CnDataTable', props: ['columns', 'rows', 'loading', 'sortKey', 'sortOrder', 'emptyText', 'rowKey'], emits: ['sort'], render: () => h('div') },
}))
vi.mock('@nextcloud/vue', () => ({
	NcNoteCard: { name: 'NcNoteCard', props: ['type'], render() { return h('div', { class: 'note' }, this.$slots.default?.()) } },
	NcSelect: { name: 'NcSelect', props: ['modelValue', 'options'], emits: ['update:modelValue'], render: () => h('div') },
}))

globalThis.t = (_app, text, vars = {}) => String(text).replace(/\{(\w+)\}/g, (whole, key) => vars[key] ?? whole)

const { default: SocialPerformanceView } = await import('../../src/views/social/SocialPerformanceView.vue')

const ranking = [
	{ publicationId: 'a', network: 'x', publishedAt: '2026-03-01T10:00:00Z', followerCount: 50, engagementRate: 0.02, metrics: { views: 300, likes: 4, comments: 1, shares: 9 } },
	{ publicationId: 'b', network: 'bluesky', publishedAt: '2026-01-01T10:00:00Z', followerCount: 900, engagementRate: 0.08, metrics: { views: 100, likes: 7 } },
	{ publicationId: 'c', network: 'mastodon', followerCount: 10, metrics: {} },
	{ publicationId: 'd', network: 'linkedin', publishedAt: '2026-02-01T10:00:00Z', engagementRate: 0.05 },
]

/**
 * Mount the page once the ranking request has answered.
 *
 * @return {Promise<object>} The wrapper.
 */
async function loaded() {
	const wrapper = mount(SocialPerformanceView, { global: { mocks: { t: globalThis.t } } })
	await flushPromises()
	return wrapper
}

/**
 * @param {object} wrapper The page.
 * @return {Array<string>} The row ids in table order.
 */
function order(wrapper) {
	return wrapper.findComponent({ name: 'CnDataTable' }).props('rows').map((row) => row.id)
}

beforeEach(() => {
	api.fetchPerformance.mockReset()
	api.fetchPerformance.mockResolvedValue(ranking)
})

describe('loading', () => {
	it('asks once, and flattens each row to the values its columns show', async () => {
		const wrapper = await loaded()

		expect(api.fetchPerformance).toHaveBeenCalledTimes(1)
		expect(wrapper.vm.loading).toBe(false)
		const rows = wrapper.vm.tableRows
		expect(rows[0]).toEqual({ id: 'a', network: 'x', publishedAt: '2026-03-01T10:00:00Z', views: 300, likes: 4, comments: 1, shares: 9, followerCount: 50, engagementRate: 0.02, source: ranking[0] })
		expect(rows[2]).toEqual({ id: 'c', network: 'mastodon', publishedAt: '', views: 0, likes: 0, comments: 0, shares: 0, followerCount: 10, engagementRate: null, source: ranking[2] })
		expect(rows[3].followerCount).toBe(0)
	})

	it('says the numbers could not be loaded when the request fails', async () => {
		api.fetchPerformance.mockRejectedValueOnce(new Error('500'))
		const wrapper = await loaded()

		expect(wrapper.vm.loading).toBe(false)
		expect(wrapper.vm.rows).toEqual([])
		expect(wrapper.find('.note').text()).toBe('The numbers could not be loaded.')
	})
})

describe('sorting', () => {
	it('starts on the highest engagement rate, with a post without a rate last', async () => {
		expect(order(await loaded())).toEqual(['b', 'd', 'a', 'c'])
	})

	it('keeps a post without a rate last in ascending order too', async () => {
		const wrapper = await loaded()
		wrapper.vm.onSort({ key: 'engagementRate', order: 'asc' })
		await flushPromises()

		expect(order(wrapper)).toEqual(['a', 'd', 'b', 'c'])
	})

	it('sorts the network column by its label, not its key', async () => {
		const wrapper = await loaded()
		wrapper.vm.onSort({ key: 'network', order: 'asc' })
		await flushPromises()

		// Bluesky, LinkedIn, Mastodon, X
		expect(order(wrapper)).toEqual(['b', 'd', 'c', 'a'])
	})

	it('sorts by a second-line value offered in the sort menu', async () => {
		const wrapper = await loaded()
		wrapper.findAllComponents({ name: 'NcSelect' })[0].vm.$emit('update:modelValue', 'followerCount')
		await flushPromises()

		expect([wrapper.vm.sortKey, wrapper.vm.sortOrder]).toEqual(['followerCount', 'desc'])
		expect(order(wrapper)).toEqual(['b', 'a', 'c', 'd'])
	})

	it('flips the direction from the order menu', async () => {
		const wrapper = await loaded()
		wrapper.vm.onSort({ key: 'views', order: 'desc' })
		wrapper.findAllComponents({ name: 'NcSelect' })[1].vm.$emit('update:modelValue', 'asc')
		await flushPromises()

		expect(order(wrapper)).toEqual(['c', 'd', 'b', 'a'])
	})

	it('falls back to the server order without a key, and to ascending without an order', async () => {
		const wrapper = await loaded()
		wrapper.findComponent({ name: 'CnDataTable' }).vm.$emit('sort', {})
		await flushPromises()

		expect([wrapper.vm.sortKey, wrapper.vm.sortOrder]).toEqual([null, 'asc'])
		expect(order(wrapper)).toEqual(['a', 'b', 'c', 'd'])
	})

	it('offers every column, plus the publication date and follower count after their columns', async () => {
		const wrapper = await loaded()

		expect(wrapper.vm.sortOptions.map((option) => option.value)).toEqual([
			'network', 'publishedAt', 'views', 'likes', 'comments', 'shares', 'followerCount', 'engagementRate',
		])
		expect(wrapper.vm.orderOptions.map((option) => option.value)).toEqual(['asc', 'desc'])
	})
})

describe('cells', () => {
	it('shows the follower count under the rate and the rate as a percentage or a hyphen', async () => {
		const wrapper = await loaded()
		const rateColumn = wrapper.vm.columns.find((column) => column.key === 'engagementRate')

		expect(rateColumn.secondary({ followerCount: 900 })).toBe('Followers: 900')
		expect(wrapper.vm.rate({ engagementRate: 0.0812 })).toBe('8.12%')
		expect(wrapper.vm.rate({ engagementRate: null })).toBe('-')
	})

	it('shows an unreadable publication date as stored, and a missing one as empty', async () => {
		const wrapper = await loaded()

		expect(wrapper.vm.formatDate('not a date')).toBe('not a date')
		expect(wrapper.vm.formatDate('')).toBe('')
		expect(wrapper.vm.formatDate('2026-03-01T10:00:00Z')).toBe(new Date('2026-03-01T10:00:00Z').toLocaleString())
	})
})
