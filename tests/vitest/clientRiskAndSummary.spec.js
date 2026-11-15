// @vitest-environment jsdom
/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 * SPDX-License-Identifier: EUPL-1.2
 *
 * The client's risk panel and history summary card
 * (clients-history-summary-and-risk, tasks 1.4 and 2.3).
 */
import { beforeAll, beforeEach, describe, expect, it, vi } from 'vitest'

const http = vi.hoisted(() => ({ get: vi.fn() }))

vi.mock('@nextcloud/axios', () => ({ default: http }))
vi.mock('@nextcloud/router', () => ({ generateUrl: (path, vars = {}) => path.replace('{id}', vars.id) }))
vi.mock('@nextcloud/vue', () => ({ NcButton: {}, NcLoadingIcon: {} }))

let risk
let summary
let api

const t = (app, text, vars = {}) => text.replace(/\{(\w+)\}/g, (match, key) => String(vars[key] ?? match))

beforeAll(async () => {
	risk = (await import('../../src/components/clients/ClientRiskSection.vue')).default
	summary = (await import('../../src/components/clients/ClientHistorySummarySection.vue')).default
	api = await import('../../src/services/clientHistorySummaryApi.js')
}, 60000)

beforeEach(() => {
	http.get.mockReset()
})

/**
 * A risk panel context over a churnRisk value.
 *
 * @param {object|null} value The churnRisk.
 * @param {Array<string>} routes The route names the router knows.
 * @return {object} The context.
 */
function riskCtx(value, routes = ['TicketDetail']) {
	const ctx = { risk: value, t, $router: { getRoutes: () => routes.map((name) => ({ name })) } }
	for (const [name, fn] of Object.entries(risk.computed)) {
		Object.defineProperty(ctx, name, { get: () => fn.call(ctx) })
	}
	Object.assign(ctx, Object.fromEntries(Object.entries(risk.methods).map(([name, fn]) => [name, fn.bind(ctx)])))
	return ctx
}

describe('ClientRiskSection', () => {
	it('shows nothing computed for a client without a level', () => {
		expect(riskCtx(null).hasRisk).toBe(false)
		expect(riskCtx({ level: 'unknown' }).hasRisk).toBe(false)
	})

	it('names the level and lists the reasons heaviest first', () => {
		const ctx = riskCtx({
			level: 'high',
			score: 65,
			reasons: [
				{ signal: 'sla-breached', points: 15, records: ['t1'] },
				{ signal: 'contract-churned', points: 40, records: ['c1'] },
				{ signal: 'no-recent-contact', points: 10, records: [] },
			],
		})

		expect(ctx.levelLabel).toBe('High risk')
		expect(ctx.reasons.map((reason) => reason.signal)).toEqual(['contract-churned', 'sla-breached', 'no-recent-contact'])
		expect(ctx.signalLabel('contract-churned')).toBe('Churned contract')
	})

	it('links records only where the app has a page for them', () => {
		const ctx = riskCtx({ level: 'medium', reasons: [] })

		expect(ctx.recordLinks({ signal: 'sla-breached', records: ['t1', '', 't2'] })).toEqual([
			{ id: 't1', to: { name: 'TicketDetail', params: { id: 't1' } } },
			{ id: 't2', to: { name: 'TicketDetail', params: { id: 't2' } } },
		])
		expect(ctx.recordLinks({ signal: 'contract-churned', records: ['c1'] })).toEqual([])
		expect(riskCtx({ level: 'low' }, ['ContractDetail']).recordLinks({ signal: 'contract-churned', records: ['c1'] }))
			.toEqual([{ id: 'c1', to: { name: 'ContractDetail', params: { id: 'c1' } } }])
	})
})

describe('clientHistorySummaryApi', () => {
	it('asks only for availability on check', async () => {
		http.get.mockResolvedValue({ data: { available: true } })

		expect(await api.fetchSummaryAvailable('c-1')).toBe(true)
		expect(http.get).toHaveBeenCalledWith('/apps/pipelinq/api/clients/c-1/history-summary', { params: { check: 1 } })
	})

	it('normalises the summary answer', async () => {
		http.get.mockResolvedValue({ data: { available: true, summary: '', recordsRead: 3, generatedAt: '2026-11-13T12:00:00+00:00' } })

		expect(await api.fetchSummary('c-1')).toEqual({ available: true, summary: null, recordsRead: 3, generatedAt: '2026-11-13T12:00:00+00:00' })
	})
})

describe('ClientHistorySummarySection.summarise', () => {
	/**
	 * @return {object} A card context.
	 */
	function cardCtx() {
		return { resolvedId: 'c-1', available: true, loading: false, error: '', result: null, t }
	}

	it('keeps the summary with what it read', async () => {
		http.get.mockResolvedValue({ data: { available: true, summary: 'Two calls.', recordsRead: 2, generatedAt: '2026-11-13T12:00:00+00:00' } })
		const ctx = cardCtx()

		await summary.methods.summarise.call(ctx)

		expect(ctx.result).toEqual({ available: true, summary: 'Two calls.', recordsRead: 2, generatedAt: '2026-11-13T12:00:00+00:00' })
		expect(ctx.loading).toBe(false)
	})

	it('drops the button when hermiq went away', async () => {
		http.get.mockResolvedValue({ data: { available: false, summary: null, recordsRead: 0, generatedAt: null } })
		const ctx = cardCtx()

		await summary.methods.summarise.call(ctx)

		expect(ctx.available).toBe(false)
		expect(ctx.result).toBeNull()
	})

	it('says so when the summary could not be written', async () => {
		http.get.mockRejectedValue(new Error('500'))
		const ctx = cardCtx()

		await summary.methods.summarise.call(ctx)

		expect(ctx.error).toBe('The summary could not be written.')
	})
})
