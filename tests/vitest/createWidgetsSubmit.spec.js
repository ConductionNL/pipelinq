// @vitest-environment jsdom
/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 * SPDX-License-Identifier: EUPL-1.2
 *
 * The dashboard's create lead and start request widgets: the object each
 * posts, the link it shows afterwards, the lists it reads, and what it does
 * when a request fails.
 *
 * @spec openspec/changes/reverse-2026-05-26-fe-widgets-ui/tasks.md#task-25
 */
import { afterEach, beforeAll, beforeEach, describe, expect, it, vi } from 'vitest'

vi.mock('@nextcloud/vue', () => ({ NcButton: {}, NcNoteCard: {}, NcSelect: {}, NcTextField: {} }))
vi.mock('@nextcloud/router', () => ({ generateUrl: (p) => '/index.php' + p }))
vi.mock('../../src/store/store.js', () => ({ initializeStores: vi.fn() }))
vi.mock('../../src/components/widgets/ClientAutocomplete.vue', () => ({ default: {} }))

let createLead
let startRequest

beforeAll(async () => {
	globalThis.t = (app, text) => text
	globalThis.OC = { requestToken: 'tok' }
	createLead = (await import('../../src/views/widgets/CreateLeadWidget.vue')).default
	startRequest = (await import('../../src/views/widgets/StartRequestWidget.vue')).default
}, 60000)

let error

beforeEach(() => {
	error = vi.spyOn(console, 'error').mockImplementation(() => {})
})

afterEach(() => {
	vi.unstubAllGlobals()
	vi.restoreAllMocks()
})

/**
 * @param {object} widget The widget options.
 * @param {object} state The state.
 * @return {object} A context with the widget's data, the state and every method bound.
 */
function bind(widget, state) {
	const ctx = { ...widget.data.call({}), ...state }
	for (const [name, fn] of Object.entries(widget.methods)) {
		if (!(name in ctx)) {
			ctx[name] = fn.bind(ctx)
		}
	}
	return ctx
}

function answer(...answers) {
	const queue = [...answers]
	vi.stubGlobal('fetch', vi.fn(async () => {
		const next = queue.shift()
		if (next instanceof Error) throw next
		return { ok: next.ok, json: async () => next.body }
	}))
}

const headers = { 'Content-Type': 'application/json', requesttoken: 'tok', 'OCS-APIREQUEST': 'true' }
const config = { lead: { register: 'r', schema: 'lead' }, ticket: { register: 'r', schema: 'ticket' }, pipeline: { register: 'r', schema: 'pipeline' } }

describe('CreateLeadWidget', () => {
	it('posts the lead on the first stage of the chosen pipeline, with the client, value and source', async () => {
		answer({ ok: true, body: { uuid: 'u1' } })
		const pipeline = { id: 'p1', stages: [{ name: 'B', order: 2 }, { name: 'A', order: 0 }] }
		const ctx = bind(createLead, { config, selectedClient: { id: 'c1' }, form: { title: 'Ovens', pipeline, value: '12.5', source: { id: 'web' } } })
		await ctx.onSubmit()
		expect(fetch).toHaveBeenCalledWith('/index.php/apps/openregister/api/objects/r/lead', { method: 'POST', headers, body: JSON.stringify({ title: 'Ovens', status: 'open', stage: 'A', stageOrder: 1, pipeline: 'p1', client: 'c1', value: 12.5, source: 'web' }) })
		expect([ctx.success, ctx.successLink, ctx.submitting, ctx.submitted]).toEqual([true, '/index.php/apps/pipelinq/leads/u1', false, true])
	})

	it('posts a bare lead without a pipeline, and a source typed as text', async () => {
		answer({ ok: true, body: { id: 'l1', uuid: 'u1' } })
		const ctx = bind(createLead, { config, selectedClient: null, form: { title: 'X', pipeline: null, value: 'abc', source: 'fair' } })
		await ctx.onSubmit()
		expect(JSON.parse(fetch.mock.calls[0][1].body)).toEqual({ title: 'X', status: 'open', stage: '', stageOrder: 1, value: 0, source: 'fair' })
		expect(ctx.successLink).toBe('/index.php/apps/pipelinq/leads/l1')
	})

	it('posts nothing without a title or a lead schema, and logs a failed post', async () => {
		vi.stubGlobal('fetch', vi.fn())
		const untitled = bind(createLead, { config, form: { title: '' } })
		await untitled.onSubmit()
		expect(untitled.submitted).toBe(true)
		const unconfigured = bind(createLead, { config: {}, form: { title: 'X' } })
		await unconfigured.onSubmit()
		expect(error).toHaveBeenCalledWith('Lead schema not configured')
		expect(fetch).not.toHaveBeenCalled()
		answer({ ok: false })
		const failed = bind(createLead, { config, form: { title: 'X', pipeline: null } })
		await failed.onSubmit()
		expect(error).toHaveBeenLastCalledWith('CreateLeadWidget create error:', new Error('Failed to create lead'))
		expect([failed.success, failed.submitting]).toEqual([false, false])
	})

	it('reads the pipelines and picks the first', async () => {
		answer({ ok: true, body: { results: [{ id: 'p1', title: 'Sales' }] } }, { ok: false }, new Error('down'))
		const ctx = bind(createLead, { config, form: { pipeline: null } })
		Object.defineProperty(ctx, 'pipelineOptions', { get: () => createLead.computed.pipelineOptions.call(ctx) })
		await ctx.fetchPipelines()
		expect(fetch).toHaveBeenCalledWith('/index.php/apps/openregister/api/objects/r/pipeline?_limit=50', { headers })
		expect(ctx.form.pipeline).toEqual({ id: 'p1', label: 'Sales', stages: [] })
		await ctx.fetchPipelines()
		expect(ctx.pipelines).toEqual([{ id: 'p1', title: 'Sales' }])
		await ctx.fetchPipelines()
		expect(error).toHaveBeenCalledWith('Failed to fetch pipelines:', new Error('down'))
	})
})

