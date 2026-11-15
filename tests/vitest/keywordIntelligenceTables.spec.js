// @vitest-environment jsdom
/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 * SPDX-License-Identifier: EUPL-1.2
 *
 * The keywords page as rendered (tables and cards): the ranking buckets, the striking-distance
 * table with its CTR, shortfall and confirm cells, the cannibalisation cards
 * with their per-page table, the content gaps or the crawl notice that
 * replaces them, and the confirm dialog that records a target without a
 * second request.
 *
 * @spec openspec/specs/marketing-keyword-intelligence/spec.md#requirement-the-keywords-page-shows-the-four-derivations-and-confirms-one-at-a-time
 */
import { flushPromises, mount } from '@vue/test-utils'
import { beforeEach, describe, expect, it, vi } from 'vitest'
import { h } from 'vue'

const http = vi.hoisted(() => ({ get: vi.fn() }))
vi.mock('@nextcloud/axios', () => ({ default: http }))
vi.mock('@nextcloud/router', () => ({ generateUrl: (path) => '/index.php' + path }))
vi.mock('vue-material-design-icons/KeyVariant.vue', () => ({
	default: { render: () => h('i') },
}))
vi.mock('../../src/modals/KeywordTargetConfirmModal.vue', () => ({
	default: {
		name: 'KeywordTargetConfirmModal',
		props: ['proposal', 'kind'],
		emits: ['close', 'confirmed'],
		render: () => h('div', { class: 'confirm-modal' }),
	},
}))
vi.mock('@nextcloud/vue', () => ({
	NcButton: {
		name: 'NcButton',
		props: ['variant', 'title'],
		emits: ['click'],
		render() {
			return h(
				'button',
				{ onClick: () => this.$emit('click') },
				this.$slots.default?.(),
			)
		},
	},
	NcEmptyContent: {
		name: 'NcEmptyContent',
		props: ['name', 'description'],
		render() {
			return h('div', { class: 'empty' }, this.name)
		},
	},
	NcLoadingIcon: {
		name: 'NcLoadingIcon',
		render: () => h('div', { class: 'loading' }),
	},
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
								{ 'data-col': c.key },
								slots['column-' + c.key]?.({ row })
									?? String(row[c.key] ?? ''),
							),
						),
					),
				),
			)
		},
	},
}))

function t (app, text, vars) {
  return text.replace(/\{(\w+)\}/g, (m, k) => (vars && k in vars ? vars[k] : m))
}
const global = { mocks: { t } }

const { default: KeywordIntelligence } =
	await import('../../src/views/marketing/KeywordIntelligence.vue')

const answer = {
	buckets: [
		{ bucket: '1-3', queries: 1, clicks: 40, impressions: 300 },
		{ bucket: '4-10', queries: 5, clicks: 12, impressions: 900 },
	],
	strikingDistance: [
		{
			query: 'crm gemeente',
			position: 9.4,
			impressions: 800,
			ctr: 0.012,
			shortfall: 0.021,
		},
		{
			query: 'zaaksysteem',
			position: 12,
			impressions: 500,
			ctr: 0.004,
			shortfall: -0.01,
		},
	],
	cannibalisation: [
		{
			query: 'klantcontact',
			impressions: 1000,
			combinedCtr: 0.03,
			bestPageCtr: 0.05,
			bestPage: 'https://ex.nl/a',
			pages: [
				{
					page: 'https://ex.nl/a',
					position: 4,
					impressions: 600,
					ctr: 0.05,
				},
				{
					page: 'https://ex.nl/b?x=1',
					position: 7,
					impressions: 400,
					ctr: 0.01,
				},
			],
		},
	],
	gaps: [{ query: 'subsidie aanvragen', impressions: 250, position: 31 }],
	crawl: { crawled: true, reason: '' },
	confirmedTerms: ['ZaakSysteem'],
	minImpressions: 100,
	from: '2026-01-01',
	to: '2026-01-29',
}

const section = (wrapper, id) => wrapper.find(`[data-testid="keyword-intel-${id}"]`)
function cells (wrapper, id, col) {
  return section(wrapper, id)
		.findAll(`td[data-col="${col}"]`)
		.map((td) => td.text())
}

