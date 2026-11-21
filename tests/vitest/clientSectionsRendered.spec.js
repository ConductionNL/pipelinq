// @vitest-environment jsdom
/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 * SPDX-License-Identifier: EUPL-1.2
 *
 * The client's history summary card and risk panel as they render: the
 * availability check, each state of the summary card, the record-count line,
 * and the risk badge, reasons and record links.
 *
 * @spec openspec/changes/clients-history-summary-and-risk/tasks.md#task-2.3
 */

import { flushPromises, mount } from '@vue/test-utils'
import { beforeEach, describe, expect, it, vi } from 'vitest'
import { h } from 'vue'

const api = vi.hoisted(() => ({ fetchSummary: vi.fn(), fetchSummaryAvailable: vi.fn() }))

/**
 * A stub that renders its default and icon slots and emits click.
 *
 * @param {string} name The component name.
 * @param {string} tag The element.
 * @return {object} The stub.
 */
function stub(name, tag = 'div') {
	return {
		name,
		inheritAttrs: false,
		props: ['disabled', 'size'],
		emits: ['click'],
		render() {
			return h(tag, { ...this.$attrs, 'data-stub': name, disabled: this.disabled, onClick: () => this.$emit('click') }, [this.$slots.icon?.(), this.$slots.default?.()])
		},
	}
}

vi.mock('@nextcloud/vue', () => ({ NcButton: stub('NcButton', 'button'), NcLoadingIcon: stub('NcLoadingIcon') }))
vi.mock('../../src/services/clientHistorySummaryApi.js', () => api)

const { default: ClientHistorySummarySection } = await import('../../src/components/clients/ClientHistorySummarySection.vue')
const { default: ClientRiskSection } = await import('../../src/components/clients/ClientRiskSection.vue')

const fill = (text, vars = {}) => text.replace(/\{(\w+)\}/g, (match, key) => String(vars[key] ?? match))
const mocks = {
	t: (app, text, vars) => fill(text, vars),
	n: (app, one, many, count, vars) => fill(count === 1 ? one : many, vars),
}

/**
 * Mount the summary card.
 *
 * @param {object} options Props and the injected section context.
 * @return {Promise<object>} The wrapper after mounting settles.
 */
async function card({ props = { clientId: 'c-1' }, context = null } = {}) {
	const wrapper = mount(ClientHistorySummarySection, {
		props,
		global: { mocks, provide: { cnSectionContext: context } },
	})
	await flushPromises()
	return wrapper
}

beforeEach(() => {
	api.fetchSummary.mockReset()
	api.fetchSummaryAvailable.mockReset()
})

