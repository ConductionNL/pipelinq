// @vitest-environment jsdom
/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 * SPDX-License-Identifier: EUPL-1.2
 *
 * The weekly review as it renders from its one request: the empty state, the
 * week, the agent mark, the missing-sources notice, the highlights with their
 * direction, the suggestions and topic ideas, composing again, and failures.
 *
 * @spec openspec/specs/marketing-integrated-campaigns/spec.md#requirement-the-weekly-review-reads-four-sources-and-names-the-ones-with-nothing-in-them
 */

import { flushPromises, mount } from '@vue/test-utils'
import { beforeEach, describe, expect, it, vi } from 'vitest'

vi.hoisted(() => {
	globalThis.t = (app, text, vars = {}) => String(text).replace(/\{(\w+)\}/g, (m, k) => (k in vars ? String(vars[k]) : m))
})

const api = vi.hoisted(() => ({ fetchWeeklyReview: vi.fn(), generateWeeklyReview: vi.fn() }))
vi.mock('../../src/services/journeysApi.js', () => api)
vi.mock('@nextcloud/l10n', () => ({ getCanonicalLocale: () => 'en-GB' }))

vi.mock('@conduction/nextcloud-vue', async () => {
	const { defineComponent: define, h } = await import('vue')
	return {
		CnBuildiqEditButton: define({ name: 'CnBuildiqEditButton', render: () => null }),
		CnPageHeader: define({
			name: 'CnPageHeader',
			props: ['title', 'description'],
			setup(props, { slots }) {
				return () => h('header', { 'data-description': props.description }, slots.extra?.())
			},
		}),
		CnStatusBadge: define({
			name: 'CnStatusBadge',
			props: ['label'],
			setup(props) {
				return () => h('span', { class: 'badge' }, props.label)
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
				return () => h('div', { 'data-testid': attrs['data-testid'] }, [slots.icon?.(), props.name])
			},
		}),
		NcLoadingIcon: define({ name: 'NcLoadingIcon', setup: () => () => h('span', { class: 'loading' }) }),
		NcNoteCard: define({
			name: 'NcNoteCard',
			setup(_props, { slots, attrs }) {
				return () => h('div', { 'data-testid': attrs['data-testid'] }, slots.default?.())
			},
		}),
	}
})
const icon = (name) => ({ default: { name, props: ['title', 'size'], render() { return null } } })
vi.mock('vue-material-design-icons/ArrowDown.vue', () => icon('ArrowDown'))
vi.mock('vue-material-design-icons/ArrowUp.vue', () => icon('ArrowUp'))
vi.mock('vue-material-design-icons/CalendarWeek.vue', () => icon('CalendarWeek'))
vi.mock('vue-material-design-icons/Refresh.vue', () => icon('Refresh'))

const { default: WeeklyReview } = await import('../../src/views/marketing/WeeklyReview.vue')

/**
 * Mount the review and let its request settle.
 *
 * @return {Promise<object>} The wrapper.
 */
async function render() {
	const wrapper = mount(WeeklyReview, { global: { mocks: { t: globalThis.t } } })
	await flushPromises()
	return wrapper
}

const review = {
	weekStarting: '2026-09-28',
	summary: 'A steady week.',
	agentAuthored: true,
	agentAuthoredBy: 'Review agent',
	generatedAt: '2026-10-05T07:00:00',
	degraded: ['Search Console', 'Social'],
	highlights: ['Opens rose', 'Clicks fell', 'Posts held'],
	highlightDirections: ['up', 'down', 'flat'],
	suggestions: ['Send on Tuesday'],
	topicIdeas: ['Klantreis', 'CRM'],
}

beforeEach(() => {
	vi.clearAllMocks()
})

