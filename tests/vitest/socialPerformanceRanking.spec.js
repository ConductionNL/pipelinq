// @vitest-environment jsdom
/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 * SPDX-License-Identifier: EUPL-1.2
 *
 * The social performance page: one request fills the ranking, the rows are
 * flattened to one sortable value per column, a row without a rate sorts last
 * in both directions, and a failed load shows an error instead of rows.
 *
 * @spec openspec/specs/social-metrics/spec.md#requirement-posts-are-ranked-by-engagement-rate-per-network
 */
import { flushPromises, mount } from '@vue/test-utils'
import { beforeEach, describe, expect, it, vi } from 'vitest'
import { h } from 'vue'

const fetchPerformance = vi.fn()
vi.mock('../../src/services/socialApi.js', () => ({
	fetchPerformance: (...args) => fetchPerformance(...args),
}))
vi.mock('../../src/services/socialNetworkIcons.js', () => ({
	networkIcon: () => ({ render: () => h('i') }),
}))
vi.mock('@nextcloud/vue', () => ({
	NcNoteCard: {
		name: 'NcNoteCard',
		props: ['type'],
		render() {
			return h('div', { class: 'note' }, this.$slots.default?.())
		},
	},
	NcSelect: {
		name: 'NcSelect',
		props: ['modelValue', 'options'],
		emits: ['update:modelValue'],
		render: () => h('div'),
	},
}))
vi.mock('@conduction/nextcloud-vue', () => ({
	CnDataTable: {
		name: 'CnDataTable',
		props: ['columns', 'rows', 'loading', 'sortKey', 'sortOrder'],
		emits: ['sort'],
		render() {
			return h(
				'table',
				(this.rows || []).map((row) =>
					h('tr', [
						h('td', this.$slots['column-network']?.({ row })),
						h('td', this.$slots['column-engagementRate']?.({ row })),
					]),
				),
			)
		},
	},
}))

globalThis.t = (app, text, vars) =>
	text.replace(/\{(\w+)\}/g, (m, k) => (vars && k in vars ? vars[k] : m))
const global = { mocks: { t: globalThis.t } }

const { default: SocialPerformanceView } =
	await import('../../src/views/social/SocialPerformanceView.vue')

const ranking = [
	{
		publicationId: 'a',
		network: 'x',
		publishedAt: '2026-03-01T10:00:00Z',
		metrics: { views: 100, likes: 5, comments: 1, shares: 2 },
		followerCount: 900,
		engagementRate: 0.0125,
	},
	{
		publicationId: 'b',
		network: 'mastodon',
		metrics: { likes: 50 },
		engagementRate: null,
	},
	{
		publicationId: 'c',
		network: 'linkedin',
		publishedAt: '2026-02-01T10:00:00Z',
		metrics: { views: 10, likes: 9 },
		followerCount: 4000,
		engagementRate: 0.05,
	},
]

const table = (wrapper) => wrapper.findComponent({ name: 'CnDataTable' })
function ids (wrapper) {
  return table(wrapper)
		.props('rows')
		.map((r) => r.id)
}

