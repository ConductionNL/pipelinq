// @vitest-environment jsdom
/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 * SPDX-License-Identifier: EUPL-1.2
 *
 * The pipeline board's item loading: the items of the selected pipeline, the
 * line items of the leads on it with their product names, and the drop of a
 * card on another stage.
 *
 * @spec openspec/changes/lead-product-link/tasks.md#task-4.1
 */

import { beforeAll, beforeEach, describe, expect, it, vi } from 'vitest'

const dialogs = vi.hoisted(() => ({ showError: vi.fn() }))
vi.mock('@nextcloud/dialogs', () => dialogs)
vi.mock('../../src/store/modules/object.js', () => ({ useObjectStore: () => ({}) }))
vi.mock('../../src/store/store.js', () => ({ initializeStores: vi.fn() }))
vi.mock('@nextcloud/vue', () => ({ NcActionButton: {}, NcActionCaption: {}, NcActionCheckbox: {}, NcActions: {}, NcButton: {}, NcCheckboxRadioSwitch: {}, NcLoadingIcon: {}, NcNoteCard: {}, NcSelect: {}, NcTextField: {} }))
vi.mock('@conduction/nextcloud-vue', () => ({ CnActionsBar: {}, CnBuildiqEditButton: {}, CnPageHeader: {}, CnQuickFilterBar: {}, openRowTarget: vi.fn(), useLook: () => ({ isBoard: false, lookClass: '' }) }))
vi.mock('../../src/components/leadScore/LeadScoreBadge.vue', () => ({ default: {} }))
vi.mock('../../src/dialogs/LeadCreateDialog.vue', () => ({ default: {} }))
vi.mock('../../src/dialogs/PipelineFormDialog.vue', () => ({ default: {} }))
vi.mock('../../src/views/pipeline/PipelineCard.vue', () => ({ default: {} }))

let board

beforeAll(async () => {
	globalThis.t = (app, text, vars = {}) =>
		text.replace(/\{(\w+)\}/g, (m, key) => vars[key] ?? m)
	board = (await import('../../src/views/pipeline/PipelineBoard.vue')).default
}, 60000)

beforeEach(() => {
	dialogs.showError.mockReset()
})

/**
 * A board context: every method bound to it, plus the given state.
 *
 * @param {object} state The state.
 * @return {object} The context.
 */
function makeBoard(state) {
	const ctx = { ...state }
	for (const [name, fn] of Object.entries(board.methods)) {
		if (!(name in ctx)) {
			ctx[name] = fn.bind(ctx)
		}
	}
	return ctx
}

describe('fetchLeadProductsForStages', () => {
	const leadProducts = [
		{ id: 'lp1', lead: 'l1', product: 'p1', total: 10 },
		{ id: 'lp2', lead: 'l1', product: 'p2', total: 5 },
		{ id: 'lp3', lead: 'l2', product: null, total: 3 },
		{ id: 'lp4', lead: 'other', product: 'p3', total: 1 },
		{ id: 'lp5', lead: null, product: 'p3', total: 1 },
	]
	const items = [
		{ id: 'l1', _schemaSlug: 'lead' },
		{ id: 'l2', _schemaSlug: 'lead' },
		{ id: 'r1', _schemaSlug: 'request' },
		{ _schemaSlug: 'lead' },
	]

	it('clears both maps without a request when the board has no leads', async () => {
		const store = { fetchCollection: vi.fn() }
		const ctx = makeBoard({ objectStore: store, items: [{ id: 'r1', _schemaSlug: 'request' }], leadProductsByLead: { x: [] }, productNamesById: { x: 'X' } })
		await ctx.fetchLeadProductsForStages()
		expect(store.fetchCollection).not.toHaveBeenCalled()
		expect(ctx.leadProductsByLead).toEqual({})
		expect(ctx.productNamesById).toEqual({})
	})

	it('groups the line items of the board leads and names their products', async () => {
		const store = {
			fetchCollection: vi.fn(async (type) => (type === 'leadProduct'
				? leadProducts
				: [{ id: 'p1', name: 'Oven' }, { id: 'p2' }, { id: 'p3', name: 'Other' }])),
		}
		const ctx = makeBoard({ objectStore: store, items })
		await ctx.fetchLeadProductsForStages()
		expect(store.fetchCollection.mock.calls).toEqual([['leadProduct', { _limit: 500 }], ['product', { _limit: 500 }]])
		expect(ctx.leadProductsByLead).toEqual({ l1: [leadProducts[0], leadProducts[1]], l2: [leadProducts[2]] })
		expect(ctx.productNamesById).toEqual({ p1: 'Oven', p2: 'p2' })
	})

	it('skips the product request when no line item names a product', async () => {
		const store = { fetchCollection: vi.fn(async () => [leadProducts[2]]) }
		const ctx = makeBoard({ objectStore: store, items, productNamesById: { x: 'X' } })
		await ctx.fetchLeadProductsForStages()
		expect(store.fetchCollection).toHaveBeenCalledTimes(1)
		expect(ctx.leadProductsByLead).toEqual({ l2: [leadProducts[2]] })
		expect(ctx.productNamesById).toEqual({})
	})

	it('reads a missing collection as empty', async () => {
		const store = { fetchCollection: vi.fn(async () => null) }
		const ctx = makeBoard({ objectStore: store, items })
		await ctx.fetchLeadProductsForStages()
		expect(ctx.leadProductsByLead).toEqual({})
		expect(ctx.productNamesById).toEqual({})
	})

	it('reads a missing product collection as no names', async () => {
		const store = { fetchCollection: vi.fn(async (type) => (type === 'leadProduct' ? leadProducts : null)) }
		const ctx = makeBoard({ objectStore: store, items })
		await ctx.fetchLeadProductsForStages()
		expect(ctx.productNamesById).toEqual({})
	})

	it('clears both maps when the product request fails', async () => {
		const store = {
			fetchCollection: vi.fn(async (type) => {
				if (type === 'product') throw new Error('down')
				return leadProducts
			}),
		}
		const ctx = makeBoard({ objectStore: store, items, productNamesById: { x: 'X' } })
		await ctx.fetchLeadProductsForStages()
		expect(ctx.leadProductsByLead).toEqual({})
		expect(ctx.productNamesById).toEqual({})
	})
})