describe('StartRequestWidget', () => {
	it('posts a request ticket now, with the client, category and channel', async () => {
		vi.useFakeTimers()
		vi.setSystemTime(new Date('2026-11-10T09:00:00Z'))
		answer({ ok: true, body: { id: 't1' } })
		const ctx = bind(startRequest, { config, selectedClient: { id: 'c1' }, form: { title: 'Pothole', priority: { id: 'high' }, category: { id: 'roads' }, channel: 'phone' } })
		await ctx.onSubmit()
		vi.useRealTimers()
		expect(fetch).toHaveBeenCalledWith('/index.php/apps/openregister/api/objects/r/ticket', { method: 'POST', headers, body: JSON.stringify({ ticketType: 'request', title: 'Pothole', status: 'new', priority: 'high', occurredAt: '2026-11-10T09:00:00.000Z', client: 'c1', category: 'roads', channel: 'phone' }) })
		expect([ctx.success, ctx.successLink]).toEqual([true, '/index.php/apps/pipelinq/tickets/t1'])
	})

	it('takes a typed priority or normal, and leaves empty fields out', async () => {
		answer({ ok: true, body: { uuid: 'u' } }, { ok: true, body: { uuid: 'u' } })
		const ctx = bind(startRequest, { config, selectedClient: null, form: { title: 'X', priority: 'low', category: null, channel: { id: 'mail' } } })
		await ctx.onSubmit()
		expect(JSON.parse(fetch.mock.calls[0][1].body)).toMatchObject({ priority: 'low', channel: 'mail' })
		expect(JSON.parse(fetch.mock.calls[0][1].body)).not.toHaveProperty('category')
		ctx.form = { title: 'X', priority: '', category: 'c', channel: '' }
		await ctx.onSubmit()
		expect(JSON.parse(fetch.mock.calls[1][1].body)).toMatchObject({ priority: 'normal', category: 'c' })
	})

	it('posts nothing without a title or a ticket schema, and logs a failed post', async () => {
		vi.stubGlobal('fetch', vi.fn())
		await bind(startRequest, { config, form: { title: '' } }).onSubmit()
		await bind(startRequest, { config: {}, form: { title: 'X' } }).onSubmit()
		expect(error).toHaveBeenCalledWith('Ticket schema not configured')
		expect(fetch).not.toHaveBeenCalled()
		answer({ ok: false })
		const failed = bind(startRequest, { config, form: { title: 'X', priority: 'normal' } })
		await failed.onSubmit()
		expect(error).toHaveBeenLastCalledWith('StartRequestWidget create error:', new Error('Failed to create request'))
		const nullPriority = bind(startRequest, { config, form: { title: 'X', priority: null } })
		vi.stubGlobal('fetch', vi.fn())
		await nullPriority.onSubmit()
		expect(fetch).not.toHaveBeenCalled()
		expect(nullPriority.submitting).toBe(false)
	})

	it('reads the three latest requests', async () => {
		answer({ ok: true, body: [1, 2, 3, 4] }, { ok: false }, new Error('down'))
		const ctx = bind(startRequest, { config })
		await ctx.fetchRecentRequests()
		expect(fetch).toHaveBeenCalledWith('/index.php/apps/openregister/api/objects/r/ticket?ticketType=request&_limit=3&_order=desc', { headers })
		expect(ctx.recentRequests).toEqual([1, 2, 3])
		await ctx.fetchRecentRequests()
		expect(ctx.recentRequests).toEqual([1, 2, 3])
		await ctx.fetchRecentRequests()
		expect(error).toHaveBeenCalledWith('Failed to fetch recent requests:', new Error('down'))
		const unconfigured = bind(startRequest, { config: {} })
		vi.stubGlobal('fetch', vi.fn())
		await unconfigured.fetchRecentRequests()
		expect(fetch).not.toHaveBeenCalled()
	})
})
