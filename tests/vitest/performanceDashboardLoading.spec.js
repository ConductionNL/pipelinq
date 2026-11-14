// @vitest-environment jsdom
/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 * SPDX-License-Identifier: EUPL-1.2
 *
 * The blast results page's loading and table behaviour: what the page load
 * asks for, how a failure reads, that attribution loads once and only after
 * the blast list, the overview sort, and the small labels.
 *
 * @spec openspec/specs/marketing-analytics/spec.md#requirement-overview-metrics-table
 */
import { afterEach, beforeAll, describe, expect, it, vi } from 'vitest'

const axios = vi.hoisted(() => ({ get: vi.fn() }))
vi.mock('@nextcloud/axios', () => ({ default: axios }))
vi.mock('@nextcloud/router', () => ({ generateUrl: (p, params = {}) => '/index.php' + p.replace(/\{(\w+)\}/g, (m, k) => params[k]) }))
vi.mock('@nextcloud/vue', () => ({ NcButton: {}, NcLoadingIcon: {} }))
vi.mock('@conduction/nextcloud-vue', () => ({ CnActionsMenu: {}, CnBuildiqEditButton: {}, CnPageHeader: {}, CnStatusBadge: {}, CnTab: {}, CnTabs: {}, useLook: () => ({ lookClass: '' }) }))

let page

beforeAll(async () => {
	globalThis.t = (app, text) => text
	page = (await import('../../src/views/blasts/PerformanceDashboard.vue')).default
}, 60000)

afterEach(() => {
	axios.get.mockReset()
})

/**
 * @param {object} state The page state.
 * @return {object} A page context: data defaults, the state, every computed as a getter, every method bound.
 */
function bind(state = {}) {
	const ctx = { t: (app, text) => text, ...page.data(), ...state }
	for (const [name, fn] of Object.entries(page.computed)) {
		Object.defineProperty(ctx, name, { get: () => fn.call(ctx) })
	}
	for (const [name, fn] of Object.entries(page.methods)) {
		ctx[name] = fn.bind(ctx)
	}
	return ctx
}

/**
 * Answer GETs by URL.
 *
 * @param {object} routes URL to response body, or to an Error to throw.
 * @return {void}
 */
function serve(routes) {
	axios.get.mockImplementation(async (url) => {
		const answer = routes[url]
		if (answer instanceof Error) {
			throw answer
		}
		return { data: answer ?? {} }
	})
}

describe('the page load', () => {
	it('reads the blasts and segment names, then the A/B template subjects', async () => {
		serve({
			'/index.php/apps/pipelinq/api/blasts': { data: [{ id: 'p', templateId: 't1' }, { id: 'v', abVariantOf: 'p', templateId: 't2' }] },
			'/index.php/apps/pipelinq/api/segments': { results: [{ id: 's1', name: 'All' }] },
			'/index.php/apps/pipelinq/api/templates/t1': { subject: 'Hello' },
			'/index.php/apps/pipelinq/api/templates/t2': new Error('gone'),
		})
		const ctx = bind()

		await ctx.fetchAll()

		expect(axios.get).toHaveBeenCalledWith('/index.php/apps/pipelinq/api/blasts', { params: { limit: 50 } })
		expect(axios.get).toHaveBeenCalledWith('/index.php/apps/pipelinq/api/segments', { params: { limit: 100 } })
		expect(ctx.blasts.map((b) => b.id)).toEqual(['p', 'v'])
		expect(ctx.segments).toEqual({ s1: 'All' })
		expect(ctx.templateSubjects).toEqual({ t1: 'Hello' })
		expect([ctx.loading, ctx.error]).toEqual([false, ''])
	})

	it('reads a results envelope and treats a missing list as none', async () => {
		serve({ '/index.php/apps/pipelinq/api/blasts': { results: [{ id: 'r1' }] } })
		const ctx = bind()
		await ctx.fetchBlasts()
		expect(ctx.blasts).toEqual([{ id: 'r1' }])

		serve({ '/index.php/apps/pipelinq/api/blasts': null })
		await ctx.fetchBlasts()
		expect(ctx.blasts).toEqual([])
	})

	it('keeps the page up without segment names when they cannot be read', async () => {
		serve({ '/index.php/apps/pipelinq/api/segments': new Error('denied') })
		const ctx = bind({ segments: { old: 'Stale' } })

		await ctx.fetchSegments()

		expect(ctx.segments).toEqual({})
	})

	it('shows the server error, or a generic one, when the blasts cannot be read', async () => {
		const refused = Object.assign(new Error('x'), { response: { data: { error: 'Not allowed' } } })
		serve({ '/index.php/apps/pipelinq/api/blasts': refused })
		const ctx = bind()
		await ctx.fetchAll()
		expect([ctx.loading, ctx.error]).toEqual([false, 'Not allowed'])

		serve({ '/index.php/apps/pipelinq/api/blasts': new Error('offline') })
		await ctx.fetchAll()
		expect(ctx.error).toBe('Could not load performance data.')
	})
})

