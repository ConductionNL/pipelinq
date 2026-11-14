// @vitest-environment jsdom
/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 * SPDX-License-Identifier: EUPL-1.2
 *
 * The keyword intelligence page as drawn: the empty state, the ranking
 * buckets and window line, the striking-distance and content-gap tables with
 * their cells and "Add as target" buttons, the cannibalisation cards with the
 * per-page table, the crawl notice, the confirmation dialog and the error.
 *
 * @spec openspec/specs/marketing-keyword-intelligence/spec.md#requirement-the-keywords-page-shows-the-four-derivations-and-confirms-one-at-a-time
 */
import { flushPromises, mount } from '@vue/test-utils'
import { afterEach, beforeAll, describe, expect, it, vi } from 'vitest'

const axios = vi.hoisted(() => ({ get: vi.fn() }))
vi.mock('@nextcloud/axios', () => ({ default: axios }))
vi.mock('@nextcloud/router', () => ({ generateUrl: (path) => '/index.php' + path }))
vi.mock('@nextcloud/vue', () => ({
	NcButton: { name: 'NcButton', props: ['title', 'variant'], template: '<button :title="title"><slot /></button>' },
	NcEmptyContent: { name: 'NcEmptyContent', props: ['name', 'description'], template: '<div class="empty">{{ name }}|{{ description }}</div>' },
	NcLoadingIcon: { name: 'NcLoadingIcon', template: '<i class="loading" />' },
	NcNoteCard: { name: 'NcNoteCard', template: '<div class="note"><slot /></div>' },
	NcSelect: { name: 'NcSelect', props: ['modelValue', 'options'], emits: ['update:modelValue'], template: '<div class="select" />' },
}))
vi.mock('@conduction/nextcloud-vue', () => ({
	CnDataTable: {
		name: 'CnDataTable',
		props: ['columns', 'rows', 'rowKey'],
		template: '<table><tr v-for="row in rows" :key="row[rowKey]"><td v-for="c in columns" :key="c.key" :class="\'col-\' + c.key"><slot :name="\'column-\' + c.key" :row="row">{{ row[c.key] }}</slot></td></tr></table>',
	},
}))
vi.mock('../../src/modals/KeywordTargetConfirmModal.vue', () => ({
	default: { name: 'KeywordTargetConfirmModal', props: ['proposal', 'kind'], emits: ['close', 'confirmed'], template: '<div class="confirm" />' },
}))
vi.mock('vue-material-design-icons/KeyVariant.vue', () => ({ default: { template: '<i />' } }))

let KeywordIntelligence

beforeAll(async () => {
	globalThis.t = (app, text, vars = {}) => text.replace(/\{(\w+)\}/g, (m, k) => String(vars[k] ?? m))
	KeywordIntelligence = (await import('../../src/views/marketing/KeywordIntelligence.vue')).default
}, 60000)

afterEach(() => {
	axios.get.mockReset()
})

const ANSWER = {
	buckets: [
		{ bucket: '1-3', queries: 1, clicks: 40, impressions: 400 },
		{ bucket: '4-10', queries: 3, clicks: 12, impressions: 900 },
	],
	strikingDistance: [
		{ query: 'omgevingsvergunning', position: 11, impressions: 500, ctr: 0.012, shortfall: 0.034 },
		{ query: 'bouwvergunning', position: 14, impressions: 300, ctr: 0.02, shortfall: -0.1 },
	],
	cannibalisation: [
		{
			query: 'parkeervergunning',
			impressions: 1000,
			combinedCtr: 0.03,
			bestPageCtr: 0.05,
			bestPage: 'https://gemeente.nl/parkeren?x=1',
			pages: [
				{ page: 'https://gemeente.nl/parkeren?x=1', position: 3, impressions: 600, ctr: 0.05 },
				{ page: 'https://gemeente.nl/vergunningen', position: 7, impressions: 400, ctr: 0.01 },
			],
		},
	],
	gaps: [{ query: 'afvalkalender', impressions: 800, position: 22 }],
	crawl: { crawled: true, reason: '' },
	confirmedTerms: ['BOUWVERGUNNING'],
	minImpressions: 50,
	from: '2026-10-16',
	to: '2026-11-13',
}

