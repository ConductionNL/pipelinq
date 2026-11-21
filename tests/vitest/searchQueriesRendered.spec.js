// @vitest-environment jsdom
/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 * SPDX-License-Identifier: EUPL-1.2
 *
 * The search insight page as it renders: the query table and its empty
 * states, the period control, the connection audit table and its "Check
 * again", and the download in the order the table reports.
 *
 * @spec openspec/changes/advanced-hub-and-connection-audit-board/specs/board-look/spec.md#requirement-the-connection-audit-reads-as-its-board
 */

import { flushPromises, mount } from '@vue/test-utils'
import { beforeEach, describe, expect, it, vi } from 'vitest'

vi.hoisted(() => {
	globalThis.t = (app, text, vars = {}) => String(text).replace(/\{(\w+)\}/g, (m, k) => (k in vars ? String(vars[k]) : m))
})

const axios = vi.hoisted(() => ({ get: vi.fn() }))
const dialogs = vi.hoisted(() => ({ showError: vi.fn(), showSuccess: vi.fn() }))
const csv = vi.hoisted(() => ({ downloadCsv: vi.fn() }))
vi.mock('@nextcloud/axios', () => ({ default: axios }))
vi.mock('@nextcloud/dialogs', () => dialogs)
vi.mock('@nextcloud/router', () => ({ generateUrl: (path) => '/index.php' + path }))
vi.mock('../../src/services/csvExport.js', () => csv)
vi.mock('../../src/views/marketing/KeywordIntelligence.vue', () => ({ default: { name: 'KeywordIntelligence', props: ['embedded', 'days'], render: () => null } }))
vi.mock('../../src/views/marketing/CompetitorWatches.vue', () => ({ default: { name: 'CompetitorWatches', props: ['embedded'], render: () => null } }))

vi.mock('@conduction/nextcloud-vue', async () => {
	const { defineComponent: define, h } = await import('vue')
	return {
		useLook: () => ({ lookClass: 'look-board' }),
		CnBuildiqEditButton: define({ name: 'CnBuildiqEditButton', render: () => null }),
		CnPageHeader: define({
			name: 'CnPageHeader',
			setup(_props, { slots }) {
				return () => h('header', slots.extra?.())
			},
		}),
		CnSegmentedControl: define({ name: 'CnSegmentedControl', props: ['modelValue', 'options'], emits: ['update:modelValue'], render: () => null }),
		CnDataTable: define({
			name: 'CnDataTable',
			props: ['columns', 'rows', 'rowKey'],
			emits: ['sort'],
			setup(props, { slots, attrs }) {
				return () => h('table', { 'data-testid': attrs['data-testid'] }, props.rows.map((row) => h(
					'tr',
					{ key: row[props.rowKey], 'data-row': row[props.rowKey] },
					props.columns.map((column) => h(
						'td',
						{ 'data-column': column.key },
						slots['column-' + column.key] ? slots['column-' + column.key]({ row }) : String(row[column.key] ?? ''),
					)),
				)))
			},
		}),
	}
})
vi.mock('@nextcloud/vue', async () => {
	const { defineComponent: define, h } = await import('vue')
	return {
		NcButton: define({
			name: 'NcButton',
			props: ['disabled'],
			emits: ['click'],
			setup(props, { emit, slots, attrs }) {
				return () => h('button', { 'data-testid': attrs['data-testid'], disabled: props.disabled, onClick: () => emit('click') }, [slots.icon?.(), slots.default?.()])
			},
		}),
		NcEmptyContent: define({
			name: 'NcEmptyContent',
			props: ['name', 'description'],
			setup(props, { attrs, slots }) {
				return () => h('div', { 'data-testid': attrs['data-testid'] }, [slots.icon?.(), `${props.name} | ${props.description}`])
			},
		}),
		NcLoadingIcon: define({ name: 'NcLoadingIcon', setup: () => () => h('span', { class: 'loading' }) }),
	}
})
const icon = (name) => ({ default: { name, render: () => null } })
vi.mock('vue-material-design-icons/Download.vue', () => icon('Download'))
vi.mock('vue-material-design-icons/Magnify.vue', () => icon('Magnify'))
vi.mock('vue-material-design-icons/Refresh.vue', () => icon('Refresh'))

const { default: SearchQueries } = await import('../../src/views/marketing/SearchQueries.vue')

const queries = {
	rows: [
		{ query: 'crm', clicks: 4, impressions: 100, ctr: 0.04, position: 3.2, pages: 1 },
		{ query: 'agenda', clicks: 9, impressions: 300, ctr: 0.03, position: 5.1, pages: 2 },
	],
	totalQueries: 2,
	from: '2026-10-13',
	to: '2026-11-10',
	configured: true,
	lastImportAt: '2026-11-11 06:00',
}

const audit = {
	rows: [
		{ id: 'a-1', counterpartHandle: '@gemeente@social.example', network: 'mastodon', weFollowThem: 'yes', theyFollowUs: 'no', reason: '' },
		{ counterpartHandle: '', network: 'ThreadsLike', weFollowThem: 'unknown', theyFollowUs: 'yes', reason: 'Private' },
	],
}

/**
 * Answer the two requests the page makes.
 *
 * @param {object|Error} queryAnswer The search queries answer, or a failure.
 * @param {object|Error} auditAnswer The connection audit answer, or a failure.
 */
function answer(queryAnswer, auditAnswer = audit) {
	axios.get.mockImplementation(async (url) => {
		const value = url.includes('connection-audit') ? auditAnswer : queryAnswer
		if (value instanceof Error || value?.response) {
			throw value
		}
		return { data: value }
	})
}

/**
 * Mount the page and let it load.
 *
 * @return {Promise<object>} The wrapper.
 */
