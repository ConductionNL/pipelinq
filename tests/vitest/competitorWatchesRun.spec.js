/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 * SPDX-License-Identifier: EUPL-1.2
 *
 * The competitors page's "Run now", the line that sums up what changed last,
 * and the readable forms of a run moment and a relevance score.
 *
 * @spec openspec/specs/marketing-competitor-watches/spec.md#requirement-a-watch-event-is-written-once-per-watch-and-url
 */
import { beforeAll, beforeEach, describe, expect, it, vi } from 'vitest'

const axios = vi.hoisted(() => ({ get: vi.fn(), post: vi.fn() }))
vi.mock('@nextcloud/axios', () => ({ default: axios }))
vi.mock('@nextcloud/router', () => ({ generateUrl: (path) => '/index.php' + path }))
vi.mock('@nextcloud/vue', () => ({ NcButton: {}, NcEmptyContent: {}, NcLoadingIcon: {}, NcNoteCard: {} }))
vi.mock('@conduction/nextcloud-vue', () => ({ CnDataTable: {}, CnStatusBadge: {} }))
vi.mock('vue-material-design-icons/AccountGroupOutline.vue', () => ({ default: {} }))
vi.mock('../../src/services/localeUtils.js', () => ({ formatDate: (value) => `formatted ${value}` }))

let page

beforeAll(async () => {
	globalThis.t = (app, text, vars = {}) => String(text).replace(/\{(\w+)\}/g, (m, k) => (k in vars ? String(vars[k]) : m))
	page = (await import('../../src/views/marketing/CompetitorWatches.vue')).default
}, 60000)

beforeEach(() => {
	axios.get.mockReset()
	axios.post.mockReset()
})

/**
 * @param {object} state The page state.
 * @return {object} A page context with every method bound.
 */
function bind(state) {
	const ctx = { t: globalThis.t, competitors: [], events: [], ...state }
	for (const [name, fn] of Object.entries(page.methods)) {
		if (!(name in ctx)) {
			ctx[name] = fn.bind(ctx)
		}
	}
	return ctx
}

describe('CompetitorWatches.run', () => {
	it('runs the watch by its id, reloads, and clears the running mark', async () => {
		axios.post.mockResolvedValue({})
		const fetchAll = vi.fn(async function () {
			expect(this.running).toBe('w-1')
		})
		const ctx = bind({ error: 'old', running: '' })
		ctx.fetchAll = fetchAll.bind(ctx)

		await ctx.run({ '@self': { id: 'w-1' } })

		expect(axios.post).toHaveBeenCalledWith('/index.php/apps/pipelinq/api/marketing/competitor-watches/w-1/run', {})
		expect(fetchAll).toHaveBeenCalledTimes(1)
		expect(ctx.error).toBe('')
		expect(ctx.running).toBe('')
	})

	it('says why a run failed, with the server reason when it gives one', async () => {
		const ctx = bind({ error: '', running: '' })
		ctx.fetchAll = vi.fn()

		axios.post.mockRejectedValueOnce({ response: { data: { error: 'The feed refused us' } } })
		await ctx.run({ id: 'w-2' })
		expect(ctx.error).toBe('The feed refused us')
		expect(ctx.running).toBe('')
		expect(ctx.fetchAll).not.toHaveBeenCalled()

		axios.post.mockRejectedValueOnce(new Error('x'))
		await ctx.run({ uuid: 'w-3' })
		expect(axios.post).toHaveBeenLastCalledWith('/index.php/apps/pipelinq/api/marketing/competitor-watches/w-3/run', {})
		expect(ctx.error).toBe('The watch could not be run.')
	})
})

describe('CompetitorWatches.latestChange', () => {
	const latest = (state) => page.computed.latestChange.call(bind(state))

	it('says nothing has been seen without events', () => {
		expect(latest({})).toBe('Nothing has been seen yet.')
	})

	it('names the competitor and the title, and the relevance when it was scored', () => {
		const competitors = [{ id: 'c-1', name: 'Concurrent BV' }]
		expect(latest({ competitors, events: [{ competitorId: 'c-1', title: 'Nieuwe dienst', relevanceScore: 0.8 }] }))
			.toBe('Concurrent BV published "Nieuwe dienst", relevance 0.8')
		expect(latest({ competitors, events: [{ competitorId: 'c-1', url: 'https://c.example/x', relevanceScore: '' }] }))
			.toBe('Concurrent BV published "https://c.example/x"')
		expect(latest({ competitors, events: [{ competitorId: 'c-9', title: 'Iets', relevanceScore: null }] }))
			.toBe('c-9 published "Iets"')
	})
})

describe('CompetitorWatches readable values', () => {
	it('formats a readable moment and keeps an unreadable one as stored', () => {
		const ctx = bind({})
		expect(ctx.formatWhen('2026-11-10T09:30:00Z')).toBe('formatted 2026-11-10T09:30:00Z')
		expect(ctx.formatWhen('gisteren')).toBe('gisteren')
		expect(ctx.formatWhen(null)).toBe('')
	})

	it('names a relevance score, or says the event was not scored', () => {
		const ctx = bind({})
		expect(ctx.relevanceLabel({ relevanceScore: 0 })).toBe('Relevance 0')
		expect(ctx.relevanceLabel({ relevanceScore: '' })).toBe('Not scored')
		expect(ctx.relevanceLabel(null)).toBe('Not scored')
	})
})