describe('the history summary card', () => {
	it('says summarising needs hermiq when it is not available', async () => {
		api.fetchSummaryAvailable.mockResolvedValue(false)
		const wrapper = await card()

		expect(api.fetchSummaryAvailable).toHaveBeenCalledWith('c-1')
		expect(wrapper.find('[data-testid="client-history-summary-unavailable"]').exists()).toBe(true)
		expect(wrapper.find('[data-testid="client-history-summary-run"]').exists()).toBe(false)
	})

	it('treats a failing availability check as not available', async () => {
		api.fetchSummaryAvailable.mockRejectedValue(new Error('down'))
		const wrapper = await card()

		expect(wrapper.find('[data-testid="client-history-summary-unavailable"]').exists()).toBe(true)
	})

	it('reads the client from the section context and asks nothing without one', async () => {
		api.fetchSummaryAvailable.mockResolvedValue(true)
		await card({ props: {}, context: { value: { objectId: 'c-9' } } })
		expect(api.fetchSummaryAvailable).toHaveBeenCalledWith('c-9')

		api.fetchSummaryAvailable.mockClear()
		await card({ props: {} })
		expect(api.fetchSummaryAvailable).not.toHaveBeenCalled()
	})

	it('writes the summary with how many records it read', async () => {
		api.fetchSummaryAvailable.mockResolvedValue(true)
		api.fetchSummary.mockResolvedValue({ available: true, summary: 'Two calls about parking.', recordsRead: 2, generatedAt: '2026-11-13T12:00:00Z' })
		const wrapper = await card()

		expect(wrapper.get('[data-testid="client-history-summary-run"]').text()).toBe('Summarise')
		await wrapper.get('[data-testid="client-history-summary-run"]').trigger('click')
		await flushPromises()

		expect(api.fetchSummary).toHaveBeenCalledWith('c-1')
		expect(wrapper.get('[data-testid="client-history-summary-text"]').text()).toBe('Two calls about parking.')
		expect(wrapper.get('[data-testid="client-history-summary-meta"]').text())
			.toBe(`Generated from 2 records at ${new Date('2026-11-13T12:00:00Z').toLocaleString()}.`)
		expect(wrapper.get('[data-testid="client-history-summary-run"]').text()).toBe('Summarise again')
	})

	it('says nothing happened when no record was read, with a dash for a missing time', async () => {
		api.fetchSummaryAvailable.mockResolvedValue(true)
		api.fetchSummary.mockResolvedValue({ available: true, summary: null, recordsRead: 0, generatedAt: null })
		const wrapper = await card()

		await wrapper.get('[data-testid="client-history-summary-run"]').trigger('click')
		await flushPromises()

		expect(wrapper.text()).toContain('Nothing you may read happened with this client in the last 90 days.')
		expect(wrapper.get('[data-testid="client-history-summary-meta"]').text()).toBe('Generated from 0 records at -.')
	})

	it('says the summary could not be written when records were read but no text came back', async () => {
		api.fetchSummaryAvailable.mockResolvedValue(true)
		api.fetchSummary.mockResolvedValue({ available: true, summary: null, recordsRead: 1, generatedAt: '2026-11-13T12:00:00Z' })
		const wrapper = await card()

		await wrapper.get('[data-testid="client-history-summary-run"]').trigger('click')
		await flushPromises()

		expect(wrapper.text()).toContain('No summary could be written. Try again later.')
		expect(wrapper.get('[data-testid="client-history-summary-meta"]').text()).toContain('Generated from 1 record at')
	})

	it('shows the failure as an alert', async () => {
		api.fetchSummaryAvailable.mockResolvedValue(true)
		api.fetchSummary.mockRejectedValue(new Error('down'))
		const wrapper = await card()

		await wrapper.get('[data-testid="client-history-summary-run"]').trigger('click')
		await flushPromises()

		expect(wrapper.get('[role="alert"]').text()).toBe('The summary could not be written.')
	})

	it('forgets the summary and checks again when the client changes', async () => {
		api.fetchSummaryAvailable.mockResolvedValue(true)
		api.fetchSummary.mockResolvedValue({ available: true, summary: 'Old.', recordsRead: 1, generatedAt: null })
		const wrapper = await card()
		await wrapper.get('[data-testid="client-history-summary-run"]').trigger('click')
		await flushPromises()

		await wrapper.setProps({ clientId: 'c-2' })
		await flushPromises()

		expect(api.fetchSummaryAvailable).toHaveBeenLastCalledWith('c-2')
		expect(wrapper.find('[data-testid="client-history-summary-text"]').exists()).toBe(false)
	})
})

describe('the risk panel', () => {
	/**
	 * Mount the risk panel.
	 *
	 * @param {object|null} risk The churnRisk.
	 * @return {object} The wrapper.
	 */
	function panel(risk) {
		return mount(ClientRiskSection, {
			props: { risk },
			global: {
				mocks: { ...mocks, $router: { getRoutes: () => [{ name: 'TicketDetail' }] } },
				stubs: { RouterLink: { props: ['to'], render() { return h('a', { 'data-to': JSON.stringify(this.to) }, this.$slots.default?.()) } } },
			},
		})
	}

	it('says the risk is not computed yet without a level', () => {
		expect(panel(null).text()).toBe('The risk of this client has not been computed yet.')
	})

	it('shows the level, score and date, and says there are no warning signals', () => {
		const wrapper = panel({ level: 'low', score: 5, computedAt: '2026-11-01T00:00:00Z', reasons: [] })

		expect(wrapper.get('[data-testid="client-risk-badge"]').classes()).toContain('client-risk__badge--low')
		expect(wrapper.text()).toContain(`Score 5, computed ${new Date('2026-11-01T00:00:00Z').toLocaleDateString()}`)
		expect(wrapper.text()).toContain('No warning signals.')
	})

	it('lists the reasons with their points and links only records the app can open', () => {
		const wrapper = panel({
			level: 'high',
			computedAt: 'not a date',
			reasons: [
				{ signal: 'sla-breached', points: 15, records: ['t1'] },
				{ signal: 'contract-churned', points: 40, records: ['c1'] },
			],
		})
		const reasons = wrapper.findAll('[data-testid="client-risk-reason"]')

		expect(wrapper.text()).toContain('Score 0, computed -')
		expect(reasons).toHaveLength(2)
		expect(reasons[0].text()).toContain('+40')
		expect(reasons[0].find('a').exists()).toBe(false)
		expect(reasons[1].get('a').attributes('data-to')).toBe(JSON.stringify({ name: 'TicketDetail', params: { id: 't1' } }))
		expect(reasons[1].get('a').text()).toBe('Open record')
	})
})
