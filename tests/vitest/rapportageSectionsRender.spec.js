// @vitest-environment jsdom
/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 * SPDX-License-Identifier: EUPL-1.2
 *
 * The three channel/agent sections of the rapportage page, rendered: what
 * each fetches, how it turns the answer into rows, its empty state after an
 * empty or failed fetch, the SLA traffic light, the CSV export link, and the
 * sortable agent table with its team summary.
 *
 * @spec openspec/specs/contactmomenten-rapportage/spec.md#requirement-kpi-dashboard
 */

import { flushPromises, mount } from '@vue/test-utils'
import { beforeEach, describe, expect, it, vi } from 'vitest'
import { h } from 'vue'

const axiosMock = vi.hoisted(() => ({ get: vi.fn() }))

vi.mock('@nextcloud/axios', () => ({ default: axiosMock }))
vi.mock('@nextcloud/router', () => ({ generateUrl: (path) => '/index.php' + path }))
vi.mock('@nextcloud/vue', () => ({
	NcLoadingIcon: { name: 'NcLoadingIcon', render: () => h('div', { class: 'loading-stub' }) },
	NcButton: {
		name: 'NcButton',
		props: ['variant'],
		emits: ['click'],
		setup: (props, { slots, emit }) => () => h('button', { onClick: () => emit('click') }, slots.default?.()),
	},
}))

globalThis.t = (app, text) => text
const mountOptions = (props = {}) => ({ props, global: { mocks: { t: globalThis.t } } })

const { default: ChannelComparisonSection } = await import('../../src/components/rapportage/ChannelComparisonSection.vue')
const { default: ChannelDistributionSection } = await import('../../src/components/rapportage/ChannelDistributionSection.vue')
const { default: AgentPerformanceSection } = await import('../../src/components/rapportage/AgentPerformanceSection.vue')

const CHANNELS = '/index.php/apps/pipelinq/api/rapportage/channels'
const SLA = '/index.php/apps/pipelinq/api/rapportage/sla'
const AGENTS = '/index.php/apps/pipelinq/api/rapportage/agents'

/**
 * Answer each GET by URL.
 *
 * @param {object} byUrl URL to response body, or an Error to reject with.
 * @return {void}
 */
function serve(byUrl) {
	axiosMock.get.mockImplementation((url) => {
		const body = byUrl[url]
		return body instanceof Error ? Promise.reject(body) : Promise.resolve({ data: body ?? {} })
	})
}

beforeEach(() => {
	axiosMock.get.mockReset()
})

describe('ChannelComparisonSection', () => {
	it('asks for the period and granularity and reads the SLA targets', async () => {
		serve({ [CHANNELS]: { distribution: {} }, [SLA]: { targets: {} } })
		mount(ChannelComparisonSection, mountOptions({ period: 'week', granularity: 'hourly' }))
		await flushPromises()

		expect(axiosMock.get).toHaveBeenCalledWith(CHANNELS, { params: { period: 'week', granularity: 'hourly' } })
		expect(axiosMock.get).toHaveBeenCalledWith(SLA)
	})

	it('renders one row per channel with its own or the default SLA target', async () => {
		serve({
			[CHANNELS]: { distribution: { telefoon: 12, email: 0, fax: 3 } },
			[SLA]: { targets: { telefoon: { target_percent: 80 } } },
		})
		const wrapper = mount(ChannelComparisonSection, mountOptions())
		await flushPromises()

		const rows = wrapper.findAll('tbody tr').map((tr) => tr.findAll('td').map((td) => td.text()))
		expect(rows).toEqual([
			['telefoon', '12', '0%', '80%'],
			['email', '0', '0%', '0%'],
			['fax', '3', '0%', '90%'],
		])
		expect(wrapper.vm.channelRows.map((r) => r.slaStatus)).toEqual(['green', 'red', 'green'])
		const sla = wrapper.findAll('tbody tr').map((tr) => tr.findAll('td')[3].classes())
		expect(sla[0]).toContain('channel-comparison__sla--green')
		expect(sla[1]).toContain('channel-comparison__sla--red')
		expect(wrapper.find('.channel-comparison__dot').attributes('style')).toContain('background: rgb(76, 132, 219)')
		expect(wrapper.vm.channelRows[2].color).toBe('#95a5a6')
	})

	it('treats missing distribution and targets as no channels', async () => {
		serve({ [CHANNELS]: {}, [SLA]: {} })
		const wrapper = mount(ChannelComparisonSection, mountOptions())
		await flushPromises()

		expect(wrapper.find('.channel-comparison__empty').text()).toBe('No data available for the selected period')
		expect(wrapper.find('table').exists()).toBe(false)
	})

	it('shows the empty state when either fetch fails, and stops loading', async () => {
		serve({ [CHANNELS]: { distribution: { telefoon: 1 } }, [SLA]: new Error('500') })
		const wrapper = mount(ChannelComparisonSection, mountOptions())
		await wrapper.vm.$nextTick()
		expect(wrapper.find('.loading-stub').exists()).toBe(true)
		await flushPromises()

		expect(wrapper.vm.loading).toBe(false)
		expect(wrapper.vm.channelRows).toEqual([])
		expect(wrapper.find('.channel-comparison__empty').exists()).toBe(true)
	})

	it('refetches when the granularity changes', async () => {
		serve({ [CHANNELS]: { distribution: {} }, [SLA]: {} })
		const wrapper = mount(ChannelComparisonSection, mountOptions())
		await flushPromises()
		axiosMock.get.mockClear()

		await wrapper.setProps({ granularity: 'weekly' })
		await flushPromises()

		expect(axiosMock.get).toHaveBeenCalledWith(CHANNELS, { params: { period: 'month', granularity: 'weekly' } })
	})
})

