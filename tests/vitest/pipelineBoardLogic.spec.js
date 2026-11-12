// @vitest-environment jsdom
/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 * SPDX-License-Identifier: EUPL-1.2
 *
 * The pipeline board's own logic, run against a plain component context: which
 * items land in which stage, filters and chips, sorting, stage limits and
 * totals, fetching items per schema, moving a card to another stage, live
 * subscriptions and the CSV download.
 *
 * @spec openspec/specs/pipeline/spec.md
 */

import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'

vi.hoisted(() => {
	globalThis.t = (app, text, vars = {}) => String(text).replace(/\{(\w+)\}/g, (m, k) => (k in vars ? String(vars[k]) : m))
	globalThis.n = (app, one, many, count, vars = {}) => globalThis.t(app, count === 1 ? one : many, vars).replace('%n', String(count))
})

const stub = (name) => ({ name, render: () => null })
const toasts = []
const csv = []
vi.mock('@conduction/nextcloud-vue', () => ({
	CnActionsBar: stub('CnActionsBar'),
	CnBuildiqEditButton: stub('CnBuildiqEditButton'),
	CnPageHeader: stub('CnPageHeader'),
	CnQuickFilterBar: stub('CnQuickFilterBar'),
	openRowTarget: vi.fn(),
	useLook: () => ({ isBoard: true, lookClass: '' }),
}))
vi.mock('@nextcloud/dialogs', () => ({ showError: (m) => toasts.push(m) }))
vi.mock('vuedraggable', () => ({ default: stub('Draggable') }))
vi.mock('../../src/components/leadScore/LeadScoreBadge.vue', () => ({ default: stub('LeadScoreBadge') }))
vi.mock('../../src/dialogs/LeadCreateDialog.vue', () => ({ default: stub('LeadCreateDialog') }))
vi.mock('../../src/dialogs/PipelineFormDialog.vue', () => ({ default: stub('PipelineFormDialog') }))
vi.mock('../../src/views/pipeline/PipelineCard.vue', () => ({ default: stub('PipelineCard') }))
vi.mock('../../src/services/csvExport.js', () => ({ downloadCsv: (...args) => csv.push(args) }))
vi.mock('../../src/store/modules/object.js', () => ({ useObjectStore: () => ({}) }))
vi.mock('../../src/store/store.js', () => ({ initializeStores: vi.fn() }))

const { openRowTarget } = await import('@conduction/nextcloud-vue')
const { default: PipelineBoard } = await import('../../src/views/pipeline/PipelineBoard.vue')

/**
 * Translate as the identity, filling {placeholders}.
 *
 * @param {string} app The app id.
 * @param {string} text The text.
 * @param {object} vars The placeholders.
 * @return {string} The filled text.
 */
const t = (app, text, vars = {}) => text.replace(/\{(\w+)\}/g, (m, k) => (k in vars ? String(vars[k]) : m))
globalThis.t = t

const pipeline = {
	id: 'p1',
	title: 'Sales',
	stages: [
		{ name: 'Won', order: 9, isClosed: true },
		{ name: 'New', order: 1, maxItems: 2 },
		{ name: 'Offer', order: 2 },
	],
	propertyMappings: [
		{ schemaSlug: 'lead', columnProperty: 'stage', totalsProperty: 'value' },
		{ schemaSlug: 'request', columnProperty: 'status' },
	],
}

/**
 * A board context: the component's data, its computed values as getters and
 * its methods bound, over a store double.
 *
 * @param {object} [state] Data to set.
 * @param {object} [store] Store fields to set.
 * @return {object} The context.
 */
function board(state = {}, store = {}) {
	const objectStore = {
		collections: { pipeline: [pipeline] },
		objectTypeRegistry: { lead: { register: 'r', schema: 'lead-s' }, ticket: { register: 'r', schema: 'ticket-s' } },
		fetchCollection: vi.fn(async () => []),
		fetchObject: vi.fn(),
		saveObject: vi.fn(),
		errors: {},
		...store,
	}
	const ctx = { ...PipelineBoard.data(), t, n: (a, one, many, c) => (c === 1 ? one : many).replace('%n', c), $router: null, ...state }
	for (const [name, def] of Object.entries(PipelineBoard.computed)) {
		Object.defineProperty(ctx, name, { get: () => def.call(ctx), configurable: true })
	}
	Object.defineProperty(ctx, 'objectStore', { get: () => objectStore, configurable: true })
	for (const [name, fn] of Object.entries(PipelineBoard.methods)) {
		ctx[name] = typeof fn === 'function' ? fn.bind(ctx) : fn
	}
	return ctx
}