describe('the attribution tab', () => {
	it('loads attribution and traffic once, and only after the page load has landed', async () => {
		const order = []
		let land
		const ctx = bind({ pageLoad: new Promise((resolve) => { land = resolve }) })
		ctx.fetchAttributionRows = async () => order.push('attribution')
		ctx.fetchTrafficRows = async () => order.push('traffic')

		const first = ctx.loadAttribution()
		await Promise.resolve()
		expect(order).toEqual([])
		land()
		await first
		await ctx.loadAttribution()

		expect(order).toEqual(['attribution', 'traffic'])
		expect(ctx.attributionRequested).toBe(true)
	})

	it('switches tab and loads attribution only for the attribution tab', () => {
		const ctx = bind()
		ctx.loadAttribution = vi.fn()

		ctx.onTabChange(1)
		expect([ctx.activeTab, ctx.loadAttribution.mock.calls.length]).toEqual(['ab', 0])

		ctx.onTabChange(2)
		expect([ctx.activeTab, ctx.loadAttribution.mock.calls.length]).toEqual(['attribution', 1])

		ctx.onTabChange(9)
		expect(ctx.activeTab).toBe('attribution')
	})

	it('refresh reloads the page and, when it was open, attribution again', async () => {
		const ctx = bind({ attributionRequested: true })
		ctx.fetchAll = vi.fn(async () => {})
		ctx.loadAttribution = vi.fn(async () => {})

		await ctx.refresh()

		expect(ctx.fetchAll).toHaveBeenCalledTimes(1)
		expect(ctx.loadAttribution).toHaveBeenCalledTimes(1)
		expect(ctx.attributionRequested).toBe(false)

		ctx.loadAttribution.mockClear()
		await ctx.refresh()
		expect(ctx.loadAttribution).not.toHaveBeenCalled()
	})
})

describe('the overview table', () => {
	const blasts = [
		{ id: 'a', name: 'Bravo', totals: { sent: 5 } },
		{ id: 'b', name: 'alpha', totals: { sent: 20 } },
		{ id: 'c', name: 'Charlie', totals: { sent: 10 } },
	]

	it('sorts by sent, most first, by default and flips on a second click', () => {
		const ctx = bind({ blasts })
		expect(ctx.sortedOverviewRows.map((r) => r.id)).toEqual(['b', 'c', 'a'])
		expect(ctx.ariaSort('sent')).toBe('descending')
		expect(ctx.ariaSort('name')).toBe('none')

		ctx.onSort('sent')
		expect(ctx.sortedOverviewRows.map((r) => r.id)).toEqual(['a', 'c', 'b'])
		expect(ctx.ariaSort('sent')).toBe('ascending')
	})

	it('sorts a text column alphabetically, starting descending', () => {
		const ctx = bind({ blasts })

		ctx.onSort('name')
		expect([ctx.overviewSortKey, ctx.overviewSortOrder]).toEqual(['name', 'desc'])
		expect(ctx.sortedOverviewRows.map((r) => r.name)).toEqual(['Charlie', 'Bravo', 'alpha'])

		ctx.onSort('name')
		expect(ctx.sortedOverviewRows.map((r) => r.name)).toEqual(['alpha', 'Bravo', 'Charlie'])
	})

	it('names its tabs, columns and status colours', () => {
		const ctx = bind()
		expect(ctx.tabs.map((tab) => tab.id)).toEqual(['overview', 'ab', 'attribution'])
		expect(ctx.overviewColumns.map((c) => c.key)).toEqual(['name', 'segmentName', 'status', 'sent', 'openRate', 'clickRate', 'unsubscribed'])
		expect(ctx.statusColors.failed).toBe('error')
		expect(ctx.statusColors.sent).toBe('success')
	})
})

describe('the labels', () => {
	it('formats euros, and a dash for anything else', () => {
		const ctx = bind()
		expect(ctx.formatEur(1234.5)).toBe(`EUR ${(1234.5).toLocaleString('nl-NL', { minimumFractionDigits: 0, maximumFractionDigits: 2 })}`)
		expect(ctx.formatEur(Number.NaN)).toBe('—')
		expect(ctx.formatEur('12')).toBe('—')
	})

	it('labels each known status and passes an unknown one through', () => {
		const ctx = bind()
		expect(['draft', 'scheduled', 'sending', 'sent', 'paused', 'failed', 'cancelled', 'archived'].map(ctx.statusLabel))
			.toEqual(['Draft', 'Scheduled', 'Sending', 'Sent', 'Paused', 'Failed', 'Cancelled', 'archived'])
	})

	it('names a segment, a missing one by its id, and none as a dash', () => {
		const ctx = bind({ segments: { s1: 'All' } })
		expect([ctx.segmentNameFor('s1'), ctx.segmentNameFor('s9'), ctx.segmentNameFor('')]).toEqual(['All', 's9', '—'])
	})
})
