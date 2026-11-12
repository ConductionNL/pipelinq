// @vitest-environment jsdom
/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 * SPDX-License-Identifier: EUPL-1.2
 *
 * The lead list's own behaviour: it opens on the "Call first" sort unless
 * the address carries one, remembers and restores the sort it replaced,
 * opens rows, marks overdue rows, and builds its filters and config.
 *
 * @spec openspec/specs/lead-management/spec.md
 */

import { beforeAll, beforeEach, describe, expect, it, vi } from 'vitest'

const openRowTarget = vi.hoisted(() => vi.fn())
vi.mock('@conduction/nextcloud-vue', () => ({ CnIndexPage: {}, openRowTarget }))
vi.mock('../../src/store/modules/object.js', () => ({ useObjectStore: () => ({}) }))
vi.mock('../../src/store/modules/settings.js', () => ({ useSettingsStore: () => ({}) }))

const CALL_FIRST = [
	{ key: 'qualificationScore', order: 'desc' },
	{ key: '@self.updated', order: 'asc' },
]

let list

beforeAll(async () => {
	globalThis.t = (app, text) => text
	list = (await import('../../src/views/leads/LeadList.vue')).default
})

beforeEach(() => {
	openRowTarget.mockReset()
})

/**
 * A context with the list's methods bound, as `this` inside the component.
 *
 * @param {object} extra Fields to add.
 * @return {object}
 */
function context(extra = {}) {
	const ctx = { sortBeforeCallFirst: [], stages: [], defaultPipelineId: '', ...extra }
	for (const [name, fn] of Object.entries(list.methods)) {
		ctx[name] = fn.bind(ctx)
	}
	return ctx
}

describe('setCallFirst', () => {
	it('remembers the current sort and applies Call first', () => {
		const index = { effectiveSortKeys: [{ key: 'title', order: 'asc' }], onSortEvent: vi.fn() }
		const ctx = context({ $refs: { index } })

		ctx.setCallFirst(true)

		expect(ctx.sortBeforeCallFirst).toEqual([{ key: 'title', order: 'asc' }])
		expect(ctx.sortBeforeCallFirst).not.toBe(index.effectiveSortKeys)
		expect(index.onSortEvent).toHaveBeenCalledWith({ keys: CALL_FIRST })
	})

	it('restores the remembered sort when switched off', () => {
		const index = { effectiveSortKeys: CALL_FIRST, onSortEvent: vi.fn() }
		const ctx = context({ $refs: { index }, sortBeforeCallFirst: [{ key: 'value', order: 'desc' }] })

		ctx.setCallFirst(false)

		expect(index.onSortEvent).toHaveBeenCalledWith({ keys: [{ key: 'value', order: 'desc' }] })
	})

	it('does not overwrite the remembered sort when Call first is already on', () => {
		const index = { effectiveSortKeys: CALL_FIRST, onSortEvent: vi.fn() }
		const ctx = context({ $refs: { index }, sortBeforeCallFirst: [{ key: 'title', order: 'asc' }] })

		ctx.setCallFirst(true)

		expect(ctx.sortBeforeCallFirst).toEqual([{ key: 'title', order: 'asc' }])
	})

	it('copes with no index mounted yet', () => {
		const ctx = context({ $refs: {} })
		expect(() => ctx.setCallFirst(true)).not.toThrow()
		expect(ctx.sortBeforeCallFirst).toEqual([])
	})
})

describe('mounted', () => {
	/**
	 * Run the mounted hook on a context.
	 *
	 * @param {object} extra Context fields.
	 * @return {Promise<object>}
	 */
	async function mountWith(extra) {
		const index = { effectiveSortKeys: [], onSortEvent: vi.fn() }
		const ctx = context({
			$refs: { index },
			callFirst: false,
			settingsStore: { fetchSettings: vi.fn().mockResolvedValue() },
			objectStore: { fetchCollection: vi.fn().mockResolvedValue([]) },
			$watch: vi.fn((getter, cb) => cb(getter())),
			...extra,
		})
		await list.mounted.call(ctx)
		return { ctx, index }
	}

	it('applies Call first when the address has no sort, then loads settings and pipeline', async () => {
		const { ctx, index } = await mountWith({ $route: { query: {} } })

		expect(index.onSortEvent).toHaveBeenCalledWith({ keys: CALL_FIRST })
		expect(ctx.settingsStore.fetchSettings).toHaveBeenCalledTimes(1)
		expect(ctx.objectStore.fetchCollection).toHaveBeenCalledWith('pipeline', { _limit: 50 })
	})

	it('keeps a sort that the address carries', async () => {
		const { index } = await mountWith({ $route: { query: { _order: 'title' } } })
		expect(index.onSortEvent).not.toHaveBeenCalled()
	})

	it('tracks whether the page sort is Call first through the watcher', async () => {
		const { ctx } = await mountWith({
			$route: { query: { _order: 'x' } },
			$refs: { index: { effectiveSortKeys: CALL_FIRST, onSortEvent: vi.fn() } },
		})
		expect(ctx.$watch).toHaveBeenCalledWith(expect.any(Function), expect.any(Function), { immediate: true })
		expect(ctx.callFirst).toBe(true)
	})
})

