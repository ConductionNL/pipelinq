// @vitest-environment jsdom
/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 * SPDX-License-Identifier: EUPL-1.2
 *
 * The keyword intelligence page as it renders: the empty state, the ranking
 * buckets, the three proposal sections with their "Add as target" buttons,
 * the crawl notice, the embedded variant and the confirmation round trip.
 *
 * @spec openspec/specs/marketing-keyword-intelligence/spec.md#requirement-the-keywords-page-shows-the-four-derivations-and-confirms-one-at-a-time
 */

import { flushPromises, mount } from '@vue/test-utils'
import { afterEach, describe, expect, it, vi } from 'vitest'

const axios = vi.hoisted(() => ({ get: vi.fn() }))
vi.mock('@nextcloud/axios', () => ({ default: axios }))
vi.mock('@nextcloud/router', () => ({ generateUrl: (path) => '/index.php' + path }))

vi.mock('@nextcloud/vue', async () => {
	const { defineComponent: define, h: render } = await import('vue')
	return {
		NcButton: define({
			name: 'NcButton',
			props: ['title'],
			emits: ['click'],
			setup(props, { emit, slots }) {
				return () => render('button', { class: 'nc-button', title: props.title, onClick: () => emit('click') }, slots.default?.())
			},
		}),
		NcEmptyContent: define({
			name: 'NcEmptyContent',
			props: ['name', 'description'],
			setup(props, { attrs }) {
				return () => render('div', { 'data-testid': attrs['data-testid'] }, [props.name, ' ', props.description])
			},
		}),
		NcLoadingIcon: define({ name: 'NcLoadingIcon', setup: () => () => render('span', { class: 'loading' }) }),
		NcNoteCard: define({
			name: 'NcNoteCard',
			setup(_props, { slots, attrs }) {
				return () => render('div', { 'data-testid': attrs['data-testid'] }, slots.default?.())
			},
		}),
		NcSelect: define({
			name: 'NcSelect',
			props: ['modelValue', 'options'],
			emits: ['update:modelValue'],
			setup: () => () => render('div', { class: 'nc-select' }),
		}),
	}
})

vi.mock('@conduction/nextcloud-vue', async () => {
	const { defineComponent: define, h: render } = await import('vue')
	return {
		CnDataTable: define({
			name: 'CnDataTable',
			props: ['columns', 'rows', 'rowKey'],
			setup(props, { slots, attrs }) {
				return () => render('table', { 'data-testid': attrs['data-testid'] }, props.rows.map((row) => render(
					'tr',
					{ key: row[props.rowKey] },
					props.columns.map((column) => render(
						'td',
						{ 'data-column': column.key },
						slots['column-' + column.key] ? slots['column-' + column.key]({ row }) : String(row[column.key] ?? ''),
					)),
				)))
			},
		}),
	}
})

vi.mock('vue-material-design-icons/KeyVariant.vue', () => ({ default: { name: 'KeyVariant', render: () => null } }))
vi.mock('../../src/modals/KeywordTargetConfirmModal.vue', async () => {
	const { defineComponent: define, h: render } = await import('vue')
	return {
		default: define({
			name: 'KeywordTargetConfirmModal',
			props: ['proposal', 'kind'],
			emits: ['close', 'confirmed'],
			setup(props) {
				return () => render('div', { class: 'confirm-modal', 'data-kind': props.kind }, props.proposal.query)
			},
		}),
	}
})

globalThis.t = (_app, text, vars = {}) => text.replace(/\{(\w+)\}/g, (_m, key) => String(vars[key] ?? `{${key}}`))

const { default: KeywordIntelligence } = await import('../../src/views/marketing/KeywordIntelligence.vue')

/**
 * A full answer: two ranking buckets, one row in each proposal section.
 *
 * @param {object} overrides Fields to replace.
 * @return {object} The answer.
 */