const items = [
	{ id: 'a', title: 'Alpha', _schemaSlug: 'lead', stage: 'New', value: '100', stageOrder: 2, assignee: 'ann', priority: 'low' },
	{ id: 'b', title: 'beta', _schemaSlug: 'lead', stage: 'Offer', value: 50, assignee: 'bob', priority: 'urgent' },
	{ id: 'c', title: 'Gamma', _schemaSlug: 'request', status: 'New', stageOrder: 1 },
	{ id: 'd', title: 'Delta', _schemaSlug: 'lead', value: 25 },
]

beforeEach(() => {
	toasts.length = 0
	csv.length = 0
	vi.clearAllMocks()
	globalThis.OC = { requestToken: 'tok' }
})

afterEach(() => {
	vi.useRealTimers()
	vi.unstubAllGlobals()
})

describe('stages and items', () => {
	it('orders the stages and splits open from closed', () => {
		const b = board({ selectedPipelineId: 'p1' })
		expect(b.sortedStages.map((s) => s.name)).toEqual(['New', 'Offer', 'Won'])
		expect([b.openStages.map((s) => s.name), b.closedStages.map((s) => s.name)]).toEqual([['New', 'Offer'], ['Won']])
		expect([b.hasMultipleSchemas, b.hasTotals]).toEqual([true, true])
		expect(b.pipelineSelectOptions).toEqual([{ value: 'p1', label: 'Sales' }])
		expect(board().sortedStages).toEqual([])
	})

	it('puts each item in the stage its own column names, and unstaged ones in the first open stage', () => {
		const b = board({ selectedPipelineId: 'p1', items })
		expect(b.getStageItems('New').map((i) => i.id)).toEqual(['d', 'c', 'a'])
		expect(b.getStageItems('Offer').map((i) => i.id)).toEqual(['b'])
		expect(b.getStageTotalValue('New')).toBe(125)
		expect([b.getItemTotalsValue(items[0]), b.getItemTotalsValue(items[2]), b.getItemTotalsValue({ _schemaSlug: 'lead' })]).toEqual(['100', null, null])
		expect([b.getColumnProperty(items[2]), b.getColumnProperty({ _schemaSlug: 'other' })]).toEqual(['status', 'stage'])
	})

	it('counts a stage against its limit', () => {
		const b = board({ selectedPipelineId: 'p1', items })
		const [won, newStage, offer] = pipeline.stages
		expect([b.stageCountLabel(newStage), b.stageCountLabel(offer), b.stageCountLabel(won)]).toEqual(['3 / 2', '1', '0'])
		expect([b.isStageFull(newStage), b.isStageFull(offer)]).toEqual([true, false])
	})
})

describe('filters and chips', () => {
	it('filters by schema, chip and search text, and lists the active filters', () => {
		const b = board({ selectedPipelineId: 'p1', items })
		expect(b.showFilterOptions.map((o) => o.label)).toEqual(['All', 'Leads', 'Requests'])
		b.showFilter = { id: 'lead' }
		b.searchQuery = ' ALP '
		expect(b.filteredItems.map((i) => i.id)).toEqual(['a'])
		expect(b.activeFilterChips.map((c) => c.label)).toEqual(['Pipeline: Sales', 'Type: Leads', 'Search: ALP'])
		expect(b.filterMenuLabel).toBe('Filter, 3 active')
		expect(b.headerCountLine).toBe('1 of 3 open leads · board per stage')
		const none = board()
		expect([none.activeFilterChips, none.filterMenuLabel]).toEqual([[], 'Filter'])
	})

	it('limits to my leads, closing this month or stale leads per chip', () => {
		vi.useFakeTimers({ now: new Date('2026-05-15T12:00:00Z'), toFake: ['Date'] })
		window.OC = { getCurrentUser: () => ({ uid: 'ann' }) }
		const b = board({ selectedPipelineId: 'p1', items: [...items, { id: 'e', _schemaSlug: 'lead', expectedCloseDate: '2026-05-30' }] })
		expect(b.chipCounts[0]).toBe(5)
		expect([b.matchesChip(items[0], 1), b.matchesChip(items[1], 1)]).toEqual([true, false])
		expect([b.matchesChip({ expectedCloseDate: '2026-05-30' }, 2), b.matchesChip({ expectedCloseDate: '2026-06-01' }, 2), b.matchesChip({}, 2)]).toEqual([true, false, false])
		b.chipIndex = 1
		expect(b.activeFilterChips.map((c) => c.key)).toEqual(['pipeline', 'chip'])
		window.OC = undefined
		expect(b.matchesChip(items[0], 1)).toBe(false)
	})

	it('removes one filter or all of them', async () => {
		const b = board({ selectedPipelineId: 'p1', items, showFilter: 'lead', chipIndex: 2, searchQuery: 'x' })
		await b.removeActiveFilter({ key: 'type' })
		await b.removeActiveFilter({ key: 'chip' })
		await b.removeActiveFilter({ key: 'search' })
		expect([b.showFilter, b.chipIndex, b.searchQuery]).toEqual(['all', 0, ''])
		Object.assign(b, { showFilter: 'lead', chipIndex: 2, searchQuery: 'x' })
		await b.clearActiveFilters()
		expect([b.showFilter, b.chipIndex, b.searchQuery, b.selectedPipelineId]).toEqual(['all', 0, '', 'p1'])
	})
})

