// @vitest-environment jsdom
/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 * SPDX-License-Identifier: EUPL-1.2
 *
 * How the keyword intelligence page asks for its proposals over a date
 * window, fills each section (or its empty default), reports a failed read,
 * and records a confirmed keyword target. HTTP is mocked.
 *
 * @spec openspec/specs/commercial-dashboard/spec.md
 */

import { flushPromises, mount } from '@vue/test-utils'
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import { h } from 'vue'

const axiosGet = vi.hoisted(() => vi.fn())

vi.mock('@nextcloud/axios', () => ({ default: { get: axiosGet } }))
vi.mock('@nextcloud/router', () => ({ generateUrl: (p) => p }))
vi.mock('../../src/modals/KeywordTargetConfirmModal.vue', () => ({
	default: { name: 'KeywordTargetConfirmModal', render: () => h('div') },
}))
vi.mock('@conduction/nextcloud-vue', () => ({
	CnDataTable: { name: 'CnDataTable', inheritAttrs: false, render: () => h('div') },
}))
vi.mock('@nextcloud/vue', () => {
	const stub = (name) => ({
		name,
		inheritAttrs: false,
		render() {
			return h('div', { 'data-name': name }, this.$slots.default?.())
		},
	})
	return {
		NcButton: stub('NcButton'),
		NcEmptyContent: stub('NcEmptyContent'),
		NcLoadingIcon: stub('NcLoadingIcon'),
		NcNoteCard: stub('NcNoteCard'),
		NcSelect: stub('NcSelect'),
	}
})

globalThis.t = (_app, text) => text

const { default: KeywordIntelligence } = await import('../../src/views/marketing/KeywordIntelligence.vue')

/**
 * Mount the page.
 *
 * @param {object} props The props.
 * @return {object} The wrapper.
 */
function mountPage(props = {}) {
	return mount(KeywordIntelligence, { props, global: { mocks: { t: (_app, text) => text } } })
}

beforeEach(() => {
	vi.useFakeTimers({ toFake: ['Date'] })
	vi.setSystemTime(new Date('2026-11-13T12:00:00Z'))
	axiosGet.mockReset()
	axiosGet.mockResolvedValue({ data: {} })
})

afterEach(() => {
	vi.useRealTimers()
})

describe('KeywordIntelligence window', () => {
	it('asks for the last 28 days by default', async () => {
		const wrapper = mountPage()
		await flushPromises()
		expect(wrapper.vm.window).toEqual({ days: 28, label: 'Last 28 days' })
		expect(axiosGet).toHaveBeenCalledWith('/apps/pipelinq/api/marketing/keyword-proposals', {
			params: { from: '2026-10-16', to: '2026-11-13' },
		})
	})

	it('uses the window picked on the page', async () => {
		const wrapper = mountPage()
		await flushPromises()
		wrapper.vm.window = wrapper.vm.windowOptions[1]
		expect(wrapper.vm.windowDays()).toBe(90)
		await wrapper.vm.fetchProposals()
		expect(axiosGet.mock.calls.at(-1)[1].params).toEqual({ from: '2026-08-15', to: '2026-11-13' })
	})

	it('lets a host set the days and refetches when they change', async () => {
		const wrapper = mountPage({ embedded: true, days: 7 })
		await flushPromises()
		expect(axiosGet.mock.calls[0][1].params.from).toBe('2026-11-06')
		await wrapper.setProps({ days: 14 })
		await flushPromises()
		expect(axiosGet).toHaveBeenCalledTimes(2)
		expect(axiosGet.mock.calls[1][1].params.from).toBe('2026-10-30')
	})

	it('falls back to 28 days without any window', () => {
		const wrapper = mountPage()
		wrapper.vm.window = null
		expect(wrapper.vm.windowDays()).toBe(28)
	})
})