function answer(overrides = {}) {
	return {
		buckets: [
			{ bucket: '1-3', queries: 1, clicks: 40, impressions: 400 },
			{ bucket: '21+', queries: 7, clicks: 2, impressions: 90 },
		],
		strikingDistance: [{ query: 'crm software', position: 9.4, impressions: 800, ctr: 0.012, shortfall: 0.021 }],
		cannibalisation: [{
			query: 'klantreis',
			impressions: 1000,
			combinedCtr: 0.02,
			bestPageCtr: 0.035,
			bestPage: 'https://www.demo.example/blog/klantreis',
			pages: [
				{ page: 'https://www.demo.example/blog/klantreis', position: 4, impressions: 600, ctr: 0.035 },
				{ page: 'https://www.demo.example/klantreis', position: 7, impressions: 400, ctr: 0.01 },
			],
		}],
		gaps: [{ query: 'crm voor gemeenten', impressions: 300, position: 31 }],
		crawl: { crawled: true, reason: '' },
		confirmedTerms: [],
		minImpressions: 1490,
		from: '2026-10-13',
		to: '2026-11-10',
		...overrides,
	}
}

/**
 * Mount the page on a given answer and let its one request settle.
 *
 * @param {object} data The answer.
 * @param {object} props The props.
 * @return {Promise<object>} The wrapper.
 */
async function render(data, props = {}) {
	axios.get.mockResolvedValue({ data })
	const wrapper = mount(KeywordIntelligence, {
		props,
		global: { mocks: { t: globalThis.t } },
	})
	await flushPromises()
	return wrapper
}

afterEach(() => {
	axios.get.mockReset()
})