async function mountWith(answer, props = {}) {
	axios.get.mockResolvedValue({ data: answer })
	const wrapper = mount(KeywordIntelligence, { props, global: { mocks: { t: globalThis.t } } })
	await flushPromises()
	return wrapper
}

const testid = (wrapper, id) => wrapper.find(`[data-testid="${id}"]`)

describe('KeywordIntelligence page', () => {
	it('shows the empty state while no bucket holds a query', async () => {
		const wrapper = await mountWith({ buckets: [{ bucket: '1-3', queries: 0 }] })

		expect(testid(wrapper, 'keyword-intel-empty').text()).toContain('No search data yet|Connect a Search Console property')
		expect(testid(wrapper, 'keyword-intel-striking').exists()).toBe(false)
		expect(wrapper.find('.loading').exists()).toBe(false)
	})

	it('draws the window line and the ranking buckets', async () => {
		const wrapper = await mountWith(ANSWER)

		expect(testid(wrapper, 'keyword-intel-window').text()).toBe('Between 2026-10-16 and 2026-11-13, from 50 impressions')
		const buckets = wrapper.findAll('.keyword-intel__bucket').map((b) => b.findAll('span').map((s) => s.text().replace(/\s+/g, ' ')))
		expect(buckets).toEqual([
			['#1–3', '1 search', '40 clicks, 400 impressions'],
			['#4–10', '3 searches', '12 clicks, 900 impressions'],
		])
	})

	it('fills the striking-distance table and offers only unconfirmed queries', async () => {
		const wrapper = await mountWith(ANSWER)
		const table = testid(wrapper, 'keyword-intel-striking-table')

		expect(table.findAll('.col-ctr').map((c) => c.text())).toEqual(['1.2%', '2.0%'])
		expect(table.findAll('.col-shortfall').map((c) => c.text())).toEqual(['3.4%', '0.0%'])
		const targets = table.findAll('.col-target')
		expect(targets[0].find('button').attributes('title')).toBe('Save this search as a keyword to work on')
		expect(targets[1].find('button').exists()).toBe(false)
		expect(targets[1].text()).toBe('Already a target')
		expect(wrapper.findComponent({ name: 'CnDataTable' }).props('columns').map((c) => c.key)).toEqual(['query', 'position', 'impressions', 'ctr', 'shortfall', 'target'])
	})

	it('draws a cannibalisation card with each page, the best one marked', async () => {
		const wrapper = await mountWith(ANSWER)
		const card = testid(wrapper, 'keyword-intel-cannibalisation').find('.keyword-intel__card')

		expect(card.find('.keyword-intel__query').text()).toBe('parkeervergunning')
		expect(card.find('.keyword-intel__summary').text()).toBe('Together these pages are clicked in 3.0% of searches. The best page alone is clicked in 5.0%.')
		const rows = card.findAll('tbody tr').map((tr) => tr.findAll('td').map((td) => td.text().replace(/\s+/g, ' ')))
		expect(rows).toEqual([
			['/parkeren?x=1Best page', '3', '60.0%', '5.0%'],
			['/vergunningen', '7', '40.0%', '1.0%'],
		])
		expect(card.find('a').attributes()).toMatchObject({ href: 'https://gemeente.nl/parkeren?x=1', target: '_blank', rel: 'noopener noreferrer' })
	})

	it('drops the buckets, window and page tables when embedded', async () => {
		const wrapper = await mountWith(ANSWER, { embedded: true, days: 90 })

		expect(wrapper.find('header').exists()).toBe(false)
		expect(testid(wrapper, 'keyword-intel-window').exists()).toBe(false)
		expect(testid(wrapper, 'keyword-intel-buckets').exists()).toBe(false)
		expect(wrapper.find('.keyword-intel__table').exists()).toBe(false)
		expect(wrapper.find('.keyword-intel__card').exists()).toBe(true)
	})

	it('refetches when the host changes the period', async () => {
		const wrapper = await mountWith(ANSWER, { embedded: true, days: 28 })
		expect(axios.get).toHaveBeenCalledTimes(1)

		await wrapper.setProps({ days: 90 })
		await flushPromises()

		expect(axios.get).toHaveBeenCalledTimes(2)
	})

	it('refetches when the period select changes', async () => {
		const wrapper = await mountWith(ANSWER)
		const select = wrapper.findComponent({ name: 'NcSelect' })
		expect(select.props('modelValue')).toEqual({ days: 28, label: 'Last 28 days' })

		select.vm.$emit('update:modelValue', { days: 90, label: 'Last 90 days' })
		await flushPromises()

		expect(axios.get).toHaveBeenCalledTimes(2)
		expect(wrapper.vm.window).toEqual({ days: 90, label: 'Last 90 days' })
	})

	it('lists content gaps when the site was crawled, and the crawl notice otherwise', async () => {
		const crawled = await mountWith(ANSWER)
		const gaps = testid(crawled, 'keyword-intel-gaps-table')
		expect(gaps.findAll('tr').length).toBe(1)
		expect(gaps.find('.col-target button').exists()).toBe(true)
		expect(testid(crawled, 'keyword-intel-crawl-notice').exists()).toBe(false)

		const notCrawled = await mountWith({ ...ANSWER, crawl: { crawled: false, reason: 'No crawl source is set.' } })
		expect(testid(notCrawled, 'keyword-intel-crawl-notice').text()).toContain('No crawl source is set.')
		expect(testid(notCrawled, 'keyword-intel-gaps-table').exists()).toBe(false)
	})

	it('says so when a section has nothing', async () => {
		const wrapper = await mountWith({ ...ANSWER, strikingDistance: [], cannibalisation: [], gaps: [] })

		expect(wrapper.findAll('.keyword-intel__none').map((p) => p.text().slice(0, 20))).toEqual([
			'No query sits betwee',
			'No query has two pag',
			'Every query with dem',
		])
	})

	it('opens the confirmation for a proposal, records the confirmed term and closes', async () => {
		const wrapper = await mountWith(ANSWER)

		await testid(wrapper, 'keyword-intel-gaps-table').find('.col-target button').trigger('click')
		const modal = wrapper.findComponent({ name: 'KeywordTargetConfirmModal' })
		expect(modal.props()).toEqual({ proposal: ANSWER.gaps[0], kind: 'content-gap' })

		modal.vm.$emit('confirmed', 'AfvalKalender')
		await flushPromises()
		expect(wrapper.findComponent({ name: 'KeywordTargetConfirmModal' }).exists()).toBe(false)
		expect(wrapper.vm.confirmedTerms).toEqual(['BOUWVERGUNNING', 'afvalkalender'])
		expect(testid(wrapper, 'keyword-intel-gaps-table').find('.col-target button').exists()).toBe(false)

		await testid(wrapper, 'keyword-intel-cannibalisation').find('button').trigger('click')
		expect(wrapper.findComponent({ name: 'KeywordTargetConfirmModal' }).props('kind')).toBe('cannibalisation')
		wrapper.findComponent({ name: 'KeywordTargetConfirmModal' }).vm.$emit('close')
		await flushPromises()
		expect(wrapper.findComponent({ name: 'KeywordTargetConfirmModal' }).exists()).toBe(false)

		await testid(wrapper, 'keyword-intel-striking-table').find('.col-target button').trigger('click')
		expect(wrapper.findComponent({ name: 'KeywordTargetConfirmModal' }).props('kind')).toBe('striking-distance')
	})

	it('shows the error of a failed load', async () => {
		axios.get.mockRejectedValue({ response: { data: { error: 'Search Console is not connected.' } } })
		const wrapper = mount(KeywordIntelligence, { global: { mocks: { t: globalThis.t } } })
		await flushPromises()

		expect(wrapper.find('[role="alert"]').text()).toBe('Search Console is not connected.')
	})

	it('builds the confirmation body through the shared helper', async () => {
		const wrapper = await mountWith(ANSWER)

		expect(wrapper.vm.payloadFor({ query: ' afval ' }, { status: 'bogus' }, 'content-gap')).toMatchObject({ term: 'afval', status: 'watch', proposalKind: 'content-gap' })
	})
})