describe('the list view', () => {
	it('sorts by each column in both directions', () => {
		const b = board({ selectedPipelineId: 'p1', items })
		expect(b.sortedListItems.map((i) => i.id)).toEqual(['a', 'b', 'd', 'c'])
		b.toggleSort('title')
		expect([b.sortDir, b.sortedListItems.map((i) => i.id)]).toEqual(['desc', ['c', 'd', 'b', 'a']])
		b.toggleSort('value')
		expect([b.sortBy, b.sortDir, b.sortedListItems.map((i) => i.id)]).toEqual(['value', 'asc', ['c', 'd', 'b', 'a']])
		b.sortBy = 'priority'
		expect(b.sortedListItems.map((i) => i.id)[0]).toBe('b')
		b.sortBy = 'unknown'
		expect(b.sortedListItems.map((i) => i.id)).toEqual(['a', 'b', 'c', 'd'])
		expect([b.ariaSortOf('unknown'), b.ariaSortOf('title')]).toEqual(['ascending', 'none'])
		expect(b.listColumns.map((c) => c.key)).toContain('score')
	})

	it('flags an overdue item and formats dates', () => {
		vi.useFakeTimers({ now: new Date('2026-05-15T12:00:00Z'), toFake: ['Date'] })
		const b = board()
		expect([b.isItemOverdue({ expectedCloseDate: '2026-05-01' }), b.isItemOverdue({ occurredAt: '2026-06-01' }), b.isItemOverdue({})]).toEqual([true, false, false])
		expect(b.formatDate('')).toBe('—')
	})
})

