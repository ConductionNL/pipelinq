// @vitest-environment jsdom
/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 * SPDX-License-Identifier: EUPL-1.2
 *
 * The data of the blast results page: the overview rows, the A/B pairs with
 * their verdict, the segment names and the attribution rows.
 *
 * @spec openspec/specs/marketing-analytics/spec.md#requirement-overview-metrics-table
 */
import { afterEach, beforeAll, describe, expect, it, vi } from 'vitest'

const axios = vi.hoisted(() => ({ get: vi.fn() }))
vi.mock('@nextcloud/axios', () => ({ default: axios }))
vi.mock('@nextcloud/router', () => ({ generateUrl: (p) => '/index.php' + p }))
vi.mock('@nextcloud/vue', () => ({ NcButton: {}, NcLoadingIcon: {} }))
vi.mock('@conduction/nextcloud-vue', () => ({ CnActionsMenu: {}, CnBuildiqEditButton: {}, CnPageHeader: {}, CnStatusBadge: {}, CnTab: {}, CnTabs: {}, useLook: () => ({ lookClass: '' }) }))

let page

beforeAll(async () => {
	globalThis.t = (app, text) => text
	page = (await import('../../src/views/blasts/PerformanceDashboard.vue')).default
}, 60000)

afterEach(() => {
	vi.useRealTimers()
	axios.get.mockReset()
})

/**
 * @param {object} state The page state.
 * @return {object} A page context: the state, every computed as a getter, every method bound.
 */
function bind(state) {
	const ctx = { t: (app, text) => text, segments: {}, templateSubjects: {}, ...state }
	for (const [name, fn] of Object.entries(page.computed)) {
		Object.defineProperty(ctx, name, { get: () => fn.call(ctx) })
	}
	for (const [name, fn] of Object.entries(page.methods)) {
		ctx[name] = fn.bind(ctx)
	}
	return ctx
}

describe('the overview rows', () => {
	it('reads the totals, the rates over delivered, the segment name and the defaults', () => {
		const ctx = bind({
			segments: { s1: 'All' },
			blasts: [
				{ id: 'b1', name: 'May', segmentId: 's1', status: 'sent', totals: { sent: 10, delivered: 8, opened: 4, clicked: 2, unsubscribed: 1 } },
				{ uuid: 'u2', segmentId: 's9' },
				{ slug: 'x', totals: { delivered: 0, opened: 3 } },
			],
		})
		expect(ctx.overviewRows).toEqual([
			{ id: 'b1', name: 'May', segmentName: 'All', status: 'sent', sent: 10, delivered: 8, opened: 4, clicked: 2, openRate: 0.5, clickRate: 0.25, unsubscribed: 1 },
			{ id: 'u2', name: '—', segmentName: 's9', status: 'draft', sent: 0, delivered: 0, opened: 0, clicked: 0, openRate: 0, clickRate: 0, unsubscribed: 0 },
			{ id: 'x', name: '—', segmentName: '—', status: 'draft', sent: 0, delivered: 0, opened: 3, clicked: 0, openRate: 0, clickRate: 0, unsubscribed: 0 },
		])
		expect(Object.keys(ctx.overviewRows[0])).toEqual(['id', 'name', 'segmentName', 'status', 'sent', 'delivered', 'opened', 'clicked', 'openRate', 'clickRate', 'unsubscribed'])
	})
})