describe('getStageBreakdown', () => {
	it('sums the line items of the stage leads per product, top five by total', () => {
		const leads = ['a', 'b'].map((id) => ({ id, _schemaSlug: 'lead' }))
		const byLead = {
			a: [1, 2, 3, 4].map((n) => ({ product: 'p' + n, total: String(n) })).concat([{ product: null, total: 9 }]),
			b: [5, 6].map((n) => ({ product: 'p' + n, total: n })).concat([{ product: 'p1', total: 'x' }]),
		}
		const ctx = makeBoard({
			getStageItems: () => [...leads, { id: 'r', _schemaSlug: 'request' }],
			leadProductsByLead: byLead,
			productNamesById: { p1: 'One' },
		})
		const result = ctx.getStageBreakdown('New')
		expect(result.remaining).toBe(1)
		expect(result.items).toEqual([
			{ product: 'p6', name: 'p6', count: 1, total: 6 },
			{ product: 'p5', name: 'p5', count: 1, total: 5 },
			{ product: 'p4', name: 'p4', count: 1, total: 4 },
			{ product: 'p3', name: 'p3', count: 1, total: 3 },
			{ product: 'p2', name: 'p2', count: 1, total: 2 },
		])
	})

	it('counts every line item of a product', () => {
		const ctx = makeBoard({
			getStageItems: () => [{ id: 'a', _schemaSlug: 'lead' }, { id: 'c', _schemaSlug: 'lead' }],
			leadProductsByLead: { a: [{ product: 'p1', total: 2 }, { product: 'p1', total: 3 }] },
			productNamesById: { p1: 'One' },
		})
		expect(ctx.getStageBreakdown('New')).toEqual({ items: [{ product: 'p1', name: 'One', count: 2, total: 5 }], remaining: 0 })
	})
})

describe('fetchPipelineItems', () => {
	it('does nothing without a selected pipeline', async () => {
		const ctx = makeBoard({ selectedPipelineId: null, itemsFetchSeq: 0, loading: false })
		await ctx.fetchPipelineItems()
		expect(ctx.itemsFetchSeq).toBe(0)
	})

	it('loads through the property mappings, with the loading state', async () => {
		const pipeline = { propertyMappings: [{ schemaSlug: 'lead' }] }
		const seen = []
		const ctx = makeBoard({
			selectedPipelineId: 'p', selectedPipeline: pipeline, itemsFetchSeq: 0, loading: false, items: [],
			fetchItemsViaMappings: vi.fn(async () => {
				seen.push(ctx.loading)
				return ['m']
			}),
			fetchItemsBySlug: vi.fn(),
			fetchLeadProductsForStages: vi.fn(async () => seen.push(ctx.items)),
		})
		await ctx.fetchPipelineItems()
		expect(ctx.fetchItemsViaMappings).toHaveBeenCalledWith(pipeline)
		expect(ctx.fetchItemsBySlug).not.toHaveBeenCalled()
		expect(seen).toEqual([true, ['m']])
		expect(ctx.items).toEqual(['m'])
		expect(ctx.loading).toBe(false)
	})

	it('loads by slug without mappings, silently', async () => {
		const pipeline = { propertyMappings: [] }
		const ctx = makeBoard({
			selectedPipelineId: 'p', selectedPipeline: pipeline, itemsFetchSeq: 4, loading: 'untouched', items: [],
			fetchItemsViaMappings: vi.fn(),
			fetchItemsBySlug: vi.fn(async () => ['s']),
			fetchLeadProductsForStages: vi.fn(),
		})
		await ctx.fetchPipelineItems({ silent: true })
		expect(ctx.fetchItemsBySlug).toHaveBeenCalledWith(pipeline)
		expect(ctx.items).toEqual(['s'])
		expect(ctx.itemsFetchSeq).toBe(5)
		expect(ctx.loading).toBe('untouched')
	})

	it('loads by slug when the pipeline has no mappings at all', async () => {
		const ctx = makeBoard({
			selectedPipelineId: 'p', selectedPipeline: undefined, itemsFetchSeq: 0, loading: false, items: [],
			fetchItemsViaMappings: vi.fn(),
			fetchItemsBySlug: vi.fn(async () => ['s']),
			fetchLeadProductsForStages: vi.fn(),
		})
		await ctx.fetchPipelineItems()
		expect(ctx.fetchItemsBySlug).toHaveBeenCalledWith(undefined)
		expect(ctx.items).toEqual(['s'])
	})

	it('lets only the latest fetch write, and leaves its loading state alone', async () => {
		const ctx = makeBoard({
			selectedPipelineId: 'p', selectedPipeline: {}, itemsFetchSeq: 0, loading: false, items: ['old'],
			fetchItemsViaMappings: vi.fn(),
			fetchItemsBySlug: vi.fn(async () => {
				ctx.itemsFetchSeq += 1
				return ['stale']
			}),
			fetchLeadProductsForStages: vi.fn(),
		})
		await ctx.fetchPipelineItems()
		expect(ctx.items).toEqual(['old'])
		expect(ctx.fetchLeadProductsForStages).not.toHaveBeenCalled()
		expect(ctx.loading).toBe(true)
	})
})

