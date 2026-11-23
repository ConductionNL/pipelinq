// @vitest-environment jsdom
/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 * SPDX-License-Identifier: EUPL-1.2
 *
 * RoutingSuggestionPanel, mounted, on its own: the query it sends, the
 * loading, error and empty states, the workload marks at each threshold,
 * names and capacities falling back, the assigned colleague, refreshing
 * when the record or category changes or Refresh is pressed, and Assign.
 */

import { flushPromises, mount } from '@vue/test-utils'
import { beforeEach, describe, expect, it, vi } from 'vitest'
import { h } from 'vue'

const axiosGet = vi.hoisted(() => vi.fn())

vi.mock('@nextcloud/axios', () => ({ default: { get: axiosGet } }))
vi.mock('@nextcloud/router', () => ({ generateUrl: (path) => '/index.php' + path }))
vi.mock('vue-material-design-icons/Check.vue', () => ({ default: { render: () => null } }))
vi.mock('vue-material-design-icons/Refresh.vue', () => ({ default: { render: () => null } }))
vi.mock('@nextcloud/vue', () => ({
	NcButton: {
		name: 'NcButton',
		props: { disabled: { default: false } },
		emits: ['click'],
		render() {
			return h('button', { disabled: this.disabled, onClick: () => this.$emit('click') }, [this.$slots.icon?.(), this.$slots.default?.()])
		},
	},
	NcLoadingIcon: { render: () => h('i', { class: 'loading' }) },
}))

const { default: RoutingSuggestionPanel } = await import('../../src/components/RoutingSuggestionPanel.vue')

const t = (app, text, vars = {}) => String(text).replace(/\{(\w+)\}/g, (m, k) => (k in vars ? String(vars[k]) : m))

/**
 * Mount the panel.
 *
 * @param {object} props Extra props.
 * @return {object} The wrapper.
 */
function open(props = {}) {
	return mount(RoutingSuggestionPanel, {
		props: { requestId: 'req-1', ...props },
		global: { mocks: { t } },
	})
}

/**
 * Let the server answer with these suggestions.
 *
 * @param {Array<object>} suggestions The suggestions.
 * @param {number} atCapacity Agents at capacity.
 * @return {void}
 */
const answer = (suggestions, atCapacity = 0) => axiosGet.mockResolvedValue({ data: { suggestions, atCapacity } })

const rows = (wrapper) => wrapper.findAll('.agent-suggestion')

describe('RoutingSuggestionPanel states', () => {
	beforeEach(() => {
		axiosGet.mockReset()
		vi.spyOn(console, 'error').mockImplementation(() => {})
	})

	it('asks for suggestions for the entity, showing a spinner and disabling Refresh meanwhile', async () => {
		let release
		axiosGet.mockReturnValue(new Promise((resolve) => { release = resolve }))
		const wrapper = open({ entityType: 'ticket' })

		expect(axiosGet).toHaveBeenCalledWith('/index.php/apps/pipelinq/api/routing/suggestions', {
			params: { entityType: 'ticket', entityId: 'req-1' },
		})
		await wrapper.vm.$nextTick()
		expect(wrapper.find('.loading').exists()).toBe(true)
		expect(wrapper.find('.routing-panel__refresh').attributes('disabled')).toBeDefined()

		release({ data: { suggestions: [] } })
		await flushPromises()
		expect(wrapper.find('.loading').exists()).toBe(false)
		expect(wrapper.find('.routing-panel__refresh').attributes('disabled')).toBeUndefined()
	})

	it('says when no colleague matches, also for an answer without a list', async () => {
		axiosGet.mockResolvedValue({ data: { suggestions: 'none' } })
		const wrapper = open()
		await flushPromises()

		expect(wrapper.find('.routing-panel__empty').text()).toBe('No agents with matching skills')
		expect(wrapper.find('.routing-panel__note').exists()).toBe(false)
	})

	it('treats an answer without a body as no suggestions', async () => {
		axiosGet.mockResolvedValue({})
		const wrapper = open()
		await flushPromises()

		expect(wrapper.find('.routing-panel__empty').exists()).toBe(true)
	})

	it('shows an alert when loading fails', async () => {
		axiosGet.mockRejectedValue(new Error('offline'))
		const wrapper = open()
		await flushPromises()

		expect(wrapper.find('[role="alert"]').text()).toBe('Failed to load suggestions')
		expect(console.error).toHaveBeenCalledWith('Error loading routing suggestions:', expect.any(Error))
		expect(wrapper.find('.routing-panel__list').exists()).toBe(false)
	})

	it('marks workload at 80% and 50% of capacity, defaulting capacity to 10', async () => {
		answer([
			{ userId: 'a', displayName: 'Anna', workload: 8, maxConcurrent: 10 },
			{ userId: 'b', workload: 5 },
			{ userId: 'c', displayName: 'Cas', maxConcurrent: 4 },
		], 2)
		const wrapper = open()
		await flushPromises()

		const marks = rows(wrapper).map((row) => row.find('.agent-workload span').text())
		expect(marks).toEqual(['!!', '!', '='])
		expect(rows(wrapper).map((row) => row.find('.agent-name').text())).toEqual(['Anna', 'b', 'Cas'])
		expect(rows(wrapper)[1].find('.agent-workload').attributes('title')).toBe('5 of 10 items')
		expect(rows(wrapper)[2].find('.agent-workload').attributes('title')).toBe('0 of 4 items')
		expect(rows(wrapper)[1].find('.agent-workload').text()).toContain('5/10')
		expect(wrapper.find('.routing-panel__note').text()).toBe('2 matching agent(s) at capacity')
	})

	it('shows the matched skill and marks the current assignee instead of offering Assign', async () => {
		answer([
			{ userId: 'a', displayName: 'Anna', workload: 1, matchedSkill: 'Belastingen' },
			{ userId: 'b', displayName: 'Bas', workload: 1 },
		])
		const wrapper = open({ assignee: 'a' })
		await flushPromises()

		expect(rows(wrapper)[0].find('.skill-tag').text()).toBe('Belastingen')
		expect(rows(wrapper)[1].find('.agent-skills').exists()).toBe(false)
		expect(rows(wrapper)[0].find('.agent-assigned').text()).toBe('Assigned')
		expect(rows(wrapper)[0].find('button').exists()).toBe(false)
		expect(rows(wrapper)[1].find('button').attributes('aria-label')).toBe('Assign to Bas')
	})

	it('emits the colleague to assign, and disables Assign while assigning', async () => {
		answer([{ userId: 'b', workload: 0 }])
		const wrapper = open()
		await flushPromises()

		await rows(wrapper)[0].find('button').trigger('click')
		expect(wrapper.emitted('assigned')).toEqual([['b']])

		await wrapper.setProps({ assigning: true })
		expect(rows(wrapper)[0].find('button').attributes('disabled')).toBeDefined()
	})

	it('reloads when the record or the category changes, and on Refresh', async () => {
		answer([])
		const wrapper = open()
		await flushPromises()

		await wrapper.setProps({ requestId: 'req-2' })
		await flushPromises()
		await wrapper.setProps({ category: 'afval' })
		await flushPromises()
		await wrapper.find('.routing-panel__refresh').trigger('click')
		await flushPromises()

		expect(axiosGet.mock.calls.map(([, config]) => config.params.entityId)).toEqual(['req-1', 'req-2', 'req-2', 'req-2'])
	})
})
