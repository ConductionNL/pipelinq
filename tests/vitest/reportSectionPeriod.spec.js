/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 * SPDX-License-Identifier: EUPL-1.2
 *
 * The three report sections read their period (and the comparison its
 * granularity) from the prop, else the workspace, else their default; they
 * load on mount and again when the period changes, and colour each channel
 * the same way.
 */
import { beforeAll, beforeEach, describe, expect, it, vi } from 'vitest'

const http = vi.hoisted(() => ({ get: vi.fn() }))
vi.mock('@nextcloud/axios', () => ({ default: http }))
vi.mock('@nextcloud/router', () => ({ generateUrl: (path) => path }))
vi.mock('@nextcloud/vue', () => ({ NcButton: {}, NcLoadingIcon: {} }))

let sections

beforeAll(async () => {
	sections = {
		agents: (await import('../../src/components/rapportage/AgentPerformanceSection.vue')).default,
		distribution: (await import('../../src/components/rapportage/ChannelDistributionSection.vue')).default,
		comparison: (await import('../../src/components/rapportage/ChannelComparisonSection.vue')).default,
	}
}, 60000)

beforeEach(() => {
	http.get.mockReset()
})

/**
 * @param {object} component A component, with the mixins it uses.
 * @param {string} kind An option name: computed, methods, watch, inject.
 * @return {object} That option merged over the mixins (watchers as lists).
 */
function optionOf(component, kind) {
	const merged = {}
	for (const part of [...(component.mixins || []), component]) {
		for (const [key, value] of Object.entries(part[kind] || {})) {
			merged[key] = kind === 'watch' ? [...(merged[key] || []), value] : value
		}
	}
	return merged
}

/**
 * @param {object} component A component, with the mixins it uses.
 * @return {Array<Function>} Its mounted hooks, in the order Vue runs them.
 */
function mountedHooks(component) {
	return [...(component.mixins || []), component].map((part) => part.mounted).filter(Boolean)
}

/**
 * @param {object} component A report section.
 * @param {object} state Props and injected context.
 * @return {object} A context with its computed values readable.
 */
function sectionCtx(component, state) {
	const ctx = { fetchData: vi.fn(), ...optionOf(component, 'methods'), ...state }
	for (const [key, getter] of Object.entries(optionOf(component, 'computed'))) {
		Object.defineProperty(ctx, key, { get: () => getter.call(ctx), configurable: true })
	}
	ctx.fetchData = vi.fn()
	return ctx
}

describe('the report sections', () => {
	it('inject the section context, defaulting to none', () => {
		for (const section of Object.values(sections)) {
			expect(optionOf(section, 'inject')).toEqual({ cnSectionContext: { default: null } })
		}
	})

	it('read the period from the prop, the workspace or their default', () => {
		for (const [name, fallback] of [['agents', 'today'], ['distribution', 'today'], ['comparison', 'month']]) {
			const section = sections[name]
			expect(sectionCtx(section, { period: 'week', cnSectionContext: { workspace: { period: 'month' } } }).effectivePeriod).toBe('week')
			expect(sectionCtx(section, { period: '', cnSectionContext: { workspace: { period: 'year' } } }).effectivePeriod).toBe('year')
			expect(sectionCtx(section, { period: '', cnSectionContext: { workspace: {} } }).effectivePeriod).toBe(fallback)
			expect(sectionCtx(section, { period: '', cnSectionContext: { workspace: null } }).effectivePeriod).toBe(fallback)
			expect(sectionCtx(section, { period: '', cnSectionContext: null }).effectivePeriod).toBe(fallback)
		}
		const comparison = sections.comparison
		expect(sectionCtx(comparison, { granularity: 'weekly', cnSectionContext: null }).effectiveGranularity).toBe('weekly')
		expect(sectionCtx(comparison, { granularity: '', cnSectionContext: { workspace: { granularity: 'monthly' } } }).effectiveGranularity).toBe('monthly')
		expect(sectionCtx(comparison, { granularity: '', cnSectionContext: null }).effectiveGranularity).toBe('daily')
	})

	it('load on mount and when the period (or granularity) changes', () => {
		for (const [name, watched] of [['agents', ['effectivePeriod']], ['distribution', ['effectivePeriod']], ['comparison', ['effectivePeriod', 'effectiveGranularity']]]) {
			const section = sections[name]
			const ctx = sectionCtx(section, {})
			mountedHooks(section).forEach((hook) => hook.call(ctx))
			expect(ctx.fetchData).toHaveBeenCalledTimes(1)
			const watch = optionOf(section, 'watch')
			expect(Object.keys(watch).sort()).toEqual([...watched].sort())
			for (const key of watched) {
				watch[key].forEach((handler) => handler.call(ctx))
			}
			expect(ctx.fetchData).toHaveBeenCalledTimes(1 + watched.length)
		}
	})

	it('colour each channel, and an unknown one grey', async () => {
		http.get.mockResolvedValue({ data: { distribution: { telefoon: 3, email: 1, fax: 0 } } })
		const ctx = { effectivePeriod: 'today', loading: false, channelData: [] }
		await sections.distribution.methods.fetchData.call(ctx)
		expect(ctx.channelData).toEqual([
			{ name: 'telefoon', count: 3, percentage: 75, color: '#4c84db' },
			{ name: 'email', count: 1, percentage: 25, color: '#f5a623' },
			{ name: 'fax', count: 0, percentage: 0, color: '#95a5a6' },
		])
	})
})
