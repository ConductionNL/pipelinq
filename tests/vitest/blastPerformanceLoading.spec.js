// @vitest-environment jsdom
/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 * SPDX-License-Identifier: EUPL-1.2
 *
 * How the blast results page loads and sorts: the blasts, the segment names
 * and the A/B variants' subjects load first and a failure says why; the
 * overview sorts by any column and flips on a second click; the attribution
 * and site traffic reads start only when that tab is first opened, after the
 * blast list has landed, and once.
 *
 * @spec openspec/specs/marketing-analytics/spec.md#requirement-overview-metrics-table
 */
import { afterEach, beforeAll, describe, expect, it, vi } from 'vitest'

const axios = vi.hoisted(() => ({ get: vi.fn() }))
vi.mock('@nextcloud/axios', () => ({ default: axios }))
vi.mock('@nextcloud/router', () => ({ generateUrl: (p, vars = {}) => '/index.php' + p.replace(/\{(\w+)\}/g, (m, k) => vars[k] ?? m) }))
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
 * @return {object} A page context: the data, the state, every computed as a getter, every method bound.
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
 * Answer GETs by path.
 *
 * @param {object} answers Path suffix => data, or an Error to reject with.
 * @return {void}
 */
function answer(answers) {
	axios.get.mockImplementation(async (url) => {
		const key = Object.keys(answers).find((suffix) => url.endsWith(suffix))
		if (key === undefined) {
			throw new Error('unexpected ' + url)
		}
		if (answers[key] instanceof Error) {
			throw answers[key]
		}
		return { data: answers[key] }
	})
}

describe('sorting the overview', () => {
	const blasts = [
		{ id: 'a', name: 'April', totals: { sent: 10, delivered: 10, opened: 5 } },
		{ id: 'b', name: 'March', totals: { sent: 30, delivered: 10, opened: 1 } },
		{ id: 'c', name: 'May', totals: { sent: 20, delivered: 10, opened: 9 } },
	]

	it('starts on the most sent first', () => {
		expect(bind({ blasts }).sortedOverviewRows.map((row) => row.id)).toEqual(['b', 'c', 'a'])
	})

	it('sorts a new column descending, flips it on a second click, and reports it to assistive technology', () => {
		const ctx = bind({ blasts })

		ctx.onSort('openRate')
		expect([ctx.overviewSortKey, ctx.overviewSortOrder]).toEqual(['openRate', 'desc'])
		expect(ctx.sortedOverviewRows.map((row) => row.id)).toEqual(['c', 'a', 'b'])
		expect([ctx.ariaSort('openRate'), ctx.ariaSort('sent')]).toEqual(['descending', 'none'])

		ctx.onSort('openRate')
		expect(ctx.overviewSortOrder).toBe('asc')
		expect(ctx.sortedOverviewRows.map((row) => row.id)).toEqual(['b', 'a', 'c'])
		expect(ctx.ariaSort('openRate')).toBe('ascending')

		ctx.onSort('openRate')
		expect(ctx.overviewSortOrder).toBe('desc')
	})

	it('sorts text columns alphabetically', () => {
		const ctx = bind({ blasts, overviewSortKey: 'name', overviewSortOrder: 'asc' })

		expect(ctx.sortedOverviewRows.map((row) => row.name)).toEqual(['April', 'March', 'May'])
	})

	it('names a segment by its loaded name, else its id, else a dash', () => {
		const ctx = bind({ segments: { s1: 'Residents' } })

		expect([ctx.segmentNameFor('s1'), ctx.segmentNameFor('s9'), ctx.segmentNameFor('')]).toEqual(['Residents', 's9', '—'])
	})
})