describe('rows', () => {
	it('opens a lead on its detail route', () => {
		const router = {}
		const event = { ctrlKey: true }
		const ctx = context({ $router: router })

		ctx.openLead({ id: 'lead-7' }, event)

		expect(openRowTarget).toHaveBeenCalledWith(event, { name: 'LeadDetail', params: { id: 'lead-7' } }, router)
	})

	it('marks only open, past-due leads in an open stage as overdue', () => {
		const ctx = context({ stages: [{ name: 'Won', isClosed: true }, { name: 'Open' }] })

		expect(ctx.rowClassFor({ expectedCloseDate: '2000-01-01', stage: 'Open', status: 'open' })).toBe('lead-overdue')
		expect(ctx.rowClassFor({ expectedCloseDate: '2000-01-01', stage: 'Won', status: 'open' })).toBe('')
		expect(ctx.rowClassFor({ expectedCloseDate: '2000-01-01', stage: 'Open', status: 'lost' })).toBe('')
		expect(ctx.rowClassFor({ expectedCloseDate: '2999-01-01', stage: 'Open', status: 'open' })).toBe('')
	})
})

describe('loadDefaultPipeline', () => {
	it('swallows a failed read and keeps the defaults', async () => {
		const ctx = context({ objectStore: { fetchCollection: vi.fn().mockRejectedValue(new Error('offline')) } })

		await expect(ctx.loadDefaultPipeline()).resolves.toBeUndefined()
		expect(ctx.defaultPipelineId).toBe('')
		expect(ctx.stages).toEqual([])
	})

	it('keeps the stages when the pipeline has none and stringifies the id', async () => {
		const ctx = context({
			stages: [{ name: 'Kept' }],
			objectStore: { fetchCollection: vi.fn().mockResolvedValue([{ id: 42, isDefault: true, propertyMappings: [{ schemaSlug: 'lead' }] }]) },
		})

		await ctx.loadDefaultPipeline()

		expect(ctx.defaultPipelineId).toBe('42')
		expect(ctx.stages).toEqual([{ name: 'Kept' }])
	})
})

describe('computed config', () => {
	it('builds the quick filters with the stale threshold in the out-of-date filter', () => {
		const filters = list.computed.quickFilters.call({ staleThreshold: 21 })

		expect(filters.map((f) => f.label)).toEqual(['Open (lead status)', 'Mine', 'Closes this month', 'Out of date', 'Won', 'Lost'])
		expect(filters[0]).toEqual({ label: 'Open (lead status)', filter: { status: 'open' }, default: true, showCount: true })
		expect(filters[1].filter).toEqual({ status: 'open', assignee: '@me' })
		expect(filters[3].filter).toEqual({ status: 'open', '@self[updated][lt]': '@today-21d' })
	})

	it('reads the stale threshold from the settings config', () => {
		expect(typeof list.computed.staleThreshold.call({ settingsStore: { config: {} } })).toBe('number')
	})

	it('offers download and a primary new-lead button', () => {
		expect(list.computed.headerButtons.call({})).toEqual([
			{ action: 'export', label: 'Download' },
			{ action: 'add', variant: 'primary', icon: 'Plus', label: 'New lead' },
		])
	})

	it('passes the default pipeline to the page config only once known', () => {
		expect(list.computed.appConfig.call({ defaultPipelineId: '' })).toEqual({})
		expect(list.computed.appConfig.call({ defaultPipelineId: 'p-1' })).toEqual({ defaultPipeline: 'p-1' })
		expect(list.computed.sidebarConfig.call({})).toEqual({ enabled: true, showMetadata: true })
	})
})