describe('WeeklyReview rendered', () => {
	it('shows the empty state when there is no review yet', async () => {
		api.fetchWeeklyReview.mockResolvedValue(null)
		const wrapper = await render()

		expect(wrapper.find('[data-testid="weekly-review-empty"]').text()).toBe('No review yet')
		expect(wrapper.find('[data-testid="weekly-review-body"]').exists()).toBe(false)
		expect(wrapper.find('header').attributes('data-description')).toContain('newsletters, posts and search traffic of last week')
	})

	it('draws the week, the summary, the agent mark and the missing sources', async () => {
		api.fetchWeeklyReview.mockResolvedValue(review)
		const wrapper = await render()

		expect(wrapper.find('.weekly-review__week').text()).toBe('Week of 28 September')
		expect(wrapper.find('.weekly-review__summary').text()).toBe('A steady week.')
		expect(wrapper.find('[data-testid="weekly-review-agent-mark"]').text()).toBe('An agent wrote this summary: Review agent, on Monday 5 October at 07:00')
		expect(wrapper.find('[data-testid="weekly-review-degraded"]').text()).toContain('Search Console, Social')
	})

	it('marks each highlight with the way its figure moved', async () => {
		api.fetchWeeklyReview.mockResolvedValue(review)
		const wrapper = await render()

		const items = wrapper.findAll('[data-testid="weekly-review-highlights"] li')
		expect(items.map((item) => item.text())).toEqual(['Opens rose', 'Clicks fell', 'Posts held'])
		expect(items[0].findComponent({ name: 'ArrowUp' }).props('title')).toBe('Higher than the week before')
		expect(items[1].findComponent({ name: 'ArrowDown' }).props('title')).toBe('Lower than the week before')
		expect(items[2].findComponent({ name: 'ArrowUp' }).exists()).toBe(false)
		expect(items[2].findComponent({ name: 'ArrowDown' }).exists()).toBe(false)
		expect(wrapper.find('[data-testid="weekly-review-suggestions"]').text()).toBe('Send on Tuesday')
		expect(wrapper.findAll('[data-testid="weekly-review-ideas"] .badge').map((badge) => badge.text())).toEqual(['Klantreis', 'CRM'])
		expect(wrapper.findAll('.weekly-review__none')).toHaveLength(0)
	})

	it('says so when a list is empty and keeps an unreadable week as written', async () => {
		api.fetchWeeklyReview.mockResolvedValue({ weekStarting: 'onbekend', summary: 'Quiet.', agentAuthored: true, agentAuthoredBy: 'Review agent', generatedAt: 'later' })
		const wrapper = await render()

		expect(wrapper.find('.weekly-review__week').text()).toBe('Week of onbekend')
		expect(wrapper.find('[data-testid="weekly-review-agent-mark"]').text()).toBe('An agent wrote this summary: Review agent')
		expect(wrapper.find('[data-testid="weekly-review-degraded"]').exists()).toBe(false)
		expect(wrapper.findAll('.weekly-review__none').map((node) => node.text())).toEqual([
			'Nothing moved that the sources can show.',
			'No suggestions this week.',
			'No topic ideas this week.',
		])
	})

	it('composes the review again on request', async () => {
		api.fetchWeeklyReview.mockResolvedValue(null)
		api.generateWeeklyReview.mockResolvedValue({ ...review, summary: 'Composed again.' })
		const wrapper = await render()

		await wrapper.find('[data-testid="weekly-review-refresh"]').trigger('click')
		await flushPromises()

		expect(api.generateWeeklyReview).toHaveBeenCalledTimes(1)
		expect(wrapper.find('.weekly-review__summary').text()).toBe('Composed again.')
		expect(wrapper.vm.loading).toBe(false)
	})

	it('reports a failed read and a failed compose, with the server reason when it gives one', async () => {
		api.fetchWeeklyReview.mockRejectedValue(new Error('x'))
		const wrapper = await render()
		expect(wrapper.find('[role="alert"]').text()).toBe('Could not read the weekly review.')

		api.generateWeeklyReview.mockRejectedValueOnce({ response: { data: { error: 'No sources' } } })
		await wrapper.vm.regenerate()
		expect(wrapper.vm.error).toBe('No sources')

		api.generateWeeklyReview.mockRejectedValueOnce(new Error('x'))
		await wrapper.vm.regenerate()
		expect(wrapper.vm.error).toBe('Could not compose the weekly review.')
		expect(wrapper.vm.loading).toBe(false)

		api.fetchWeeklyReview.mockRejectedValueOnce({ response: { data: { error: 'Locked' } } })
		await wrapper.vm.load()
		expect(wrapper.vm.error).toBe('Locked')
	})
})