describe('loading the page', () => {
	it('reads 50 blasts, the segments and the A/B variants\' subjects, then stops loading', async () => {
		answer({
			'/api/blasts': { data: [{ id: 'p', templateId: 't1' }, { id: 'v', abVariantOf: 'p', templateId: 't2' }] },
			'/api/segments': { results: [{ id: 's1', name: 'Residents' }] },
			'/api/templates/t1': { subject: 'Hello' },
			'/api/templates/t2': new Error('404'),
		})
		const ctx = bind()

		await ctx.fetchAll()

		expect(axios.get).toHaveBeenCalledWith('/index.php/apps/pipelinq/api/blasts', { params: { limit: 50 } })
		expect(axios.get).toHaveBeenCalledWith('/index.php/apps/pipelinq/api/segments', { params: { limit: 100 } })
		expect(ctx.blasts.map((b) => b.id)).toEqual(['p', 'v'])
		expect(ctx.segments).toEqual({ s1: 'Residents' })
		expect(ctx.templateSubjects).toEqual({ t1: 'Hello' })
		expect([ctx.loading, ctx.error]).toEqual([false, ''])
	})

	it('reads the blasts from `results` too, and an empty list from neither', async () => {
		answer({ '/api/blasts': { results: [{ id: 'r' }] }, '/api/segments': {} })
		const ctx = bind()
		await ctx.fetchAll()
		expect(ctx.blasts).toEqual([{ id: 'r' }])

		answer({ '/api/blasts': null, '/api/segments': {} })
		await ctx.fetchAll()
		expect(ctx.blasts).toEqual([])
	})

	it('keeps the overview when the segments cannot be read', async () => {
		answer({ '/api/blasts': { data: [{ id: 'a' }] }, '/api/segments': new Error('500') })
		const ctx = bind({ segments: { stale: 'x' } })

		await ctx.fetchAll()

		expect(ctx.segments).toEqual({})
		expect([ctx.blasts.length, ctx.error, ctx.loading]).toEqual([1, '', false])
	})

	it('shows the server\'s reason when the blasts cannot be read, else a general message', async () => {
		const refused = Object.assign(new Error('403'), { response: { data: { error: 'Not allowed.' } } })
		answer({ '/api/blasts': refused, '/api/segments': {} })
		const ctx = bind()
		await ctx.fetchAll()
		expect([ctx.error, ctx.loading]).toEqual(['Not allowed.', false])

		answer({ '/api/blasts': new Error('network'), '/api/segments': {} })
		await ctx.fetchAll()
		expect(ctx.error).toBe('Could not load performance data.')
	})
})

describe('the attribution tab', () => {
	it('selects a tab by index and ignores an index without a tab', () => {
		const ctx = bind()
		ctx.loadAttribution = vi.fn()

		ctx.onTabChange(1)
		expect(ctx.activeTab).toBe('ab')
		expect(ctx.loadAttribution).not.toHaveBeenCalled()
		ctx.onTabChange(7)
		expect(ctx.activeTab).toBe('ab')
		ctx.onTabChange(2)
		expect(ctx.activeTab).toBe('attribution')
		expect(ctx.loadAttribution).toHaveBeenCalledTimes(1)
	})

	it('waits for the blast list before reading attribution and traffic, and reads them once', async () => {
		let release
		const ctx = bind({ pageLoad: new Promise((resolve) => { release = resolve }) })
		answer({
			'/b1/attribution': { dealCount: 2, attributedValue: 5000 },
			'/b1/performance': { connected: true, campaign: 'mei', site: { sessions: 12 }, email: { opened: 3, clicked: 1 }, deals: { dealCount: 2 } },
		})

		const first = ctx.loadAttribution()
		const second = ctx.loadAttribution()
		await Promise.resolve()
		expect(axios.get).not.toHaveBeenCalled()

		ctx.blasts = [{ id: 'b1', name: 'May' }]
		release()
		await Promise.all([first, second])

		expect(axios.get).toHaveBeenCalledTimes(2)
		expect(ctx.attributionRows).toEqual([{ id: 'b1', name: 'May', dealCount: 2, attributedValue: 5000 }])
		expect(ctx.trafficRows).toEqual([{ id: 'b1', name: 'May', campaign: 'mei', opened: 3, clicked: 1, sessions: 12, dealCount: 2 }])
		expect([ctx.trafficConnected, ctx.trafficState]).toEqual([true, 'answered'])

		await ctx.loadAttribution()
		expect(axios.get).toHaveBeenCalledTimes(2)
	})

	it('says there are no blasts when there is nothing to ask about', async () => {
		const ctx = bind({ pageLoad: Promise.resolve(), blasts: [] })

		await ctx.loadAttribution()

		expect(axios.get).not.toHaveBeenCalled()
		expect([ctx.trafficState, ctx.attributionRows, ctx.trafficRows]).toEqual(['no-blasts', [], []])
	})

	it('says the traffic is unreadable when every read failed', async () => {
		answer({ '/attribution': new Error('500'), '/performance': new Error('500') })
		const ctx = bind({ pageLoad: Promise.resolve(), blasts: [{ id: 'b1' }, { id: 'b2' }] })

		await ctx.loadAttribution()

		expect([ctx.trafficState, ctx.trafficConnected, ctx.attributionRows]).toEqual(['unreadable', null, []])
	})

	it('records that no portal is connected', async () => {
		answer({ '/attribution': { dealCount: 0 }, '/performance': { connected: false } })
		const ctx = bind({ pageLoad: Promise.resolve(), blasts: [{ id: 'b1' }] })

		await ctx.loadAttribution()

		expect([ctx.trafficState, ctx.trafficConnected, ctx.trafficRows, ctx.attributionRows]).toEqual(['answered', false, [], []])
	})
})