describe('KeywordIntelligence rendered', () => {
	it('shows the empty state when no bucket holds a query', async () => {
		const wrapper = await render(answer({ buckets: [{ bucket: '1-3', queries: 0 }] }))

		expect(wrapper.find('[data-testid="keyword-intel-empty"]').text()).toContain('No search data yet')
		expect(wrapper.find('[data-testid="keyword-intel-striking"]').exists()).toBe(false)
		expect(wrapper.find('.nc-select').exists()).toBe(true)
	})

	it('draws the window, the buckets and one row per proposal section', async () => {
		const wrapper = await render(answer())

		expect(wrapper.find('[data-testid="keyword-intel-window"]').text()).toBe('Between 2026-10-13 and 2026-11-10, from 1490 impressions')
		const buckets = wrapper.findAll('.keyword-intel__bucket')
		expect(buckets).toHaveLength(2)
		expect(buckets[0].text()).toContain('#1–3')
		expect(buckets[0].text()).toContain('1 search')
		expect(buckets[1].text()).toContain('7 searches')
		expect(buckets[1].text()).toContain('2 clicks, 90 impressions')

		const striking = wrapper.find('[data-testid="keyword-intel-striking-table"]')
		expect(striking.find('[data-column="ctr"]').text()).toBe('1.2%')
		expect(striking.find('[data-column="shortfall"]').text()).toBe('2.1%')
		expect(striking.find('[data-column="target"] button').text()).toBe('Add as target')
		expect(striking.find('[data-column="target"] button').attributes('title')).toBe('Save this search as a keyword to work on')

		const card = wrapper.find('.keyword-intel__card')
		expect(card.find('.keyword-intel__query').text()).toBe('klantreis')
		expect(card.find('.keyword-intel__summary').text()).toBe('Together these pages are clicked in 2.0% of searches. The best page alone is clicked in 3.5%.')
		const links = card.findAll('.keyword-intel__page-link')
		expect(links.map((link) => link.text())).toEqual(['/blog/klantreis', '/klantreis'])
		expect(card.findAll('.keyword-intel__best')).toHaveLength(1)
		expect(card.findAll('tbody tr')[0].text()).toContain('60.0%')

		expect(wrapper.find('[data-testid="keyword-intel-gaps-table"] [data-column="query"]').text()).toBe('crm voor gemeenten')
		expect(wrapper.find('[data-testid="keyword-intel-crawl-notice"]').exists()).toBe(false)
	})

	it('says so when a section has nothing to propose', async () => {
		const wrapper = await render(answer({ strikingDistance: [], cannibalisation: [], gaps: [] }))

		const none = wrapper.findAll('.keyword-intel__none').map((node) => node.text())
		expect(none).toHaveLength(3)
		expect(none[0]).toContain('No query sits between position 8 and 20')
		expect(none[1]).toContain('No query has two pages taking a real share')
		expect(none[2]).toContain('Every query with demand is answered')
	})

	it('warns that the gap check did not run instead of reporting no gaps', async () => {
		const wrapper = await render(answer({ gaps: [], crawl: { crawled: false, reason: 'No crawl source is set.' } }))

		const notice = wrapper.find('[data-testid="keyword-intel-crawl-notice"]')
		expect(notice.text()).toContain('No crawl source is set.')
		expect(notice.text()).toContain('Set a crawl source under Settings, Marketing intelligence')
		expect(wrapper.find('[data-testid="keyword-intel-gaps-table"]').exists()).toBe(false)
	})

	it('marks a confirmed term as taken and offers no button for it', async () => {
		const wrapper = await render(answer({ confirmedTerms: ['crm software', 'klantreis', 'crm voor gemeenten'] }))

		expect(wrapper.find('[data-testid="keyword-intel-striking-table"] .keyword-intel__taken').text()).toBe('Already a target')
		expect(wrapper.find('.keyword-intel__card button').exists()).toBe(false)
		expect(wrapper.find('[data-testid="keyword-intel-gaps-table"] [data-column="target"] button').exists()).toBe(false)
	})

	it('opens the confirmation with the section kind and records the confirmed term', async () => {
		const wrapper = await render(answer())

		await wrapper.find('.keyword-intel__card button').trigger('click')
		expect(wrapper.find('.confirm-modal').attributes('data-kind')).toBe('cannibalisation')
		await wrapper.find('[data-testid="keyword-intel-gaps-table"] [data-column="target"] button').trigger('click')
		const modal = wrapper.findComponent({ name: 'KeywordTargetConfirmModal' })
		expect(modal.props('kind')).toBe('content-gap')
		expect(modal.props('proposal').query).toBe('crm voor gemeenten')

		modal.vm.$emit('confirmed', 'CRM voor Gemeenten')
		await flushPromises()
		expect(wrapper.vm.confirmedTerms).toEqual(['crm voor gemeenten'])
		expect(wrapper.find('.confirm-modal').exists()).toBe(false)

		await wrapper.find('[data-testid="keyword-intel-striking-table"] [data-column="target"] button').trigger('click')
		const again = wrapper.findComponent({ name: 'KeywordTargetConfirmModal' })
		expect(again.props('kind')).toBe('striking-distance')
		again.vm.$emit('close')
		await flushPromises()
		expect(wrapper.find('.confirm-modal').exists()).toBe(false)
	})

	it('shows a failure as an alert', async () => {
		axios.get.mockRejectedValue({ response: { data: { error: 'Search Console refused' } } })
		const wrapper = mount(KeywordIntelligence, { global: { mocks: { t: globalThis.t } } })
		await flushPromises()

		expect(wrapper.find('[role="alert"]').text()).toBe('Search Console refused')
	})

	it('embedded, drops the header, window and buckets and reloads when the host period changes', async () => {
		const wrapper = await render(answer(), { embedded: true, days: 7 })

		expect(wrapper.find('.keyword-intel--embedded').exists()).toBe(true)
		expect(wrapper.find('.nc-select').exists()).toBe(false)
		expect(wrapper.find('[data-testid="keyword-intel-window"]').exists()).toBe(false)
		expect(wrapper.find('[data-testid="keyword-intel-buckets"]').exists()).toBe(false)
		expect(wrapper.find('.keyword-intel__card table').exists()).toBe(false)
		expect(axios.get).toHaveBeenCalledTimes(1)

		await wrapper.setProps({ days: 90 })
		await flushPromises()
		expect(axios.get).toHaveBeenCalledTimes(2)
	})

	it('reloads when the period is picked', async () => {
		const wrapper = await render(answer())
		wrapper.findComponent({ name: 'NcSelect' }).vm.$emit('update:modelValue', { days: 90, label: 'Last 90 days' })
		await flushPromises()

		expect(axios.get).toHaveBeenCalledTimes(2)
		expect(wrapper.vm.window.days).toBe(90)
	})
})