describe('fetching', () => {
	it('fetches the items of each mapped schema for the selected pipeline', async () => {
		const fetchSpy = vi.fn(async (url) => ({ ok: true, json: async () => ({ results: [{ id: url.includes('lead-s') ? 'L' : 'T' }] }) }))
		vi.stubGlobal('fetch', fetchSpy)
		const b = board({ selectedPipelineId: 'p1' })
		await b.fetchPipelineItems()
		expect(b.items.map((i) => [i.id, i._schemaSlug])).toEqual([['L', 'lead'], ['T', 'request']])
		expect(fetchSpy.mock.calls[0][0]).toContain('/objects/r/lead-s?pipeline=p1&_limit=200')
		expect(fetchSpy.mock.calls[1][0]).toContain('/objects/r/ticket-s?ticketType=request&pipeline=p1&_limit=200')
		expect(fetchSpy.mock.calls[0][1].headers.requesttoken).toBe('tok')
		expect(b.loading).toBe(false)
	})

	it('falls back to the entity type, and returns nothing for an unknown schema or a failed read', async () => {
		vi.stubGlobal('fetch', vi.fn(async () => ({ ok: false })))
		const b = board({ selectedPipelineId: 'p2' }, { collections: { pipeline: [{ id: 'p2', entityType: 'lead' }] } })
		await b.fetchPipelineItems()
		expect(b.items).toEqual([])
		expect(await b.fetchSchemaItems('nothing')).toEqual([])
		vi.stubGlobal('fetch', vi.fn(async () => { throw new Error('x') }))
		expect(await b.fetchSchemaItems('lead')).toEqual([])
		await board().fetchPipelineItems()
	})

	it('reads all leads for the orphan check, empty without a lead schema', async () => {
		vi.stubGlobal('fetch', vi.fn(async () => ({ ok: true, json: async () => ({ results: [{ id: 'l' }] }) })))
		expect(await board().fetchAllLeads()).toEqual([{ id: 'l' }])
		expect(await board({}, { objectTypeRegistry: {} }).fetchAllLeads()).toEqual([])
		vi.stubGlobal('fetch', vi.fn(async () => ({ ok: true, json: async () => ({}) })))
		expect(await board().fetchAllLeads()).toEqual([])
		vi.stubGlobal('fetch', vi.fn(async () => { throw new Error('x') }))
		expect(await board().fetchAllLeads()).toEqual([])
	})

	it('keeps only the newest of two overlapping fetches', async () => {
		let release
		const b = board({ selectedPipelineId: 'p1' })
		b.fetchSelectedPipelineItems = vi.fn()
			.mockImplementationOnce(() => new Promise((resolve) => { release = () => resolve([{ id: 'old' }]) }))
			.mockImplementationOnce(async () => [{ id: 'new' }])
		const first = b.fetchPipelineItems()
		await b.fetchPipelineItems({ silent: true })
		release()
		await first
		expect(b.items).toEqual([{ id: 'new' }])
	})

	it('loads the product breakdown of the leads on the board, and clears it when there are none', async () => {
		const b = board({ selectedPipelineId: 'p1', items: [] })
		b.leadProductsByLead = { x: [] }
		await b.fetchLeadProductsForStages()
		expect([b.leadProductsByLead, b.productNamesById]).toEqual([{}, {}])
		b.items = items
		b.objectStore.fetchCollection.mockRejectedValueOnce(new Error('x'))
		await b.fetchLeadProductsForStages()
		expect(b.leadProductsByLead).toEqual({})
		expect(await b.fetchProductNames(new Set())).toEqual({})
		b.toggleStageBreakdown('New')
		expect(b.expandedBreakdownStage).toBe('New')
		b.toggleStageBreakdown('New')
		expect(b.expandedBreakdownStage).toBeNull()
		expect(b.getStageBreakdown('Offer')).toEqual({ items: [], remaining: 0 })
	})

	it('saves a pipeline and reloads the pipelines and items', async () => {
		const b = board({ selectedPipelineId: 'p1', showPipelineForm: true })
		b.refreshItems = vi.fn()
		await b.onPipelineSave({ title: 'New' })
		expect(b.objectStore.saveObject).toHaveBeenCalledWith('pipeline', { title: 'New' })
		expect(b.objectStore.fetchCollection).toHaveBeenCalledWith('pipeline', { _limit: 100 })
		expect([b.showPipelineForm, b.refreshItems.mock.calls.length]).toEqual([false, 1])
	})

	it('switches pipeline only to a different one', async () => {
		const b = board({ selectedPipelineId: 'p1', searchQuery: 'x' })
		b.fetchPipelineItems = vi.fn()
		await b.selectPipeline('p1')
		await b.selectPipeline(null)
		expect(b.fetchPipelineItems).not.toHaveBeenCalled()
		await b.selectPipeline('p9')
		expect([b.selectedPipelineId, b.searchQuery, b.fetchPipelineItems.mock.calls.length]).toEqual(['p9', '', 1])
	})
})

describe('moving cards', () => {
	it('saves a card on its new stage and refreshes', async () => {
		const b = board({ selectedPipelineId: 'p1' })
		b.objectStore.fetchObject.mockResolvedValueOnce({ id: 'a', stage: 'New' })
		b.objectStore.saveObject.mockResolvedValueOnce({ id: 'a' })
		b.refreshItems = vi.fn()
		const card = { id: 'a', _schemaSlug: 'lead', stage: 'New' }
		await b.moveItemToStage(card, { name: 'Offer', order: 2 })
		expect(b.objectStore.fetchObject).toHaveBeenCalledWith('lead', 'a')
		expect(b.objectStore.saveObject.mock.calls[0][0]).toBe('lead')
		expect([card.stage, card.stageOrder, b.refreshItems.mock.calls.length]).toEqual(['Offer', 2, 1])
		await b.moveItemToStage(card, { name: 'Offer' })
		expect(b.objectStore.fetchObject).toHaveBeenCalledTimes(1)
	})

	it('puts a card back and explains when the move is refused', async () => {
		const b = board({ selectedPipelineId: 'p1' })
		b.objectStore.fetchObject.mockResolvedValueOnce(null)
		const card = { id: 'a', _schemaSlug: 'lead', stage: 'New', stageOrder: 1 }
		await b.moveItemToStage(card, { name: 'Offer', order: 2 })
		expect([card.stage, card.stageOrder]).toEqual(['New', 1])
		expect(toasts).toEqual(['Could not move the card to Offer.'])
		b.onCardDropped({ removed: {} }, { name: 'Offer' })
		expect(b.objectStore.fetchObject).toHaveBeenCalledTimes(1)
	})

	it('tracks the drag source to mark drop targets', () => {
		const b = board()
		b.onDragStart('New')
		expect([b.dropListClass('New'), b.dropListClass('Offer')]).toEqual([{ 'kanban-drop--target': false }, { 'kanban-drop--target': true }])
		const list = {}
		expect(b.onCardMove({ to: list, from: {} })).toBe(true)
		expect(b.dropListClass('New')).toEqual({ 'kanban-drop--target': true })
		expect(b.onCardMove({ to: list, from: list, dragged: { parentNode: list } })).toBe(false)
		b.onDragEnd()
		expect([b.dragSourceStage, b.dragLeftSource]).toEqual([null, false])
		b.toggleClosedStage('Won')
		expect(b.expandedClosed).toBe('Won')
		b.toggleClosedStage('Won')
		expect(b.expandedClosed).toBeNull()
	})
})

