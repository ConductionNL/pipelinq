// @vitest-environment jsdom
/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 * SPDX-License-Identifier: EUPL-1.2
 *
 * The weekly review page's other states: composing it again from the header
 * button, a failed read or compose that says why, the empty state, a falling
 * figure's arrow, the agent mark without a readable moment, an unreadable
 * week start kept as stored, and the topic ideas as badges or their absence.
 *
 * @spec openspec/specs/marketing-integrated-campaigns/spec.md#requirement-the-weekly-review-reads-four-sources-and-names-the-ones-with-nothing-in-them
 */
import { flushPromises, mount } from '@vue/test-utils'
import { beforeEach, describe, expect, it, vi } from 'vitest'
import { h } from 'vue'

const api = vi.hoisted(() => ({
	fetchWeeklyReview: vi.fn(),
	generateWeeklyReview: vi.fn(),
}))
vi.mock('../../src/services/journeysApi.js', () => api)
vi.mock('@nextcloud/l10n', () => ({ getCanonicalLocale: () => 'en-GB' }))
function icon (name) {
  return {
	default: {
		name,
		props: ['size', 'title'],
		render: () => h('i', { class: name }),
	},
}
}
vi.mock('vue-material-design-icons/ArrowDown.vue', () => icon('ArrowDown'))
vi.mock('vue-material-design-icons/ArrowUp.vue', () => icon('ArrowUp'))
vi.mock('vue-material-design-icons/CalendarWeek.vue', () => icon('CalendarWeek'))
vi.mock('vue-material-design-icons/Refresh.vue', () => icon('Refresh'))
vi.mock('@nextcloud/vue', () => ({
	NcButton: {
		name: 'NcButton',
		props: ['disabled', 'variant'],
		emits: ['click'],
		render() {
			return h(
				'button',
				{ disabled: this.disabled, onClick: () => this.$emit('click') },
				this.$slots.default?.(),
			)
		},
	},
	NcEmptyContent: {
		name: 'NcEmptyContent',
		props: ['name', 'description'],
		render() {
			return h('div', [this.$slots.icon?.(), this.name])
		},
	},
	NcLoadingIcon: {
		name: 'NcLoadingIcon',
		render: () => h('div', { class: 'loading' }),
	},
	NcNoteCard: {
		name: 'NcNoteCard',
		render() {
			return h('div', this.$slots.default?.())
		},
	},
}))
vi.mock('@conduction/nextcloud-vue', () => ({
	CnPageHeader: {
		name: 'CnPageHeader',
		props: ['title', 'description'],
		render() {
			return h('header', this.$slots.extra?.())
		},
	},
	CnBuildiqEditButton: { name: 'CnBuildiqEditButton', render: () => h('span') },
	CnStatusBadge: {
		name: 'CnStatusBadge',
		props: ['label', 'variant', 'size'],
		render() {
			return h('span', { class: 'badge' }, this.label)
		},
	},
}))

function t (app, text, vars) {
  return text.replace(/\{(\w+)\}/g, (m, k) => (vars && k in vars ? vars[k] : m))
}
const global = { mocks: { t } }

const { default: WeeklyReview } =
	await import('../../src/views/marketing/WeeklyReview.vue')

const byId = (wrapper, id) => wrapper.find(`[data-testid="weekly-review-${id}"]`)

