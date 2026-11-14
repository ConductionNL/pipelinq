// @vitest-environment jsdom
/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 * SPDX-License-Identifier: EUPL-1.2
 *
 * The find client widget's plumbing around a result: loading the registry
 * and the first results, the debounced search, opening a client, copying its
 * e-mail address, and preparing and cancelling a request or lead for it.
 *
 * @spec exclude Client-side dashboard widget behaviour.
 */
import { afterEach, beforeAll, beforeEach, describe, expect, it, vi } from 'vitest'

vi.mock('@nextcloud/router', () => ({ generateUrl: (p) => '/index.php' + p }))
vi.mock('@nextcloud/vue', () => ({ NcButton: {}, NcLoadingIcon: {}, NcNoteCard: {}, NcSelect: {}, NcTextField: {} }))
vi.mock('@conduction/nextcloud-vue', () => ({ openRowTarget: vi.fn() }))
vi.mock('../../src/store/store.js', () => ({ initializeStores: vi.fn() }))
vi.mock('../../src/services/objectFetch.js', () => ({ fetchRegisteredObjects: vi.fn() }))

let widget
let openRowTarget
let initializeStores
let fetchRegisteredObjects
let error

beforeAll(async () => {
	globalThis.t = (app, text) => text
	widget = (await import('../../src/views/widgets/FindClientWidget.vue')).default
	openRowTarget = (await import('@conduction/nextcloud-vue')).openRowTarget
	initializeStores = (await import('../../src/store/store.js')).initializeStores
	fetchRegisteredObjects = (await import('../../src/services/objectFetch.js')).fetchRegisteredObjects
}, 60000)

beforeEach(() => {
	vi.clearAllMocks()
	error = vi.spyOn(console, 'error').mockImplementation(() => {})
})

afterEach(() => {
	vi.restoreAllMocks()
	vi.unstubAllGlobals()
	vi.useRealTimers()
})

/**
 * A bare widget context with the methods bound to it.
 *
 * @param {object} state State to set over the defaults
 * @return {object} The context
 */
function bind(state = {}) {
	const ctx = { ...widget.data.call({}), ...state }
	for (const [name, fn] of Object.entries(widget.methods)) {
		if (!(name in ctx)) {
			ctx[name] = fn.bind(ctx)
		}
	}
	return ctx
}

describe('FindClientWidget loading', () => {
	it('reads the registry and lists the first clients', async () => {
		const registry = { client: { register: 'r', schema: 'client' } }
		initializeStores.mockResolvedValue({ objectStore: { objectTypeRegistry: registry } })
		fetchRegisteredObjects.mockResolvedValue([{ id: 'c-1' }])
		const ctx = bind()
		const pending = ctx.fetchData()
		expect(ctx.loading).toBe(true)
		await pending
		expect(ctx.config).toBe(registry)
		expect(fetchRegisteredObjects).toHaveBeenCalledWith(registry, { type: 'client', params: { _search: '', _limit: 20 } })
		expect(ctx.clients).toEqual([{ id: 'c-1' }])
		expect(ctx.loading).toBe(false)
	})

	it('logs a failed start and stops loading', async () => {
		const failure = new Error('no stores')
		initializeStores.mockRejectedValue(failure)
		const ctx = bind()
		await ctx.fetchData()
		expect(error).toHaveBeenCalledWith('FindClientWidget fetch error:', failure)
		expect(ctx.loading).toBe(false)
	})

	it('does not search without a client schema, and logs a failed search', async () => {
		const ctx = bind({ config: {} })
		await ctx.searchClients()
		expect(fetchRegisteredObjects).not.toHaveBeenCalled()
		const failure = new Error('down')
		fetchRegisteredObjects.mockRejectedValue(failure)
		const configured = bind({ config: { client: {} }, clients: [{ id: 'kept' }] })
		await configured.searchClients()
		expect(error).toHaveBeenCalledWith('FindClientWidget search error:', failure)
		expect(configured.clients).toEqual([{ id: 'kept' }])
	})

	it('searches 300 ms after the last keystroke, and stops waiting when unmounted', () => {
		vi.useFakeTimers()
		const ctx = bind()
		ctx.searchClients = vi.fn()
		widget.watch.searchQuery.call(ctx)
		vi.advanceTimersByTime(200)
		widget.watch.searchQuery.call(ctx)
		vi.advanceTimersByTime(299)
		expect(ctx.searchClients).not.toHaveBeenCalled()
		vi.advanceTimersByTime(1)
		expect(ctx.searchClients).toHaveBeenCalledTimes(1)
		widget.watch.searchQuery.call(ctx)
		widget.beforeUnmount.call(ctx)
		vi.advanceTimersByTime(1000)
		expect(ctx.searchClients).toHaveBeenCalledTimes(1)
	})

	it('shows at most twenty results', () => {
		const clients = Array.from({ length: 25 }, (_, i) => ({ id: i }))
		expect(widget.computed.filteredClients.call({ clients })).toHaveLength(20)
	})
})

