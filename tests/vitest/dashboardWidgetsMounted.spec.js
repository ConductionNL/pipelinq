// @vitest-environment jsdom
/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Three dashboard widgets mounted: My work (and the refresh mixin under it),
 * the report export panel and the knowledge base widget. Checked for what
 * they show and what they ask the server.
 *
 * @spec openspec/specs/dashboard/spec.md
 */

import { flushPromises, mount } from '@vue/test-utils'
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import { h, nextTick, reactive } from 'vue'

const signal = vi.hoisted(() => ({ value: null }))
const http = vi.hoisted(() => ({ get: vi.fn() }))
const settings = vi.hoisted(() => ({ store: { config: null }, fail: false }))

vi.hoisted(() => {
	globalThis.t = (app, text, vars = {}) => String(text).replace(/\{(\w+)\}/g, (m, k) => (k in vars ? String(vars[k]) : m))
	globalThis.n = (app, one, many, count) => (count === 1 ? one : many).replace('%n', String(count))
	globalThis.OC = { requestToken: 'tok' }
})

vi.mock('../../src/services/dashboardData.js', () => ({ getDashboardRefreshSignal: () => signal.value }))
vi.mock('../../src/store/modules/settings.js', () => ({
	useSettingsStore: () => {
		if (settings.fail) {
			throw new Error('no pinia')
		}
		return settings.store
	},
}))
vi.mock('@nextcloud/axios', () => ({ default: http }))
vi.mock('@nextcloud/router', () => ({ generateUrl: (path) => '/index.php' + path }))

/**
 * A stub that renders its slots and keeps its props.
 *
 * @param {string} name The component name.
 * @param {string} [tag] The element to render.
 * @return {object} The component.
 */
function stub(name, tag = 'div') {
	return {
		name,
		inheritAttrs: false,
		props: ['label', 'variant', 'disabled', 'title', 'space', 'limit', 'showSearch', 'bare', 'to'],
		emits: ['click'],
		render() {
			return h(tag, { 'data-stub': name, onClick: () => this.$emit('click') }, [this.label, this.$slots.default?.()])
		},
	}
}

vi.mock('@conduction/nextcloud-vue', () => ({ CnStatusBadge: stub('CnStatusBadge', 'span') }))
vi.mock('@nextcloud/vue', () => ({ NcButton: stub('NcButton', 'button') }))
vi.mock('../../src/components/xwiki/XWikiWidget.vue', () => ({ default: stub('XWikiWidget') }))

const { default: MyWorkWidget } = await import('../../src/views/dashboard/widgets/MyWorkWidget.vue')
const { default: ReportExportPanel, overviewRows } = await import('../../src/views/dashboard/widgets/ReportExportPanel.vue')
const { default: XWikiDashboardWidget } = await import('../../src/views/dashboard/widgets/XWikiDashboardWidget.vue')

const RouterLink = { name: 'RouterLink', props: ['to'], render() { return h('a', { 'data-to': JSON.stringify(this.to) }, this.$slots.default?.()) } }
const mountOptions = { global: { mocks: { t: globalThis.t, n: globalThis.n }, stubs: { RouterLink, 'router-link': RouterLink } } }

/**
 * Answer the worklist request.
 *
 * @param {boolean} ok Whether the response is ok.
 * @param {object} body The JSON body.
 */
function worklist(ok, body = {}) {
	vi.stubGlobal('fetch', vi.fn(async () => ({ ok, json: async () => body })))
}

beforeEach(() => {
	vi.resetAllMocks()
	signal.value = reactive({ token: 0 })
	settings.store = { config: null }
	settings.fail = false
	vi.useFakeTimers({ toFake: ['Date'] })
	vi.setSystemTime(new Date(2026, 10, 11, 12, 0, 0))
})

afterEach(() => {
	vi.useRealTimers()
	vi.unstubAllGlobals()
})