describe('the weekly review states', () => {
	beforeEach(() => {
		api.fetchWeeklyReview.mockReset()
		api.generateWeeklyReview.mockReset()
	})

	it('shows the empty state with its icon when there is no review', async () => {
		api.fetchWeeklyReview.mockResolvedValue(null)
		const wrapper = mount(WeeklyReview, { global })
		await flushPromises()
		expect(byId(wrapper, 'empty').text()).toBe('No review yet')
		expect(byId(wrapper, 'empty').find('.CalendarWeek').exists()).toBe(true)
		expect(byId(wrapper, 'body').exists()).toBe(false)
	})

	it('says why the review could not be read', async () => {
		api.fetchWeeklyReview.mockRejectedValueOnce({
			response: { data: { error: 'Register offline' } },
		})
		const wrapper = mount(WeeklyReview, { global })
		await flushPromises()
		expect(wrapper.find('[role="alert"]').text()).toBe('Register offline')
		expect(wrapper.find('.loading').exists()).toBe(false)

		api.fetchWeeklyReview.mockRejectedValueOnce(new Error('x'))
		const other = mount(WeeklyReview, { global })
		await flushPromises()
		expect(other.find('[role="alert"]').text()).toBe(
			'Could not read the weekly review.',
		)
	})

	it('composes the review again from the header button', async () => {
		api.fetchWeeklyReview.mockResolvedValue(null)
		let finish
		api.generateWeeklyReview.mockReturnValue(
			new Promise((resolve) => {
				finish = resolve
			}),
		)
		const fresh = {
			summary: 'Fresh numbers',
			weekStarting: '2026-09-28',
			highlights: ['Opens fell'],
			highlightDirections: ['down'],
			suggestions: ['Send on Tuesday'],
			topicIdeas: ['Subsidies', 'Wijkteams'],
		}
		const wrapper = mount(WeeklyReview, { global })
		await flushPromises()

		await byId(wrapper, 'refresh').trigger('click')
		await wrapper.vm.$nextTick()
		expect(wrapper.find('.loading').exists()).toBe(true)
		expect(byId(wrapper, 'refresh').attributes('disabled')).toBeDefined()
		finish(fresh)
		await flushPromises()

		expect(api.generateWeeklyReview).toHaveBeenCalledTimes(1)
		expect(wrapper.find('.weekly-review__summary').text()).toBe('Fresh numbers')
		expect(wrapper.find('.weekly-review__week').text()).toBe(
			'Week of 28 September',
		)
		const line = byId(wrapper, 'highlights').find('.weekly-review__highlight')
		expect(line.find('.ArrowDown').exists()).toBe(true)
		expect(line.find('.ArrowUp').exists()).toBe(false)
		expect(byId(wrapper, 'suggestions').text()).toBe('Send on Tuesday')
		expect(
			byId(wrapper, 'ideas')
				.findAll('.badge')
				.map((b) => b.text()),
		).toEqual(['Subsidies', 'Wijkteams'])
	})

	it('keeps the old review and says why when composing fails', async () => {
		api.fetchWeeklyReview.mockResolvedValue({
			summary: 'Old',
			weekStarting: '2026-09-21',
		})
		api.generateWeeklyReview.mockRejectedValueOnce({
			response: { data: { error: 'No sources' } },
		})
		const wrapper = mount(WeeklyReview, { global })
		await flushPromises()
		await byId(wrapper, 'refresh').trigger('click')
		await flushPromises()
		expect(wrapper.find('[role="alert"]').text()).toBe('No sources')
		expect(wrapper.find('.weekly-review__summary').text()).toBe('Old')

		api.generateWeeklyReview.mockRejectedValueOnce(new Error('x'))
		await byId(wrapper, 'refresh').trigger('click')
		await flushPromises()
		expect(wrapper.find('[role="alert"]').text()).toBe(
			'Could not compose the weekly review.',
		)
	})

	it('names the agent without a moment when the generation time is unreadable', async () => {
		api.fetchWeeklyReview.mockResolvedValue({
			summary: 'S',
			weekStarting: 'unknown',
			agentAuthored: true,
			agentAuthoredBy: 'Review agent',
			generatedAt: 'not a date',
		})
		const wrapper = mount(WeeklyReview, { global })
		await flushPromises()
		expect(byId(wrapper, 'agent-mark').text()).toBe(
			'An agent wrote this summary: Review agent',
		)
		expect(wrapper.find('.weekly-review__week').text()).toBe('Week of unknown')
	})

	it('says there are no topic ideas or suggestions this week', async () => {
		api.fetchWeeklyReview.mockResolvedValue({ summary: 'S', weekStarting: '' })
		const wrapper = mount(WeeklyReview, { global })
		await flushPromises()
		const none = wrapper.findAll('.weekly-review__none').map((n) => n.text())
		expect(none).toEqual([
			'Nothing moved that the sources can show.',
			'No suggestions this week.',
			'No topic ideas this week.',
		])
		expect(wrapper.find('.weekly-review__week').text()).toBe('Week of')
	})
})