describe('FindClientWidget opening a client', () => {
	it('opens the client page for the event', () => {
		const ctx = bind()
		const event = { type: 'click' }
		expect(ctx.clientUrl({ id: 'c-1' })).toBe('/index.php/apps/pipelinq/clients/c-1')
		ctx.viewClient({ id: 'c-1' }, event)
		expect(openRowTarget).toHaveBeenCalledWith(event, '/index.php/apps/pipelinq/clients/c-1')
	})

	it('opens it on the space bar without scrolling the page', () => {
		const ctx = bind()
		const event = { type: 'keydown', preventDefault: vi.fn() }
		ctx.viewClientOnSpace({ id: 'c-2' }, event)
		expect(openRowTarget).toHaveBeenCalledWith(event, '/index.php/apps/pipelinq/clients/c-2')
		expect(event.preventDefault).toHaveBeenCalledTimes(1)
	})
})

describe('FindClientWidget e-mail copy', () => {
	it('copies the address and shows the feedback for two seconds', async () => {
		vi.useFakeTimers()
		const writeText = vi.fn(async () => {})
		vi.stubGlobal('navigator', { clipboard: { writeText } })
		const ctx = bind()
		await ctx.copyEmail({ email: 'a@b.nl' })
		expect(writeText).toHaveBeenCalledWith('a@b.nl')
		expect(ctx.copyFeedback).toBe(true)
		vi.advanceTimersByTime(1999)
		expect(ctx.copyFeedback).toBe(true)
		vi.advanceTimersByTime(1)
		expect(ctx.copyFeedback).toBe(false)
	})

	it('logs a refused copy without feedback', async () => {
		const failure = new Error('denied')
		vi.stubGlobal('navigator', { clipboard: { writeText: vi.fn(async () => { throw failure }) } })
		const ctx = bind()
		await ctx.copyEmail({ email: 'a@b.nl' })
		expect(error).toHaveBeenCalledWith('Failed to copy email:', failure)
		expect(ctx.copyFeedback).toBe(false)
	})
})

describe('FindClientWidget actions for a client', () => {
	it('prepares a request or a lead with an empty title, and cancels either', () => {
		const ctx = bind({ actionTitle: 'old' })
		const client = { id: 'c-1' }
		ctx.createRequestForClient(client)
		expect([ctx.actionClient, ctx.actionType, ctx.actionTitle]).toEqual([client, 'request', ''])
		ctx.actionTitle = 'typed'
		ctx.createLeadForClient(client)
		expect([ctx.actionClient, ctx.actionType, ctx.actionTitle]).toEqual([client, 'lead', ''])
		ctx.actionTitle = 'typed'
		ctx.cancelAction()
		expect([ctx.actionClient, ctx.actionType, ctx.actionTitle]).toEqual([null, '', ''])
	})
})