describe('ChannelDistributionSection', () => {
	it('renders each channel with its share of the total, rounded', async () => {
		serve({ [CHANNELS]: { distribution: { telefoon: 2, email: 1 } } })
		const wrapper = mount(ChannelDistributionSection, mountOptions({ period: 'week' }))
		await flushPromises()

		expect(axiosMock.get).toHaveBeenCalledWith(CHANNELS, { params: { period: 'week' } })
		const bars = wrapper.findAll('.channel-distribution__bar')
		expect(bars.map((b) => b.find('.channel-distribution__label').text())).toEqual(['telefoon', 'email'])
		expect(bars.map((b) => b.find('.channel-distribution__count').text())).toEqual(['2 (67%)', '1 (33%)'])
		expect(bars[0].find('.channel-distribution__fill').attributes('style')).toContain('width: 67%')
	})

	it('shows zero percent rather than dividing by zero when every count is zero', async () => {
		serve({ [CHANNELS]: { distribution: { telefoon: 0 } } })
		const wrapper = mount(ChannelDistributionSection, mountOptions())
		await flushPromises()

		expect(wrapper.find('.channel-distribution__count').text()).toBe('0 (0%)')
	})

	it.each([
		['an empty answer', {}],
		['a failed fetch', new Error('500')],
	])('shows the empty message after %s', async (_label, body) => {
		serve({ [CHANNELS]: body })
		const wrapper = mount(ChannelDistributionSection, mountOptions())
		await flushPromises()

		expect(wrapper.vm.loading).toBe(false)
		expect(wrapper.find('.channel-distribution__empty').text()).toBe('No contact moments registered for this period')
	})

	it('sends the browser to the CSV export', async () => {
		serve({ [CHANNELS]: {} })
		const location = { href: '' }
		vi.stubGlobal('location', location)
		const wrapper = mount(ChannelDistributionSection, mountOptions())
		await flushPromises()

		await wrapper.find('button').trigger('click')

		expect(window.location.href).toBe('/index.php/apps/pipelinq/api/rapportage/export')
		vi.unstubAllGlobals()
	})
})

describe('AgentPerformanceSection', () => {
	const agents = {
		alice: { count: 5, fcrRate: 80, avgHandlingTime: '3:10' },
		bob: { count: 9, fcrRate: 50 },
		carol: { count: 0, fcrRate: 100 },
		dave: { fcrRate: 70 },
		erin: { count: 2, avgHandlingTime: '1:05' },
	}

	/**
	 * The agent ids in table order.
	 *
	 * @param {object} wrapper The section.
	 * @return {string[]}
	 */
	const order = (wrapper) => wrapper.findAll('tbody tr').map((tr) => tr.find('td').text())

	it('lists agents with at least one contact, busiest first, with defaults filled in', async () => {
		serve({ [AGENTS]: { agents } })
		const wrapper = mount(AgentPerformanceSection, mountOptions({ period: 'month' }))
		await flushPromises()

		expect(axiosMock.get).toHaveBeenCalledWith(AGENTS, { params: { period: 'month' } })
		expect(wrapper.findAll('tbody tr').map((tr) => tr.findAll('td').map((td) => td.text()))).toEqual([
			['bob', '9', '0:00', '50%'],
			['alice', '5', '3:10', '80%'],
			['erin', '2', '1:05', '0%'],
		])
	})

	it('summarises the team: total contacts and the rounded mean FCR', async () => {
		serve({ [AGENTS]: { agents } })
		const wrapper = mount(AgentPerformanceSection, mountOptions())
		await flushPromises()

		expect(wrapper.findAll('.agent-performance__summary-value').map((v) => v.text())).toEqual(['16', '43%'])
	})

	it('re-sorts on a header click and flips direction on a second click', async () => {
		serve({ [AGENTS]: { agents } })
		const wrapper = mount(AgentPerformanceSection, mountOptions())
		await flushPromises()
		const header = (label) => wrapper.findAll('th').find((th) => th.text() === label)

		await header('Agent').trigger('click')
		expect(order(wrapper)).toEqual(['erin', 'bob', 'alice'])
		expect(header('Agent').classes()).toContain('sort-active')

		await header('Agent').trigger('click')
		expect(order(wrapper)).toEqual(['alice', 'bob', 'erin'])

		await header('FCR Rate').trigger('click')
		expect(order(wrapper)).toEqual(['alice', 'bob', 'erin'])
		await header('FCR Rate').trigger('click')
		expect(order(wrapper)).toEqual(['erin', 'bob', 'alice'])

		await header('Avg Time').trigger('click')
		expect(order(wrapper)).toEqual(['alice', 'erin', 'bob'])
		expect(header('Contacts').classes()).not.toContain('sort-active')
	})

	it.each([
		['no agents key', {}],
		['a failed fetch', new Error('500')],
	])('shows the empty message after %s', async (_label, body) => {
		serve({ [AGENTS]: body })
		const wrapper = mount(AgentPerformanceSection, mountOptions())
		await flushPromises()

		expect(wrapper.vm.loading).toBe(false)
		expect(wrapper.find('.agent-performance__empty').text()).toBe('No agent data available')
		expect(wrapper.vm.teamStats).toEqual({ totalContacts: 0, avgFcr: 0 })
	})
})
