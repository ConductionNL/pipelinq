// @vitest-environment jsdom
/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Asking Navi a question on the dashboard: what is posted, how the answer
 * joins the conversation, and what a failure says.
 *
 * @spec openspec/specs/dashboard/spec.md
 */
import { afterEach, beforeAll, describe, expect, it, vi } from 'vitest'

const axios = vi.hoisted(() => ({ post: vi.fn() }))
vi.mock('@nextcloud/axios', () => ({ default: axios }))
vi.mock('@nextcloud/router', () => ({ generateUrl: (p) => '/index.php' + p }))
vi.mock('@nextcloud/vue', () => ({ NcButton: {}, NcLoadingIcon: {}, NcTextField: {}, NcEmptyContent: {} }))
vi.mock('@conduction/nextcloud-vue', () => ({ CnChartWidget: {}, CnDataTable: {} }))

let widget

beforeAll(async () => {
	widget = (await import('../../src/views/dashboard/widgets/NaviAnalyticsWidget.vue')).default
}, 60000)

afterEach(() => {
	axios.post.mockReset()
	vi.restoreAllMocks()
})

/**
 * @param {object} state The widget state.
 * @return {object} A context with every method bound.
 */
function bind(state) {
	const ctx = { t: (app, text) => text, messages: [], query: '', loading: false, error: 'old', conversationId: null, ...state }
	for (const [name, fn] of Object.entries(widget.methods)) {
		if (!(name in ctx)) {
			ctx[name] = fn.bind(ctx)
		}
	}
	return ctx
}

describe('NaviAnalyticsWidget.submitQuery', () => {
	it('posts the trimmed question and adds the answer, keeping the conversation', async () => {
		axios.post.mockResolvedValueOnce({ data: { conversationId: 'cv1', textResponse: 'Ten', resultType: 'chart', chartData: { a: 1 }, tableData: [1], suggestedFollowUps: ['More'] } })
		const ctx = bind({ query: '  How many?  ' })
		await ctx.submitQuery()
		expect(axios.post).toHaveBeenCalledWith('/index.php/apps/pipelinq/api/navi/query', { query: 'How many?', conversationId: null })
		expect(ctx.messages).toEqual([
			{ role: 'user', text: 'How many?' },
			{ role: 'assistant', text: 'Ten', resultType: 'chart', chartData: { a: 1 }, tableData: [1], suggestedFollowUps: ['More'] },
		])
		expect([ctx.conversationId, ctx.query, ctx.loading, ctx.error]).toEqual(['cv1', '', false, null])
		axios.post.mockResolvedValueOnce({ data: null })
		ctx.query = 'Again'
		await ctx.submitQuery()
		expect(axios.post).toHaveBeenLastCalledWith('/index.php/apps/pipelinq/api/navi/query', { query: 'Again', conversationId: 'cv1' })
		expect(ctx.messages[3]).toEqual({ role: 'assistant', text: '', resultType: 'text', chartData: null, tableData: null, suggestedFollowUps: [] })
		expect(ctx.conversationId).toBe('cv1')
	})

	it('asks nothing for an empty question or while busy, and says when Navi failed', async () => {
		const idle = bind({ query: '   ' })
		await idle.submitQuery()
		const busy = bind({ query: 'x', loading: true })
		await busy.submitQuery()
		expect(axios.post).not.toHaveBeenCalled()
		vi.spyOn(console, 'error').mockImplementation(() => {})
		axios.post.mockRejectedValueOnce(new Error('down'))
		const failed = bind({ query: 'x' })
		await failed.submitQuery()
		expect(failed.error).toBe('Navi could not answer that question. Please try again.')
		expect(console.error).toHaveBeenCalledWith('NaviAnalyticsWidget submit error:', new Error('down'))
		expect(failed.messages).toEqual([{ role: 'user', text: 'x' }])
		expect(failed.loading).toBe(false)
	})
})
