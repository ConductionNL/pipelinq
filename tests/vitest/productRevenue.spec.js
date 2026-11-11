// @vitest-environment jsdom
/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 * SPDX-License-Identifier: EUPL-1.2
 *
 * The product revenue card: the three products with the highest line-item
 * total, how many leads carry each, and their names.
 *
 * @spec openspec/changes/reverse-2026-05-26-fe-widgets-ui/tasks.md#task-1
 */
import { afterEach, beforeAll, describe, expect, it, vi } from 'vitest'

vi.mock('@nextcloud/router', () => ({ generateUrl: (path) => '/index.php' + path }))
vi.mock('@nextcloud/vue', () => ({ NcLoadingIcon: {} }))
vi.mock('../../src/store/modules/object.js', () => ({ useObjectStore: () => ({}) }))

let card

beforeAll(async () => {
	globalThis.t = (app, text) => text
	globalThis.OC = { requestToken: 'tok' }
	card = (await import('../../src/components/ProductRevenue.vue')).default
}, 60000)

afterEach(() => {
	vi.unstubAllGlobals()
})

/**
 * @param {object} objectStore The store.
 * @return {object} A card context with every method bound.
 */
function bind(objectStore) {
	const ctx = { loading: false, topProducts: ['old'], objectStore }
	for (const [name, fn] of Object.entries(card.methods)) {
		ctx[name] = fn.bind(ctx)
	}
	return ctx
}

const registry = { leadProduct: { register: 'r', schema: 'lp' }, product: { register: 'r', schema: 'p' } }

function answer(ok, body) {
	vi.stubGlobal('fetch', vi.fn(async () => ({ ok, json: async () => body })))
}

describe('ProductRevenue.fetchData', () => {
	it('shows nothing without both types registered', async () => {
		vi.stubGlobal('fetch', vi.fn())
		const ctx = bind({ objectTypeRegistry: { product: registry.product } })
		await ctx.fetchData()
		expect(ctx.topProducts).toEqual([])
		expect(fetch).not.toHaveBeenCalled()
		expect(ctx.loading).toBe(false)
	})

	it('shows nothing on a refusal, an empty list or a failure', async () => {
		for (const [ok, body] of [[false, {}], [true, { results: [] }], [true, null]]) {
			answer(ok, body)
			const ctx = bind({ objectTypeRegistry: registry })
			await ctx.fetchData()
			expect(ctx.topProducts).toEqual([])
		}
		vi.stubGlobal('fetch', vi.fn(async () => { throw new Error('down') }))
		const ctx = bind({ objectTypeRegistry: registry })
		await ctx.fetchData()
		expect(ctx.topProducts).toEqual([])
		expect(ctx.loading).toBe(false)
	})

	it('totals the line items per product, counts their leads, and names the top three', async () => {
		answer(true, [
			{ product: 'p1', total: '10', lead: 'l1' },
			{ product: 'p1', total: 5, lead: 'l1' },
			{ product: 'p1', total: 1, lead: 'l2' },
			{ product: 'p2', total: 30 },
			{ product: 'p3', total: 'x', lead: 'l3' },
			{ product: 'p4', total: 12, lead: 'l4' },
			{ total: 99 },
		])
		const fetchObject = vi.fn(async (type, id) => {
			if (id === 'p4') throw new Error('gone')
			return { p2: { name: 'Oven' }, p1: {} }[id]
		})
		const ctx = bind({ objectTypeRegistry: registry, fetchObject })
		await ctx.fetchData()
		expect(fetch).toHaveBeenCalledWith('/index.php/apps/openregister/api/objects/r/lp?_limit=500', { headers: { 'Content-Type': 'application/json', requesttoken: 'tok', 'OCS-APIREQUEST': 'true' } })
		expect(ctx.topProducts).toEqual([
			{ productId: 'p2', totalValue: 30, leadCount: 0, name: 'Oven' },
			{ productId: 'p1', totalValue: 16, leadCount: 2, name: 'Unknown Product' },
			{ productId: 'p4', totalValue: 12, leadCount: 1, name: 'Unknown Product' },
		])
		expect(fetchObject.mock.calls).toEqual([['product', 'p2'], ['product', 'p1'], ['product', 'p4']])
	})
})