describe('KeywordIntelligence proposals', () => {
	it('fills every section from the answer', async () => {
		const data = {
			buckets: [{ queries: 3 }],
			strikingDistance: [{ query: 'crm' }],
			cannibalisation: [{ query: 'pipeline' }],
			gaps: [{ query: 'how to' }],
			crawl: { crawled: true, reason: '' },
			confirmedTerms: ['crm'],
			minImpressions: 50,
			from: '2026-10-16',
			to: '2026-11-13',
		}
		axiosGet.mockResolvedValue({ data })
		const wrapper = mountPage()
		await flushPromises()
		for (const [key, value] of Object.entries(data)) {
			expect(wrapper.vm[key]).toEqual(value)
		}
		expect(wrapper.vm.isEmpty).toBe(false)
		expect(wrapper.vm.loading).toBe(false)
		expect(wrapper.vm.error).toBe('')
	})

	it('uses empty defaults for missing sections', async () => {
		axiosGet.mockResolvedValue({ data: null })
		const wrapper = mountPage()
		await flushPromises()
		expect(wrapper.vm.buckets).toEqual([])
		expect(wrapper.vm.crawl).toEqual({ crawled: false, reason: '' })
		expect(wrapper.vm.minImpressions).toBe(0)
		expect(wrapper.vm.from).toBe('')
		expect(wrapper.vm.isEmpty).toBe(true)
	})

	it('counts buckets without queries as empty', async () => {
		axiosGet.mockResolvedValue({ data: { buckets: [{ queries: 0 }, { queries: 0 }] } })
		const wrapper = mountPage()
		await flushPromises()
		expect(wrapper.vm.isEmpty).toBe(true)
	})

	it('shows the server error and empties the buckets when the read fails', async () => {
		axiosGet.mockResolvedValueOnce({ data: { buckets: [{ queries: 1 }] } })
		const wrapper = mountPage()
		await flushPromises()
		axiosGet.mockRejectedValueOnce({ response: { data: { error: 'Not connected' } } })
		await wrapper.vm.fetchProposals()
		expect(wrapper.vm.buckets).toEqual([])
		expect(wrapper.vm.error).toBe('Not connected')
		expect(wrapper.vm.loading).toBe(false)
	})

	it('falls back to its own message without a server error', async () => {
		axiosGet.mockRejectedValue(new Error('network'))
		const wrapper = mountPage()
		await flushPromises()
		expect(wrapper.vm.error).toBe('Could not load keyword proposals.')
	})
})

describe('KeywordIntelligence confirming a target', () => {
	it('opens the confirmation for a proposal and records the term in lower case', async () => {
		axiosGet.mockResolvedValue({ data: { confirmedTerms: ['crm'] } })
		const wrapper = mountPage()
		await flushPromises()
		const proposal = { query: 'Sales Pipeline' }
		wrapper.vm.openConfirm(proposal, 'gap')
		expect(wrapper.vm.confirming).toEqual({ proposal, kind: 'gap' })
		wrapper.vm.onConfirmed('Sales Pipeline')
		expect(wrapper.vm.confirmedTerms).toEqual(['crm', 'sales pipeline'])
		expect(wrapper.vm.confirming).toBeNull()
	})
})

describe('KeywordIntelligence tables', () => {
	it('right-aligns the number columns and leaves the action column unsorted', () => {
		const wrapper = mountPage()
		expect(wrapper.vm.strikingColumns.map((c) => c.key)).toEqual(['query', 'position', 'impressions', 'ctr', 'shortfall', 'target'])
		expect(wrapper.vm.strikingColumns[1]).toEqual({
			key: 'position',
			label: 'Position',
			type: 'number',
			sortable: true,
			class: 'keyword-intel__num',
			cellClass: 'keyword-intel__num',
		})
		expect(wrapper.vm.gapColumns.map((c) => c.key)).toEqual(['query', 'impressions', 'position', 'target'])
		expect(wrapper.vm.gapColumns.at(-1)).toEqual({ key: 'target', label: '', sortable: false, filterable: false })
	})
})