describe('moveItemToStage', () => {
	function card(store, columnProp = 'stage') {
		const item = { id: 'r-1', stage: 'New', status: 'open', stageOrder: 0, _schemaSlug: 'request' }
		const ctx = { objectStore: store, getColumnProperty: () => columnProp, refreshItems: vi.fn() }
		return { ctx, item }
	}

	it('does nothing when the card is dropped on its own stage', async () => {
		const store = { fetchObject: vi.fn() }
		const { ctx, item } = card(store)
		await board.methods.moveItemToStage.call(ctx, item, { name: 'New', order: 0 })
		expect(store.fetchObject).not.toHaveBeenCalled()
		expect(ctx.refreshItems).not.toHaveBeenCalled()
	})

	it('saves a ticket with its ticketType and without a stage order when that did not change', async () => {
		const store = {
			fetchObject: vi.fn().mockResolvedValue({ id: 'r-1', stage: 'New', stageOrder: 7 }),
			saveObject: vi.fn().mockResolvedValue({ id: 'r-1' }),
		}
		const { ctx, item } = card(store)
		await board.methods.moveItemToStage.call(ctx, item, { name: 'Done', order: '2' })
		expect(store.fetchObject).toHaveBeenCalledWith('ticket', 'r-1')
		expect(store.saveObject).toHaveBeenCalledWith('ticket', { id: 'r-1', stage: 'Done', stageOrder: 7, ticketType: 'request' })
		expect(item.stage).toBe('Done')
		expect(item.stageOrder).toBe(0)
		expect(ctx.refreshItems).toHaveBeenCalled()
	})

	it('keeps a ticketType the object already has', async () => {
		const store = {
			fetchObject: vi.fn().mockResolvedValue({ id: 'r-1', ticketType: 'complaint' }),
			saveObject: vi.fn().mockResolvedValue({ id: 'r-1' }),
		}
		const { ctx, item } = card(store)
		await board.methods.moveItemToStage.call(ctx, item, { name: 'Done', order: 4 })
		expect(store.saveObject).toHaveBeenCalledWith('ticket', { id: 'r-1', ticketType: 'complaint', stage: 'Done', stageOrder: 4 })
	})

	it('moves on another column property without touching the stage order', async () => {
		const store = {
			fetchObject: vi.fn().mockResolvedValue({ id: 'r-1' }),
			saveObject: vi.fn().mockResolvedValue(null),
			errors: { ticket: { isValidation: false, message: 'ignored' } },
		}
		const { ctx, item } = card(store, 'status')
		await board.methods.moveItemToStage.call(ctx, item, { name: 'closed', order: 9 })
		expect(store.saveObject).toHaveBeenCalledWith('ticket', { id: 'r-1', status: 'closed', ticketType: 'request' })
		expect(item.status).toBe('open')
		expect(item.stageOrder).toBe(0)
		expect(dialogs.showError).toHaveBeenCalledWith('Could not move the card to closed.')
		expect(ctx.refreshItems).not.toHaveBeenCalled()
	})

	it('falls back to the generic message for a validation refusal without a message', async () => {
		const store = {
			fetchObject: vi.fn().mockResolvedValue({ id: 'r-1' }),
			saveObject: vi.fn().mockResolvedValue(null),
			errors: { ticket: { isValidation: true, message: '' } },
		}
		const { ctx, item } = card(store)
		await board.methods.moveItemToStage.call(ctx, item, { name: 'Done', order: 1 })
		expect(dialogs.showError).toHaveBeenCalledWith('Could not move the card to Done.')
	})
})
