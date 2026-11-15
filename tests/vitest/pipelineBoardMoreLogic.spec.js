// @vitest-environment jsdom
/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 * SPDX-License-Identifier: EUPL-1.2
 *
 * More of the pipeline board's logic, on the same plain component context as
 * pipelineBoardLogic.spec.js: the list columns that sort on schema, assignee,
 * due date, stage and age, the "call first" score order, the link to the leads
 * list, a drop that only moves an added card, the quiet refresh, the aging
 * marks, and the live watchers and teardown.
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

const day = 86400000

describe('list sort keys', () => {
	it('sorts by schema, assignee and stage, case-insensitively and with blanks first', () => {
		const b = board({ selectedPipelineId: 'p1' })
		const schema = b.listSortKey('schemaSlug')
		const assignee = b.listSortKey('assignee')
		const stage = b.listSortKey('stage')

		expect(schema({ _schemaSlug: 'request' })).toBe('request')
		expect([assignee({ assignee: 'Ann' }), assignee({})]).toEqual(['ann', ''])
		expect(stage({ _schemaSlug: 'lead', stage: 'Offer' })).toBe('offer')
		expect(stage({ _schemaSlug: 'request', status: 'New' })).toBe('new')
	})

	it('sorts the due date on the expected close date, else the date it happened, else blank', () => {
		const due = board().listSortKey('dueDate')

		expect(due({ expectedCloseDate: '2026-12-01', occurredAt: '2026-01-01' })).toBe('2026-12-01')
		expect(due({ occurredAt: '2026-01-01' })).toBe('2026-01-01')
		expect(due({})).toBe('')
	})

	it('sorts the age on whole days since the last change', () => {
		vi.useFakeTimers()
		vi.setSystemTime(new Date('2026-11-10T12:00:00Z'))
		const age = board().listSortKey('age')

		expect(age({ _dateModified: new Date(Date.now() - 3.5 * day).toISOString() })).toBe(3)
		expect(age({})).toBe(0)
	})

	it('lists the highest score first in the ascending call-first order, an unscored lead last', () => {
		const b = board({
			selectedPipelineId: 'p1',
			sortBy: 'score',
			sortDir: 'asc',
			items: [
				{ id: 'none', title: 'N', _schemaSlug: 'lead', stage: 'New' },
				{ id: 'low', title: 'L', _schemaSlug: 'lead', stage: 'New', qualificationScore: 20 },
				{ id: 'high', title: 'H', _schemaSlug: 'lead', stage: 'Offer', qualificationScore: 80 },
			],
		})

		expect(b.sortedListItems.map((i) => i.id)).toEqual(['high', 'low', 'none'])
		b.sortDir = 'desc'
		expect(b.sortedListItems.map((i) => i.id)).toEqual(['none', 'low', 'high'])
	})
})

describe('aging and dates', () => {
	it('marks a lead stale after 14 days but never a request, and labels and classes its age', () => {
		vi.useFakeTimers()
		vi.setSystemTime(new Date('2026-11-10T12:00:00Z'))
		const b = board()
		const ago = (days) => new Date(Date.now() - days * day).toISOString()

		expect(b.isItemStale({ _schemaSlug: 'lead', _dateModified: ago(14) })).toBe(true)
		expect(b.isItemStale({ _schemaSlug: 'lead', _dateModified: ago(13) })).toBe(false)
		expect(b.isItemStale({ _schemaSlug: 'request', _dateModified: ago(40) })).toBe(false)
		expect([b.getItemAgingClass({ _dateModified: ago(7) }), b.getItemAgingClass({ _dateModified: ago(14) }), b.getItemAgingClass({})]).toEqual(['aging-warning', 'aging-alert', ''])
		expect([b.getItemAgeLabel({}), b.getItemAgeLabel({ _dateModified: ago(1) }), b.getItemAgeLabel({ _dateModified: ago(12) })]).toEqual(['Today', '1d', '12d'])
	})

	it('shows a dash for a missing date', () => {
		expect([board().formatDate(null), board().formatDate('')]).toEqual(['—', '—'])
	})
})

describe('links, drops and refreshes', () => {
	it('links the cards view to the leads list only when there is a router', () => {
		const resolve = vi.fn(() => ({ href: '/apps/pipelinq/leads' }))

		expect(board({ $router: { resolve } }).viewModeLinks).toEqual({ cards: { href: '/apps/pipelinq/leads', to: { name: 'Leads' } } })
		expect(resolve).toHaveBeenCalledWith({ name: 'Leads' })
		expect(board().viewModeLinks).toEqual({})
		expect(board({ $router: { resolve: () => ({ href: '' }) } }).viewModeLinks).toEqual({})
	})

	it('moves a card only when the drop added it to the column', () => {
		const b = board()
		b.moveItemToStage = vi.fn()
		const stage = { name: 'Offer' }

		b.onCardDropped({ removed: { element: { id: 'a' } } }, stage)
		expect(b.moveItemToStage).not.toHaveBeenCalled()
		b.onCardDropped({ added: { element: { id: 'a' } } }, stage)
		expect(b.moveItemToStage).toHaveBeenCalledWith({ id: 'a' }, stage)
	})

	it('refreshes the items quietly', async () => {
		const b = board()
		b.fetchPipelineItems = vi.fn(async () => {})

		await b.refreshItems()

		expect(b.fetchPipelineItems).toHaveBeenCalledWith({ silent: true })
	})

	it('has no live types without a selected pipeline', () => {
		expect(board({ selectedPipelineId: 'gone' }).liveTypes).toEqual([])
	})

	it('names the leads that are on no board', () => {
		const b = board({ allLeads: [{ id: 'x', title: 'Lost', pipeline: 'deleted', stage: 'New' }] })

		expect(b.orphanedLeads.map((entry) => entry.lead.id)).toEqual(['x'])
	})
})

describe('live watchers and teardown', () => {
	it('re-scopes the subscriptions when the live types change, and refetches on a live event', () => {
		const ctx = { syncLiveSubscriptions: vi.fn(), onLiveEvent: vi.fn() }

		PipelineBoard.watch.liveTypes.call(ctx)
		PipelineBoard.watch['objectStore.liveLastEventAt'].call(ctx)

		expect(ctx.syncLiveSubscriptions).toHaveBeenCalledTimes(1)
		expect(ctx.onLiveEvent).toHaveBeenCalledTimes(1)
	})

	it('cancels a pending refetch and releases the subscriptions on unmount', () => {
		vi.useFakeTimers()
		const refetch = vi.fn()
		const ctx = { liveRefetchTimer: setTimeout(refetch, 500), releaseLiveSubscriptions: vi.fn() }

		PipelineBoard.beforeUnmount.call(ctx)
		vi.advanceTimersByTime(1000)

		expect(refetch).not.toHaveBeenCalled()
		expect(ctx.releaseLiveSubscriptions).toHaveBeenCalledTimes(1)
	})
})
