// @vitest-environment jsdom
/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 * SPDX-License-Identifier: EUPL-1.2
 *
 * How the weekly review page reads and recomposes the review, the error it
 * shows when either fails, and how it labels the week and when it was
 * composed. The journeys API is mocked.
 *
 * @spec openspec/specs/commercial-dashboard/spec.md
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
vi.mock('@conduction/nextcloud-vue', () => {
	const stub = (name) => ({
		name,
		inheritAttrs: false,
		render() {
			return h('div', { 'data-name': name }, [
				this.$slots.default?.(),
				this.$slots.actions?.(),
			])
		},
	})
	return {
		CnBuildiqEditButton: stub('CnBuildiqEditButton'),
		CnPageHeader: stub('CnPageHeader'),
		CnStatusBadge: stub('CnStatusBadge'),
	}
})
vi.mock('@nextcloud/vue', () => {
	const stub = (name) => ({
		name,
		inheritAttrs: false,
		render() {
			return h('div', { 'data-name': name }, [
				this.$slots.default?.(),
				this.$slots.icon?.(),
			])
		},
	})
	return {
		NcButton: stub('NcButton'),
		NcEmptyContent: stub('NcEmptyContent'),
		NcLoadingIcon: stub('NcLoadingIcon'),
		NcNoteCard: stub('NcNoteCard'),
	}
})

globalThis.t = (_app, text) => text

const { default: WeeklyReview } =
	await import('../../src/views/marketing/WeeklyReview.vue')

/**
 * Mount the page.
 *
 * @return {object} The wrapper.
 */
function mountPage() {
	return mount(WeeklyReview, { global: { mocks: { t: (_app, text) => text } } })
}

beforeEach(() => {
	api.fetchWeeklyReview.mockReset()
	api.generateWeeklyReview.mockReset()
})

describe('WeeklyReview loading', () => {
	it('reads the review on mount', async () => {
		api.fetchWeeklyReview.mockResolvedValue({ weekStarting: '2026-11-02' })
		const wrapper = mountPage()
		expect(wrapper.vm.loading).toBe(true)
		await flushPromises()
		expect(api.fetchWeeklyReview).toHaveBeenCalledTimes(1)
		expect(wrapper.vm.review).toEqual({ weekStarting: '2026-11-02' })
		expect(wrapper.vm.loading).toBe(false)
		expect(wrapper.vm.error).toBe('')
	})

	it('shows the server error when reading fails', async () => {
		api.fetchWeeklyReview.mockRejectedValue({
			response: { data: { error: 'No review yet' } },
		})
		const wrapper = mountPage()
		await flushPromises()
		expect(wrapper.vm.error).toBe('No review yet')
		expect(wrapper.vm.loading).toBe(false)
	})

	it('falls back to its own message without a server error', async () => {
		api.fetchWeeklyReview.mockRejectedValue(new Error('network'))
		const wrapper = mountPage()
		await flushPromises()
		expect(wrapper.vm.error).toBe('Could not read the weekly review.')
	})
})

describe('WeeklyReview recomposing', () => {
	it('replaces the review and clears an earlier error', async () => {
		api.fetchWeeklyReview.mockRejectedValue(new Error('network'))
		api.generateWeeklyReview.mockResolvedValue({ weekStarting: '2026-11-09' })
		const wrapper = mountPage()
		await flushPromises()
		await wrapper.vm.regenerate()
		expect(api.generateWeeklyReview).toHaveBeenCalledTimes(1)
		expect(wrapper.vm.error).toBe('')
		expect(wrapper.vm.review).toEqual({ weekStarting: '2026-11-09' })
		expect(wrapper.vm.loading).toBe(false)
	})

	it('shows the server error or its own message when composing fails', async () => {
		api.fetchWeeklyReview.mockResolvedValue(null)
		const wrapper = mountPage()
		await flushPromises()
		api.generateWeeklyReview.mockRejectedValueOnce({
			response: { data: { error: 'Busy' } },
		})
		await wrapper.vm.regenerate()
		expect(wrapper.vm.error).toBe('Busy')
		api.generateWeeklyReview.mockRejectedValueOnce(new Error('network'))
		await wrapper.vm.regenerate()
		expect(wrapper.vm.error).toBe('Could not compose the weekly review.')
		expect(wrapper.vm.loading).toBe(false)
	})
})

describe('WeeklyReview labels', () => {
	it('labels the week by day and month', async () => {
		api.fetchWeeklyReview.mockResolvedValue({
			weekStarting: '2026-11-02T00:00:00',
		})
		const wrapper = mountPage()
		await flushPromises()
		expect(wrapper.vm.weekLabel).toBe('2 November')
	})

	it('keeps an unparseable week as typed and none when missing', async () => {
		api.fetchWeeklyReview.mockResolvedValue({ weekStarting: 'soon' })
		const wrapper = mountPage()
		await flushPromises()
		expect(wrapper.vm.weekLabel).toBe('soon')
		wrapper.vm.review = null
		expect(wrapper.vm.weekLabel).toBe('')
	})

	it('labels when the review was composed, or nothing', async () => {
		api.fetchWeeklyReview.mockResolvedValue({
			generatedAt: '2026-11-09T07:05:00',
		})
		const wrapper = mountPage()
		await flushPromises()
		expect(wrapper.vm.generatedLabel).toMatch(/^Monday 9 November\b/)
		expect(wrapper.vm.generatedLabel).toMatch(/07:05$/)
		wrapper.vm.review = { generatedAt: 'not a date' }
		expect(wrapper.vm.generatedLabel).toBe('')
		wrapper.vm.review = {}
		expect(wrapper.vm.generatedLabel).toBe('')
	})

	it('opens the subtitle with a capitalised date', async () => {
		api.fetchWeeklyReview.mockResolvedValue(null)
		const wrapper = mountPage()
		await flushPromises()
		expect(wrapper.vm.subtitle).toMatch(
			/^[A-Z].+ · newsletters, posts and search traffic of last week$/,
		)
	})
})