async function render() {
	const wrapper = mount(SearchQueries, { global: { mocks: { t: globalThis.t } } })
	await flushPromises()
	return wrapper
}

beforeEach(() => {
	vi.clearAllMocks()
})

describe('SearchQueries rendered', () => {
	it('lists the queries with their click-through and the import moment', async () => {
		answer(queries)
		const wrapper = await render()

		expect(wrapper.classes()).toContain('look-board')
		expect(wrapper.find('.search-queries__meta').text().replace(/\s+/g, ' ')).toBe('2 queries between 2026-10-13 and 2026-11-10 · Last import: 2026-11-11 06:00')
		const crm = wrapper.find('[data-testid="search-queries-table"] [data-row="crm"]')
		expect(crm.find('[data-column="ctr"]').text()).toBe('4.0%')
		expect(crm.find('[data-column="clicks"]').text()).toBe('4')
		expect(wrapper.find('[data-testid="search-queries-download"]').element.disabled).toBe(false)
		expect(wrapper.findComponent({ name: 'KeywordIntelligence' }).props('days')).toBe(28)
	})

	it('explains an empty period differently from an unconnected property', async () => {
		answer({ rows: [], configured: true })
		let wrapper = await render()
		expect(wrapper.find('[data-testid="search-queries-empty"]').text()).toContain('The import has not brought in rows for this period yet.')
		expect(wrapper.find('[data-testid="search-queries-download"]').element.disabled).toBe(true)

		answer(null)
		wrapper = await render()
		expect(wrapper.find('[data-testid="search-queries-empty"]').text()).toContain('Connect a Search Console property')
	})

	it('reports a failed load in an alert', async () => {
		answer({ response: { data: { error: 'Search Console is down' } } })
		let wrapper = await render()
		expect(wrapper.find('[role="alert"]').text()).toBe('Search Console is down')

		answer(new Error('x'))
		wrapper = await render()
		expect(wrapper.find('[role="alert"]').text()).toBe('Could not load search queries.')
	})

	it('reloads the chosen period, falling back to 28 days', async () => {
		answer(queries)
		const wrapper = await render()
		const control = wrapper.findComponent({ name: 'CnSegmentedControl' })

		control.vm.$emit('update:modelValue', 7)
		await flushPromises()
		expect(wrapper.vm.days).toBe(7)
		expect(wrapper.findComponent({ name: 'KeywordIntelligence' }).props('days')).toBe(7)
		control.vm.$emit('update:modelValue', 'nonsense')
		await flushPromises()
		expect(wrapper.vm.days).toBe(28)
		const queryCalls = axios.get.mock.calls.filter(([url]) => url.endsWith('/search-queries'))
		expect(queryCalls).toHaveLength(3)
	})

	it('draws the connection audit with readable networks and verdicts', async () => {
		answer(queries)
		const wrapper = await render()

		const table = wrapper.find('[data-testid="search-insight-audit-table"]')
		const first = table.find('[data-row="a-1"]')
		expect(first.find('[data-column="counterpartHandle"]').text()).toBe('@gemeente@social.example')
		expect(first.find('[data-column="network"]').text()).toBe('Mastodon')
		expect(first.find('[data-column="weFollowThem"]').text()).toBe('Yes')
		expect(first.find('[data-column="theyFollowUs"]').text()).toBe('No')
		const second = table.find('[data-row="ThreadsLike:"]')
		expect(second.find('[data-column="counterpartHandle"]').text()).toBe('—')
		expect(second.find('[data-column="network"]').text()).toBe('ThreadsLike')
		expect(second.find('[data-column="weFollowThem"]').text()).toBe('Cannot be checked')
	})

	it('says no connections were checked, and checks again on request', async () => {
		answer(queries, { rows: [] })
		const wrapper = await render()
		expect(wrapper.text()).toContain('No connections checked yet.')

		answer(queries, audit)
		await wrapper.find('[data-testid="search-insight-audit-rerun"]').trigger('click')
		await flushPromises()
		expect(axios.get).toHaveBeenLastCalledWith('/index.php/apps/pipelinq/api/marketing/connection-audit', { params: { refresh: 'true' } })
		expect(dialogs.showSuccess).toHaveBeenCalledWith('The connection audit ran again.')
		expect(wrapper.find('[data-testid="search-insight-audit-table"]').exists()).toBe(true)

		answer(queries, new Error('x'))
		await wrapper.find('[data-testid="search-insight-audit-rerun"]').trigger('click')
		await flushPromises()
		expect(dialogs.showError).toHaveBeenCalledWith('Could not load the connection audit.')
		expect(wrapper.text()).toContain('No connections checked yet.')
		expect(wrapper.vm.auditRunning).toBe(false)
	})

	it('downloads the rows in the order the table reports', async () => {
		answer(queries)
		const wrapper = await render()

		await wrapper.find('[data-testid="search-queries-download"]').trigger('click')
		expect(csv.downloadCsv.mock.calls[0][1].map((row) => row.query)).toEqual(['agenda', 'crm'])

		wrapper.findComponent({ name: 'CnDataTable' }).vm.$emit('sort', { key: 'query', order: 'asc' })
		await wrapper.find('[data-testid="search-queries-download"]').trigger('click')
		expect(csv.downloadCsv.mock.calls[1]).toEqual([
			'search-queries.csv',
			[queries.rows[1], queries.rows[0]],
			['query', 'clicks', 'impressions', 'ctr', 'position', 'pages'],
		])

		wrapper.findComponent({ name: 'CnDataTable' }).vm.$emit('sort', {})
		await wrapper.find('[data-testid="search-queries-download"]').trigger('click')
		expect(csv.downloadCsv.mock.calls[2][1]).toBe(wrapper.vm.rows)
	})
})
