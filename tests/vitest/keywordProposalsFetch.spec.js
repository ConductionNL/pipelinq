// @vitest-environment jsdom
/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 * SPDX-License-Identifier: EUPL-1.2
 *
 * The keyword intelligence page's one request: the window it asks for, the
 * sections it fills from the answer, and what it says when the answer fails.
 *
 * @spec openspec/changes/marketing-search-intelligence/specs/marketing-keyword-intelligence/spec.md#requirement-the-keywords-page-shows-the-four-derivations-and-confirms-one-at-a-time
 */
import { afterEach, beforeAll, describe, expect, it, vi } from 'vitest'

const axios = vi.hoisted(() => ({ get: vi.fn() }))
vi.mock('@nextcloud/axios', () => ({ default: axios }))
vi.mock('@nextcloud/router', () => ({ generateUrl: (path) => '/index.php' + path }))
vi.mock('@nextcloud/vue', () => ({ NcButton: {}, NcEmptyContent: {}, NcLoadingIcon: {}, NcNoteCard: {}, NcSelect: {} }))
vi.mock('@conduction/nextcloud-vue', () => ({ CnDataTable: {} }))
vi.mock('../../src/modals/KeywordTargetConfirmModal.vue', () => ({ default: {} }))

let page

beforeAll(async () => {
	globalThis.t = (app, text) => text
	page = (await import('../../src/views/marketing/KeywordIntelligence.vue')).default
}, 60000)

afterEach(() => {
	vi.useRealTimers()
	axios.get.mockReset()
})

/**
 * @param {object} state The page state.
 * @return {object} A page context with every method bound.
 */
function bind(state) {
	const ctx = { t: globalThis.t, ...state }
	for (const [name, fn] of Object.entries(page.methods)) {
		if (!(name in ctx)) {
			ctx[name] = fn.bind(ctx)
		}
	}
	return ctx
}

function fields (ctx) {
  return {
	buckets: ctx.buckets,
	strikingDistance: ctx.strikingDistance,
	cannibalisation: ctx.cannibalisation,
	gaps: ctx.gaps,
	crawl: ctx.crawl,
	confirmedTerms: ctx.confirmedTerms,
	minImpressions: ctx.minImpressions,
	from: ctx.from,
	to: ctx.to,
}
}

describe('KeywordIntelligence.fetchProposals', () => {
	it('asks for the chosen window and fills every section from the answer', async () => {
		vi.useFakeTimers()
		vi.setSystemTime(new Date('2026-11-10T12:00:00Z'))
		const data = { buckets: [1], strikingDistance: [2], cannibalisation: [3], gaps: [4], crawl: { crawled: true }, confirmedTerms: ['a'], minImpressions: 5, from: 'f', to: 't' }
		axios.get.mockResolvedValue({ data })
		const ctx = bind({ days: 7 })
		await ctx.fetchProposals()
		expect(axios.get).toHaveBeenCalledWith('/index.php/apps/pipelinq/api/marketing/keyword-proposals', { params: { from: '2026-11-03', to: '2026-11-10' } })
		expect(fields(ctx)).toEqual(data)
		expect(ctx.loading).toBe(false)
		expect(ctx.error).toBe('')
	})

	it('takes the window, then 28 days, and empty sections for an empty answer', async () => {
		vi.useFakeTimers()
		vi.setSystemTime(new Date('2026-11-10T12:00:00Z'))
		axios.get.mockResolvedValue({ data: null })
		const ctx = bind({ days: 0, window: { days: 14 } })
		await ctx.fetchProposals()
		expect(axios.get.mock.calls[0][1].params.from).toBe('2026-10-27')
		expect(fields(ctx)).toEqual({ buckets: [], strikingDistance: [], cannibalisation: [], gaps: [], crawl: { crawled: false, reason: '' }, confirmedTerms: [], minImpressions: 0, from: '', to: '' })
		const fallback = bind({})
		axios.get.mockResolvedValue({})
		await fallback.fetchProposals()
		expect(axios.get.mock.calls[1][1].params.from).toBe('2026-10-13')
	})

	it('empties the buckets and says why on a failure', async () => {
		axios.get.mockRejectedValueOnce({ response: { data: { error: 'No console' } } })
		const ctx = bind({ days: 7, buckets: [1], gaps: [9] })
		await ctx.fetchProposals()
		expect(ctx.buckets).toEqual([])
		expect(ctx.gaps).toEqual([9])
		expect(ctx.error).toBe('No console')
		expect(ctx.loading).toBe(false)
		axios.get.mockRejectedValueOnce(new Error('x'))
		await ctx.fetchProposals()
		expect(ctx.error).toBe('Could not load keyword proposals.')
	})
})
