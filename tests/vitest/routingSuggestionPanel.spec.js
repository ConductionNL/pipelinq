// @vitest-environment jsdom
/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 * SPDX-License-Identifier: EUPL-1.2
 *
 * The routing suggestions panel: what it asks the server, how it lists the
 * agents and their workload sign, assigning one, reloading when the request
 * or its category changes, and a failed load.
 */
import { flushPromises, mount } from '@vue/test-utils'
import { beforeEach, describe, expect, it, vi } from 'vitest'

const get = vi.fn()
vi.mock('@nextcloud/axios', () => ({ default: { get: (...args) => get(...args) } }))
vi.mock('@nextcloud/router', () => ({ generateUrl: (p) => '/index.php' + p }))
vi.mock('@nextcloud/vue', () => ({
	NcButton: { name: 'NcButton', emits: ['click'], template: '<button @click="$emit(\'click\')"><slot /></button>' },
	NcLoadingIcon: { name: 'NcLoadingIcon', template: '<span class="loading" />' },
}))
vi.mock('vue-material-design-icons/Check.vue', () => ({ default: { template: '<i />' } }))
vi.mock('vue-material-design-icons/Refresh.vue', () => ({ default: { template: '<i />' } }))

globalThis.t = (_app, text) => text

const { default: RoutingSuggestionPanel } = await import('../../src/components/RoutingSuggestionPanel.vue')

function mountPanel(props = {}) {
	return mount(RoutingSuggestionPanel, {
		props: { requestId: 'r-1', ...props },
		global: { mocks: { t: (_app, text) => text, n: (_app, one, many, count) => (count === 1 ? one : many) } },
	})
}

describe('RoutingSuggestionPanel', () => {
	beforeEach(() => {
		get.mockReset()
		vi.spyOn(console, 'error').mockImplementation(() => {})
	})

	it('asks for suggestions for this request and lists them', async () => {
		get.mockResolvedValue({ data: { suggestions: [{ userId: 'anna', displayName: 'Anna', workload: 1, maxConcurrent: 10 }], atCapacity: 2 } })
		const wrapper = mountPanel({ entityType: 'lead' })
		await flushPromises()

		expect(get).toHaveBeenCalledWith('/index.php/apps/pipelinq/api/routing/suggestions', { params: { entityType: 'lead', entityId: 'r-1' } })
		expect(wrapper.findAll('.agent-suggestion')).toHaveLength(1)
		expect(wrapper.vm.atCapacityCount).toBe(2)
	})

	it('reads an answer without a list as no suggestions', async () => {
		get.mockResolvedValue({ data: { suggestions: 'none' } })
		const wrapper = mountPanel()
		await flushPromises()

		expect(wrapper.vm.suggestions).toEqual([])
		expect(wrapper.vm.atCapacityCount).toBe(0)
		expect(wrapper.find('.routing-panel__empty').exists()).toBe(true)
	})

	it('shows an error when the suggestions cannot be loaded', async () => {
		get.mockRejectedValue(new Error('500'))
		const wrapper = mountPanel()
		await flushPromises()

		expect(wrapper.find('[role="alert"]').text()).toBe('Failed to load suggestions')
		expect(wrapper.vm.loading).toBe(false)
	})

	it('reloads when the request or its category changes', async () => {
		get.mockResolvedValue({ data: {} })
		const wrapper = mountPanel()
		await flushPromises()

		await wrapper.setProps({ requestId: 'r-2' })
		await flushPromises()
		await wrapper.setProps({ category: 'waste' })
		await flushPromises()

		expect(get).toHaveBeenCalledTimes(3)
		expect(get.mock.calls[1][1].params.entityId).toBe('r-2')
	})

	it('emits the chosen agent', async () => {
		get.mockResolvedValue({ data: {} })
		const wrapper = mountPanel()
		await flushPromises()

		wrapper.vm.assign({ userId: 'piet' })

		expect(wrapper.emitted('assigned')).toEqual([['piet']])
	})

	it('signs the workload without colour: high, medium or low', () => {
		const icon = RoutingSuggestionPanel.methods.workloadIcon

		expect(icon({ workload: 8, maxConcurrent: 10 })).toBe('!!')
		expect(icon({ workload: 5, maxConcurrent: 10 })).toBe('!')
		expect(icon({ workload: 4, maxConcurrent: 10 })).toBe('=')
		expect(icon({ workload: 8 })).toBe('!!')
		expect(icon({})).toBe('=')
	})
})