describe('the A/B pairs', () => {
	const day = 24 * 60 * 60 * 1000
	const blast = (extra) => ({ templateId: 't', totals: { sent: 600, delivered: 600, clicked: 60 }, ...extra })

	it('pairs each parent with its first variant, newest test first, and skips orphans', () => {
		vi.useFakeTimers()
		vi.setSystemTime(new Date('2026-11-10T12:00:00Z'))
		const ctx = bind({
			blasts: [
				blast({ id: 'old', name: 'Old', sentAt: new Date(Date.now() - 5 * day).toISOString() }),
				blast({ id: 'old-b', abVariantOf: 'old', sentAt: new Date(Date.now() - 5 * day).toISOString(), totals: { sent: 600, delivered: 600, clicked: 150 } }),
				blast({ id: 'old-c', abVariantOf: 'old' }),
				blast({ uuid: 'new', sentAt: new Date(Date.now() - 2 * day).toISOString() }),
				blast({ id: 'new-b', abVariantOf: 'new', sentAt: new Date(Date.now() - 1000).toISOString() }),
				blast({ id: 'orphan', abVariantOf: 'gone' }),
			],
		})
		const pairs = ctx.abPairs
		expect(pairs.map((p) => [p.id, p.parentName, p.eligible, p.significant, p.recipients])).toEqual([
			['new', '—', false, false, 1200],
			['old', 'Old', true, true, 1200],
		])
		expect(pairs[1].variants.map((v) => [v.key, v.label, v.winner])).toEqual([['a', 'Variant A', false], ['b', 'Variant B', true]])
		expect(pairs[1].verdictLabel).toBe('Variant B significantly higher (p<0.05).')
		expect(pairs[1].pValueLabel).toBe('0.00')
		expect(pairs[0].pValueLabel).toBe('—')
		expect(pairs[0].verdictLabel).toBe('Results not yet available.')
		expect(pairs[0].variants.every((v) => v.winner === false)).toBe(true)
	})

	it('needs enough delivered per variant, and names the verdict of each outcome', () => {
		vi.useFakeTimers()
		vi.setSystemTime(new Date('2026-11-10T12:00:00Z'))
		const sentAt = new Date(Date.now() - 3 * day).toISOString()
		const small = bind({ blasts: [blast({ id: 'p', sentAt, totals: { delivered: 100, clicked: 1 } }), blast({ id: 'v', abVariantOf: 'p', sentAt, totals: { delivered: 100, clicked: 50 } })] })
		expect(small.abPairs[0]).toMatchObject({ eligible: false, recipients: 200 })
		const even = bind({ blasts: [blast({ id: 'p', sentAt }), blast({ id: 'v', abVariantOf: 'p', sentAt })] })
		expect(even.abPairs[0]).toMatchObject({ eligible: true, significant: false, verdictLabel: 'Not significant (p>0.05).' })
		const aWins = bind({ blasts: [blast({ id: 'p', sentAt, totals: { sent: 0, delivered: 600, clicked: 200 } }), blast({ id: 'v', abVariantOf: 'p', sentAt, totals: { delivered: 600, clicked: 20 } })] })
		expect(aWins.abPairs[0]).toMatchObject({ significant: true, verdictLabel: 'Variant A significantly higher (p<0.05).', recipients: 1200 })
		expect(aWins.abPairs[0].variants[0].winner).toBe(true)
	})
})

describe('the segment names and the attribution rows', () => {
	it('names segments by id, uuid or slug', async () => {
		axios.get.mockResolvedValueOnce({ data: { data: [{ id: 's1', name: 'All' }, { uuid: 'u' }, {}] } })
		const ctx = bind({})
		await ctx.fetchSegments()
		expect(axios.get).toHaveBeenCalledWith('/index.php/apps/pipelinq/api/segments', { params: { limit: 100 } })
		expect(ctx.segments).toEqual({ s1: 'All', u: 'u' })
		axios.get.mockResolvedValueOnce({ data: { results: [{ slug: 'z', name: 'Z' }] } })
		await ctx.fetchSegments()
		expect(ctx.segments).toEqual({ z: 'Z' })
		axios.get.mockRejectedValueOnce(new Error('x'))
		await ctx.fetchSegments()
		expect(ctx.segments).toEqual({})
	})

	it('keeps the blasts with deals or value, in blast order, and skips failures', async () => {
		const answers = { b1: { dealCount: 2, attributedValue: 0 }, b2: { dealCount: 0, attributedValue: 0 }, b4: { attributedValue: 99 } }
		axios.get.mockImplementation(async (url) => {
			const id = url.split('/').at(-2)
			if (id === 'b3') throw new Error('x')
			return { data: answers[id] }
		})
		const ctx = bind({ blasts: [{ id: 'b1', name: 'One' }, { uuid: 'b2' }, { slug: 'b3' }, {}, { id: 'b4' }] })
		await ctx.fetchAttributionRows()
		expect(axios.get).toHaveBeenCalledTimes(4)
		expect(axios.get).toHaveBeenCalledWith('/index.php/apps/pipelinq/api/blasts/b1/attribution')
		expect(ctx.attributionRows).toEqual([
			{ id: 'b1', name: 'One', dealCount: 2, attributedValue: 0 },
			{ id: 'b4', name: 'b4', dealCount: 0, attributedValue: 99 },
		])
	})
})