describe('MyWorkWidget', () => {
	it('asks for the five newest items of the current user', async () => {
		worklist(true, { items: [] })
		mount(MyWorkWidget, mountOptions)
		await flushPromises()
		expect(fetch).toHaveBeenCalledWith('/index.php/apps/pipelinq/api/worklist/mine?limit=5', {
			headers: { 'Content-Type': 'application/json', requesttoken: 'tok', 'OCS-APIREQUEST': 'true' },
		})
	})

	it('says nothing is assigned on an empty list', async () => {
		worklist(true, { items: [] })
		const wrapper = mount(MyWorkWidget, mountOptions)
		expect(wrapper.text()).toBe('Loading…')
		await flushPromises()
		expect(wrapper.text()).toBe('No items assigned to you')
		expect(wrapper.vm.error).toBeNull()
	})

	it('says the items could not load when the request fails', async () => {
		const log = vi.spyOn(console, 'error').mockImplementation(() => {})
		worklist(false)
		const wrapper = mount(MyWorkWidget, mountOptions)
		await flushPromises()
		expect(wrapper.text()).toBe('Could not load your items')
		expect(wrapper.vm.error.message).toBe('Failed to fetch worklist')
		expect(wrapper.vm.loading).toBe(false)
		expect(log).toHaveBeenCalledWith('[pipelinq] dashboard widget load failed', wrapper.vm.error)
	})

	it('shows each item with its route, meta line and due pill', async () => {
		worklist(true, { total: '7', items: [
			{ id: 'l1', entityType: 'lead', title: 'Deal', dueDate: '2026-11-08' },
			{ id: 'r1', entityType: 'request', title: 'Question', dueDate: '2026-11-11', routeName: 'ComplaintDetail' },
			{ id: 'r2', entityType: 'request', title: 'Later', stageOrStatus: 'new' },
			{ id: 'l2', entityType: 'lead', title: 'Week', dueDate: '2026-11-13', routeName: 'LeadDetail' },
		] })
		const wrapper = mount(MyWorkWidget, mountOptions)
		await flushPromises()
		expect(wrapper.vm.total).toBe(7)
		const rows = wrapper.findAll('li')
		expect(rows.map((r) => r.find('.my-work__meta').text())).toEqual([
			'Lead · 3 days late',
			'Request · today',
			'Request · new',
			expect.stringMatching(/^Lead · closes /),
		])
		expect(rows.map((r) => JSON.parse(r.find('a').attributes('data-to')))).toEqual([
			{ name: 'LeadDetail', params: { id: 'l1' } },
			{ name: 'TicketDetail', params: { id: 'r1' } },
			{ name: 'TicketDetail', params: { id: 'r2' } },
			{ name: 'LeadDetail', params: { id: 'l2' } },
		])
		expect(rows.map((r) => r.find('[data-stub="CnStatusBadge"]').exists() ? r.find('[data-stub="CnStatusBadge"]').text() : null))
			.toEqual(['Late', 'Today', null, 'This week'])
	})

	it('names a lead or request with a later due date', () => {
		const ctx = { t: globalThis.t }
		expect(MyWorkWidget.methods.dueText.call(ctx, { entityType: 'request', dueDate: '2026-12-01' }, { state: 'later' })).toMatch(/^due /)
		expect(MyWorkWidget.methods.duePill.call(ctx, { isOverdue: true })).toEqual({ label: 'Late', variant: 'error' })
	})

	it('loads again when the dashboard refresh signal bumps', async () => {
		worklist(true, { items: [] })
		mount(MyWorkWidget, mountOptions)
		await flushPromises()
		signal.value.token++
		await nextTick()
		await flushPromises()
		expect(fetch).toHaveBeenCalledTimes(2)
	})
})

describe('ReportExportPanel', () => {
	it('lays the figures out against the period before', () => {
		const rows = overviewRows({ leadConversionRate: 12, contactMomentVolume: 0, previousPeriod: { leadConversionRate: 10 } }, (k) => k.toUpperCase())
		expect(rows).toEqual([
			['LEADCONVERSIONRATE', 12, 10],
			['AVGREQUESTRESOLUTIONTIME', '', ''],
			['CONTACTMOMENTVOLUME', 0, ''],
			['CUSTOMERSATISFACTIONSCORE', '', ''],
		])
		expect(overviewRows(null, (k) => k)[0]).toEqual(['leadConversionRate', '', ''])
	})

	it('downloads the overview of the chosen period as CSV', async () => {
		http.get.mockResolvedValueOnce({ data: { leadConversionRate: 40, previousPeriod: { leadConversionRate: 30 } } })
		const blobs = []
		vi.stubGlobal('URL', { createObjectURL: vi.fn((blob) => { blobs.push(blob); return 'blob:1' }), revokeObjectURL: vi.fn() })
		const click = vi.spyOn(HTMLAnchorElement.prototype, 'click').mockImplementation(() => {})
		const wrapper = mount(ReportExportPanel, { global: { ...mountOptions.global, provide: { cnDashboardDateRange: { preset: 'quarter' } } } })
		await wrapper.findAll('button')[0].trigger('click')
		await flushPromises()
		expect(http.get).toHaveBeenCalledWith('/index.php/apps/pipelinq/api/analytics/overview', { params: { period: 'quarter' } })
		expect(click).toHaveBeenCalledTimes(1)
		const text = (await blobs[0].text()).replace(/^\uFEFF/, '')
		expect(text.split('\n')).toEqual([
			'Figure;This period;The period before',
			'Lead conversion;40;30',
			'Average request turnaround;;',
			'Contacts;;',
			'Customer satisfaction;;',
		])
		expect(wrapper.vm.busy).toBe(false)
		click.mockRestore()
	})

	it('reports an export that fails, using the month without a range', async () => {
		http.get.mockRejectedValueOnce(new Error('500'))
		const wrapper = mount(ReportExportPanel, mountOptions)
		await wrapper.findAll('button')[0].trigger('click')
		await flushPromises()
		expect(http.get).toHaveBeenCalledWith('/index.php/apps/pipelinq/api/analytics/overview', { params: { period: 'month' } })
		expect(wrapper.find('[role="alert"]').text()).toBe('The report could not be exported.')
	})

	it('prints for the PDF button', async () => {
		const print = vi.fn()
		vi.stubGlobal('print', print)
		window.print = print
		const wrapper = mount(ReportExportPanel, mountOptions)
		await wrapper.findAll('button')[1].trigger('click')
		expect(print).toHaveBeenCalledTimes(1)
	})
})

describe('XWikiDashboardWidget', () => {
	it('opens the configured default space with five articles and search', () => {
		settings.store = { config: { xwiki_default_space: 'Main.Help' } }
		const widget = mount(XWikiDashboardWidget, mountOptions).findComponent({ name: 'XWikiWidget' })
		expect([widget.props('title'), widget.props('space'), widget.props('limit'), widget.props('showSearch')]).toEqual(['Knowledge base', 'Main.Help', 5, true])
	})

	it('falls back to no space without a config or a store', () => {
		expect(mount(XWikiDashboardWidget, mountOptions).findComponent({ name: 'XWikiWidget' }).props('space')).toBe('')
		settings.fail = true
		expect(mount(XWikiDashboardWidget, mountOptions).findComponent({ name: 'XWikiWidget' }).props('space')).toBe('')
	})
})