describe('the rendered keywords page', () => {
	beforeEach(() => {
		http.get.mockReset()
		http.get.mockResolvedValue({ data: answer })
	})

	it('states the window and lists each ranking bucket', async () => {
		const wrapper = mount(KeywordIntelligence, { global })
		await flushPromises()
		expect(section(wrapper, 'window').text()).toBe(
			'Between 2026-01-01 and 2026-01-29, from 100 impressions',
		)
		const buckets = wrapper
			.findAll('.keyword-intel__bucket')
			.map((b) =>
				b.findAll(':scope > span').map((s) => s.text().replace(/\s+/g, ' ')),
			)
		expect(buckets).toEqual([
			['#1–3', '1 search', '40 clicks, 300 impressions'],
			['#4–10', '5 searches', '12 clicks, 900 impressions'],
		])
	})

	it('fills the striking-distance cells and offers confirm only for new terms', async () => {
		const wrapper = mount(KeywordIntelligence, { global })
		await flushPromises()
		expect(cells(wrapper, 'striking', 'ctr')).toEqual(['1.2%', '0.4%'])
		expect(cells(wrapper, 'striking', 'shortfall')).toEqual(['2.1%', '0.0%'])
		expect(cells(wrapper, 'striking', 'target')).toEqual([
			'Add as target',
			'Already a target',
		])
	})

	it('shows each competing page with its path, share and best-page mark', async () => {
		const wrapper = mount(KeywordIntelligence, { global })
		await flushPromises()
		const card = section(wrapper, 'cannibalisation').find('.keyword-intel__card')
		expect(card.find('.keyword-intel__summary').text()).toBe(
			'Together these pages are clicked in 3.0% of searches. The best page alone is clicked in 5.0%.',
		)
		const rows = card
			.findAll('tbody tr')
			.map((tr) => tr.findAll('td').map((td) => td.text()))
		expect(rows).toEqual([
			['/aBest page', '4', '60.0%', '5.0%'],
			['/b?x=1', '7', '40.0%', '1.0%'],
		])
		expect(card.findAll('.keyword-intel__best')).toHaveLength(1)
		expect(card.find('a').attributes()).toMatchObject({
			href: 'https://ex.nl/a',
			target: '_blank',
			rel: 'noopener noreferrer',
		})
	})

	it('lists the content gaps once the site was crawled', async () => {
		const wrapper = mount(KeywordIntelligence, { global })
		await flushPromises()
		expect(cells(wrapper, 'gaps', 'query')).toEqual(['subsidie aanvragen'])
		expect(
			wrapper.find('[data-testid="keyword-intel-crawl-notice"]').exists(),
		).toBe(false)
	})

	it('replaces the gaps with the crawl notice when nothing was crawled', async () => {
		http.get.mockResolvedValue({
			data: {
				...answer,
				crawl: { crawled: false, reason: 'No crawl source set.' },
			},
		})
		const wrapper = mount(KeywordIntelligence, { global })
		await flushPromises()
		const notice = wrapper.find('[data-testid="keyword-intel-crawl-notice"]')
		expect(notice.text()).toContain('No crawl source set.')
		expect(
			wrapper.find('[data-testid="keyword-intel-gaps-table"]').exists(),
		).toBe(false)
	})

	it('says so in each section that has no findings', async () => {
		http.get.mockResolvedValue({
			data: { buckets: answer.buckets, crawl: { crawled: true } },
		})
		const wrapper = mount(KeywordIntelligence, { global })
		await flushPromises()
		const none = wrapper.findAll('.keyword-intel__none').map((p) => p.text())
		expect(none).toHaveLength(3)
		expect(none[0]).toMatch(/^No query sits between position 8 and 20/)
		expect(none[1]).toMatch(/^No query has two pages/)
		expect(none[2]).toMatch(/^Every query with demand is answered/)
	})

	it('opens the confirm dialog per proposal and records the confirmed term', async () => {
		const wrapper = mount(KeywordIntelligence, { global })
		await flushPromises()
		http.get.mockClear()

		await section(wrapper, 'striking').find('button').trigger('click')
		let modal = wrapper.findComponent({ name: 'KeywordTargetConfirmModal' })
		expect(modal.props('kind')).toBe('striking-distance')
		expect(modal.props('proposal').query).toBe('crm gemeente')

		await modal.vm.$emit('confirmed', 'CRM Gemeente')
		expect(
			wrapper.findComponent({ name: 'KeywordTargetConfirmModal' }).exists(),
		).toBe(false)
		expect(cells(wrapper, 'striking', 'target')).toEqual([
			'Already a target',
			'Already a target',
		])

		await section(wrapper, 'cannibalisation').find('button').trigger('click')
		modal = wrapper.findComponent({ name: 'KeywordTargetConfirmModal' })
		expect(modal.props('kind')).toBe('cannibalisation')
		await modal.vm.$emit('close')
		expect(
			wrapper.findComponent({ name: 'KeywordTargetConfirmModal' }).exists(),
		).toBe(false)

		await section(wrapper, 'gaps').find('button').trigger('click')
		expect(
			wrapper
				.findComponent({ name: 'KeywordTargetConfirmModal' })
				.props('kind'),
		).toBe('content-gap')
		expect(http.get).not.toHaveBeenCalled()
	})

	it('leaves out the header, window line, buckets and page tables when embedded', async () => {
		const wrapper = mount(KeywordIntelligence, {
			props: { embedded: true, days: 90 },
			global,
		})
		await flushPromises()
		expect(wrapper.find('h2').exists()).toBe(false)
		expect(section(wrapper, 'window').exists()).toBe(false)
		expect(section(wrapper, 'buckets').exists()).toBe(false)
		expect(wrapper.find('.keyword-intel__table').exists()).toBe(false)
		const { from, to } = http.get.mock.calls[0][1].params
		expect((Date.parse(to) - Date.parse(from)) / 86400000).toBe(90)
	})

	it('shows the empty state without ranked queries and the error when loading fails', async () => {
		http.get.mockResolvedValueOnce({
			data: { buckets: [{ bucket: '1-3', queries: 0 }] },
		})
		const empty = mount(KeywordIntelligence, { global })
		await flushPromises()
		expect(empty.find('[data-testid="keyword-intel-empty"]').text()).toBe(
			'No search data yet',
		)

		http.get.mockRejectedValueOnce({
			response: { data: { error: 'Search Console offline' } },
		})
		const failed = mount(KeywordIntelligence, { global })
		await flushPromises()
		expect(failed.find('[role="alert"]').text()).toBe('Search Console offline')
		expect(failed.find('[data-testid="keyword-intel-empty"]').exists()).toBe(
			true,
		)
	})
})