describe('the social performance ranking', () => {
	beforeEach(() => {
		fetchPerformance.mockReset()
	})

	it('loads once on mount and flattens each row to one value per column', async () => {
		let resolve
		fetchPerformance.mockReturnValue(
			new Promise((r) => {
				resolve = r
			}),
		)
		const wrapper = mount(SocialPerformanceView, { global })
		await wrapper.vm.$nextTick()
		expect(table(wrapper).props('loading')).toBe(true)
		resolve(ranking)
		await flushPromises()

		expect(fetchPerformance).toHaveBeenCalledTimes(1)
		expect(table(wrapper).props('loading')).toBe(false)
		const b = table(wrapper)
			.props('rows')
			.find((r) => r.id === 'b')
		expect(b).toMatchObject({
			network: 'mastodon',
			publishedAt: '',
			views: 0,
			likes: 50,
			comments: 0,
			shares: 0,
			followerCount: 0,
			engagementRate: null,
		})
		expect(b.source).toEqual(ranking[1])
	})

	it('ranks by engagement rate descending with a missing rate last', async () => {
		fetchPerformance.mockResolvedValue(ranking)
		const wrapper = mount(SocialPerformanceView, { global })
		await flushPromises()
		expect(ids(wrapper)).toEqual(['c', 'a', 'b'])

		table(wrapper).vm.$emit('sort', { key: 'engagementRate', order: 'asc' })
		await wrapper.vm.$nextTick()
		expect(ids(wrapper)).toEqual(['a', 'c', 'b'])
	})

	it('sorts the network column on its label, not the stored value', async () => {
		fetchPerformance.mockResolvedValue(ranking)
		const wrapper = mount(SocialPerformanceView, { global })
		await flushPromises()

		table(wrapper).vm.$emit('sort', { key: 'network', order: 'asc' })
		await wrapper.vm.$nextTick()
		// LinkedIn < Mastodon < X
		expect(ids(wrapper)).toEqual(['c', 'b', 'a'])
	})

	it('sorts dates as strings and keeps server order without a key', async () => {
		fetchPerformance.mockResolvedValue(ranking)
		const wrapper = mount(SocialPerformanceView, { global })
		await flushPromises()

		table(wrapper).vm.$emit('sort', { key: 'publishedAt', order: 'desc' })
		await wrapper.vm.$nextTick()
		expect(ids(wrapper)).toEqual(['a', 'c', 'b'])

		table(wrapper).vm.$emit('sort', {})
		await wrapper.vm.$nextTick()
		expect(table(wrapper).props('sortOrder')).toBe('asc')
		expect(ids(wrapper)).toEqual(['a', 'b', 'c'])
	})

	it('offers the six columns plus date and follower count as sort choices', async () => {
		fetchPerformance.mockResolvedValue([])
		const wrapper = mount(SocialPerformanceView, { global })
		await flushPromises()
		const [sort, order] = wrapper.findAllComponents({ name: 'NcSelect' })
		expect(sort.props('options').map((o) => o.value)).toEqual([
			'network',
			'publishedAt',
			'views',
			'likes',
			'comments',
			'shares',
			'followerCount',
			'engagementRate',
		])
		expect(order.props('options').map((o) => o.value)).toEqual(['asc', 'desc'])

		sort.vm.$emit('update:modelValue', 'likes')
		await wrapper.vm.$nextTick()
		expect(table(wrapper).props('sortKey')).toBe('likes')
		expect(table(wrapper).props('sortOrder')).toBe('desc')
	})

	it('renders the network label and the formatted rate in the cells', async () => {
		fetchPerformance.mockResolvedValue([ranking[0], ranking[1]])
		const wrapper = mount(SocialPerformanceView, { global })
		await flushPromises()
		const cells = wrapper.findAll('td').map((td) => td.text())
		expect(cells).toEqual(['X', '1.25%', 'Mastodon', '-'])

		const followers = table(wrapper)
			.props('columns')
			.find((c) => c.key === 'engagementRate')
			.secondary({ followerCount: 900 })
		expect(followers).toBe('Followers: 900')
	})

	it('shows an error and no rows when the request fails', async () => {
		fetchPerformance.mockRejectedValue(new Error('boom'))
		const wrapper = mount(SocialPerformanceView, { global })
		await flushPromises()
		expect(wrapper.findComponent({ name: 'NcNoteCard' }).text()).toBe(
			'The numbers could not be loaded.',
		)
		expect(table(wrapper).props('rows')).toEqual([])
		expect(table(wrapper).props('loading')).toBe(false)
	})

	it('formats an unreadable date as stored and an empty one as empty', () => {
		fetchPerformance.mockResolvedValue([])
		const wrapper = mount(SocialPerformanceView, { global })
		expect(wrapper.vm.formatDate('not a date')).toBe('not a date')
		expect(wrapper.vm.formatDate(undefined)).toBe('')
		const iso = '2026-03-01T10:00:00Z'
		expect(wrapper.vm.formatDate(iso)).toBe(new Date(iso).toLocaleString())
	})
})