describe('live updates', () => {
	it('subscribes once per set of live types and releases them', async () => {
		const subscribe = vi.fn(async (type) => `h-${type}`)
		const unsubscribe = vi.fn()
		const b = board({ selectedPipelineId: 'p1' }, { subscribe, unsubscribe })
		expect(b.liveTypes).toEqual(['lead', 'ticket'])
		await b.syncLiveSubscriptions()
		await b.syncLiveSubscriptions()
		expect(subscribe.mock.calls).toEqual([['lead'], ['ticket']])
		expect(b.liveHandles).toEqual(['h-lead', 'h-ticket'])
		b.releaseLiveSubscriptions()
		expect(unsubscribe.mock.calls).toEqual([['h-lead'], ['h-ticket']])
		expect([b.liveHandles, b.liveTypesKey]).toEqual([[], ''])
	})

	it('drops handles that arrive after a newer scope, and survives a failed subscription', async () => {
		const unsubscribe = vi.fn()
		const b = board({ selectedPipelineId: 'p1' }, { subscribe: vi.fn(async () => { throw new Error('ws down') }), unsubscribe })
		vi.spyOn(console, 'warn').mockImplementation(() => {})
		await b.syncLiveSubscriptions()
		expect([b.liveHandles, b.liveTypesKey]).toEqual([[], ''])
		b.adoptLiveHandles(['late'], b.liveEpoch - 1)
		expect(unsubscribe).toHaveBeenCalledWith('late')
		await board({}, {}).syncLiveSubscriptions()
		expect(board().claimLiveScope([])).toBe(false)
	})

	it('refetches quietly half a second after a live event', () => {
		vi.useFakeTimers()
		const b = board({ liveHandles: ['h'] })
		b.refreshItems = vi.fn()
		b.onLiveEvent()
		b.onLiveEvent()
		vi.advanceTimersByTime(499)
		expect(b.refreshItems).not.toHaveBeenCalled()
		vi.advanceTimersByTime(1)
		expect(b.refreshItems).toHaveBeenCalledTimes(1)
		b.loading = true
		b.onLiveEvent()
		vi.advanceTimersByTime(500)
		expect(b.refreshItems).toHaveBeenCalledTimes(1)
		board().onLiveEvent()
	})
})

describe('opening and exporting', () => {
	it('opens a lead or a request on its detail page, and nothing else', () => {
		const b = board()
		b.openItem({ id: 'a', _schemaSlug: 'lead' }, 'e')
		b.openItem({ id: 'c', _schemaSlug: 'request' }, 'e')
		b.openItem({ id: 'x', _schemaSlug: 'other' }, 'e')
		expect(openRowTarget.mock.calls.map((c) => c[1])).toEqual([{ name: 'LeadDetail', params: { id: 'a' } }, { name: 'TicketDetail', params: { id: 'c' } }])
	})

	it('downloads the shown items as CSV', () => {
		const b = board({ selectedPipelineId: 'p1', items: [items[1]] })
		b.downloadLeads()
		expect(csv).toEqual([['pipeline.csv', [{ title: 'beta', stage: 'Offer', value: 50, probability: '', assignee: 'bob', expectedCloseDate: '' }], ['title', 'stage', 'value', 'probability', 'assignee', 'expectedCloseDate']]])
	})

	it('reloads leads and items after a lead is created', async () => {
		const b = board({ showLeadCreate: true })
		b.fetchAllLeads = vi.fn(async () => [{ id: 'l' }])
		b.fetchPipelineItems = vi.fn()
		await b.onLeadCreated()
		expect([b.showLeadCreate, b.allLeads, b.fetchPipelineItems.mock.calls.length]).toEqual([false, [{ id: 'l' }], 1])
	})
})
