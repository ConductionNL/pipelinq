// @vitest-environment jsdom
/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 * SPDX-License-Identifier: EUPL-1.2
 *
 * The pipeline board's live subscriptions: one per object type the board
 * renders, idempotent per scope, released on a pipeline switch, and stale
 * handles dropped when a release lands while a subscribe is in flight.
 *
 * @spec openspec/specs/realtime-updates-ui/spec.md
 */

import { afterEach, beforeAll, describe, expect, it, vi } from 'vitest'

vi.mock('@nextcloud/dialogs', () => ({ showError: vi.fn() }))
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
	globalThis.t = (app, text) => text
	board = (await import('../../src/views/pipeline/PipelineBoard.vue')).default
}, 60000)

afterEach(() => {
	vi.restoreAllMocks()
})

/**
 * A board context: every method bound to it, the live state as data() sets it.
 *
 * @param {object} objectStore The store.
 * @param {Array<string>} liveTypes The types the board renders.
 * @return {object} The context.
 */
function makeBoard(objectStore, liveTypes) {
	const ctx = { objectStore, liveTypes, liveTypesKey: '', liveEpoch: 0, liveHandles: [] }
	for (const [name, fn] of Object.entries(board.methods)) {
		ctx[name] = fn.bind(ctx)
	}
	return ctx
}

function makeStore() {
	return {
		subscribe: vi.fn(async (objectType) => 'h-' + objectType),
		unsubscribe: vi.fn(),
	}
}

describe('syncLiveSubscriptions', () => {
	it('does nothing when the store has no subscribe', async () => {
		const ctx = makeBoard({ unsubscribe: vi.fn() }, ['lead'])
		await ctx.syncLiveSubscriptions()
		expect(ctx.liveEpoch).toBe(0)
		expect(ctx.liveTypesKey).toBe('')
	})

	it('subscribes once per type and keeps the handles and the scope key', async () => {
		const store = makeStore()
		const ctx = makeBoard(store, ['lead', 'request'])
		await ctx.syncLiveSubscriptions()
		expect(store.subscribe.mock.calls).toEqual([['lead'], ['request']])
		expect(ctx.liveHandles).toEqual(['h-lead', 'h-request'])
		expect(ctx.liveTypesKey).toBe('lead,request')
		expect(ctx.liveEpoch).toBe(1)
	})

	it('leaves the same scope alone', async () => {
		const store = makeStore()
		const ctx = makeBoard(store, ['lead'])
		await ctx.syncLiveSubscriptions()
		await ctx.syncLiveSubscriptions()
		expect(store.subscribe).toHaveBeenCalledTimes(1)
		expect(ctx.liveEpoch).toBe(1)
	})

	it('releases the old scope on a change, and stops at an empty one', async () => {
		const store = makeStore()
		const ctx = makeBoard(store, ['lead'])
		await ctx.syncLiveSubscriptions()
		ctx.liveTypes = []
		await ctx.syncLiveSubscriptions()
		expect(store.unsubscribe.mock.calls).toEqual([['h-lead']])
		expect(ctx.liveHandles).toEqual([])
		expect(ctx.liveTypesKey).toBe('')
		expect(ctx.liveEpoch).toBe(2)
	})

	it('keeps the handles gathered before a failure and warns', async () => {
		const warn = vi.spyOn(console, 'warn').mockImplementation(() => {})
		const store = makeStore()
		store.subscribe.mockImplementation(async (objectType) => {
			if (objectType === 'request') throw new Error('down')
			return 'h-' + objectType
		})
		const ctx = makeBoard(store, ['lead', 'request', 'client'])
		await ctx.syncLiveSubscriptions()
		expect(warn).toHaveBeenCalledWith('[PipelineBoard] live subscription failed:', 'down')
		expect(ctx.liveHandles).toEqual(['h-lead'])
		expect(ctx.liveTypesKey).toBe('lead,request,client')
	})

	it('warns with the thrown value itself when it has no message', async () => {
		const warn = vi.spyOn(console, 'warn').mockImplementation(() => {})
		const store = makeStore()
		store.subscribe.mockRejectedValue('plain')
		const ctx = makeBoard(store, ['lead'])
		await ctx.syncLiveSubscriptions()
		expect(warn).toHaveBeenCalledWith('[PipelineBoard] live subscription failed:', 'plain')
		expect(ctx.liveHandles).toEqual([])
		expect(ctx.liveTypesKey).toBe('')
	})

	it('drops the handles when released while subscribing', async () => {
		const store = makeStore()
		const ctx = makeBoard(store, ['lead'])
		store.subscribe.mockImplementation(async (objectType) => {
			ctx.releaseLiveSubscriptions()
			return 'h-' + objectType
		})
		await ctx.syncLiveSubscriptions()
		expect(store.unsubscribe.mock.calls).toEqual([['h-lead']])
		expect(ctx.liveHandles).toEqual([])
		expect(ctx.liveTypesKey).toBe('')
		expect(ctx.liveEpoch).toBe(2)
	})
})
