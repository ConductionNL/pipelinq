// @vitest-environment jsdom
/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 * SPDX-License-Identifier: EUPL-1.2
 *
 * The find client widget's actions: start a request or a lead for a client
 * and open it, and create a client from the widget.
 *
 * @spec openspec/changes/reverse-2026-05-26-fe-widgets-ui/tasks.md#task-41
 */
import { afterEach, beforeAll, beforeEach, describe, expect, it, vi } from 'vitest'

vi.mock('@nextcloud/router', () => ({ generateUrl: (p) => '/index.php' + p }))
vi.mock('@nextcloud/vue', () => ({ NcButton: {}, NcLoadingIcon: {}, NcNoteCard: {}, NcSelect: {}, NcTextField: {} }))
vi.mock('@nextcloud/dialogs', () => ({ showError: vi.fn(), showSuccess: vi.fn() }))
vi.mock('@conduction/nextcloud-vue', () => ({ openRowTarget: vi.fn() }))
vi.mock('../../src/store/store.js', () => ({ initializeStores: vi.fn() }))

let widget
let error

beforeAll(async () => {
	globalThis.t = (app, text) => text
	globalThis.OC = { requestToken: 'tok' }
	widget = (await import('../../src/views/widgets/FindClientWidget.vue')).default
}, 60000)

beforeEach(() => {
	error = vi.spyOn(console, 'error').mockImplementation(() => {})
	delete window.location
	window.location = { href: '' }
})

afterEach(() => {
	vi.unstubAllGlobals()
	vi.restoreAllMocks()
	vi.useRealTimers()
})

function bind(state) {
	const ctx = { ...widget.data.call({}), config: { ticket: { register: 'r', schema: 'ticket' }, lead: { register: 'r', schema: 'lead' }, client: { register: 'r', schema: 'client' } }, ...state }
	for (const [name, fn] of Object.entries(widget.methods)) {
		if (!(name in ctx)) {
			ctx[name] = fn.bind(ctx)
		}
	}
	return ctx
}

function answer(ok, body) {
	vi.stubGlobal('fetch', vi.fn(async () => ({ ok, json: async () => body })))
}

const init = (body) => ({ method: 'POST', headers: { 'Content-Type': 'application/json', requesttoken: 'tok', 'OCS-APIREQUEST': 'true' }, body: JSON.stringify(body) })

describe('FindClientWidget.submitAction', () => {
	it('starts a request ticket now for the client and opens it', async () => {
		vi.useFakeTimers()
		vi.setSystemTime(new Date('2026-11-10T09:00:00Z'))
		answer(true, { uuid: 'u1' })
		const ctx = bind({ actionType: 'request', actionTitle: 'Pothole', actionClient: { id: 'c1' } })
		await ctx.submitAction()
		expect(fetch).toHaveBeenCalledWith('/index.php/apps/openregister/api/objects/r/ticket', init({ title: 'Pothole', client: 'c1', ticketType: 'request', status: 'new', priority: 'normal', occurredAt: '2026-11-10T09:00:00.000Z' }))
		expect(window.location.href).toBe('/index.php/apps/pipelinq/tickets/u1')
		expect(ctx.actionSubmitting).toBe(false)
	})

	it('starts an open lead and opens it', async () => {
		answer(true, { id: 'l1', uuid: 'u' })
		const ctx = bind({ actionType: 'lead', actionTitle: 'Ovens', actionClient: { id: 'c1' } })
		await ctx.submitAction()
		expect(fetch).toHaveBeenCalledWith('/index.php/apps/openregister/api/objects/r/lead', init({ title: 'Ovens', client: 'c1', status: 'open' }))
		expect(window.location.href).toBe('/index.php/apps/pipelinq/leads/l1')
	})

	it('starts nothing without a title or a client, and logs a missing schema or a refusal', async () => {
		vi.stubGlobal('fetch', vi.fn())
		await bind({ actionType: 'lead', actionTitle: '', actionClient: { id: 'c' } }).submitAction()
		await bind({ actionType: 'lead', actionTitle: 'x', actionClient: null }).submitAction()
		expect(fetch).not.toHaveBeenCalled()
		const unconfigured = bind({ actionType: 'lead', actionTitle: 'x', actionClient: { id: 'c' }, config: {} })
		await unconfigured.submitAction()
		expect(error).toHaveBeenLastCalledWith('Action submit error:', new Error('Schema not configured for lead'))
		answer(false, {})
		const refused = bind({ actionType: 'request', actionTitle: 'x', actionClient: { id: 'c' } })
		await refused.submitAction()
		expect(error).toHaveBeenLastCalledWith('Action submit error:', new Error('Failed to create request'))
		expect([refused.actionSubmitting, window.location.href]).toEqual([false, ''])
	})
})

describe('FindClientWidget.createClient', () => {
	it('creates the client, puts it first and resets the form', async () => {
		answer(true, { id: 'c9', name: 'Anna' })
		const ctx = bind({ clients: [{ id: 'c1' }], newClient: { name: 'Anna', type: { id: 'organization' }, email: 'a@b.nl' }, showNewClientForm: true })
		await ctx.createClient()
		expect(fetch).toHaveBeenCalledWith('/index.php/apps/openregister/api/objects/r/client', init({ name: 'Anna', type: 'organization', email: 'a@b.nl' }))
		expect(ctx.clients).toEqual([{ id: 'c9', name: 'Anna' }, { id: 'c1' }])
		expect([ctx.newClient, ctx.newClientSubmitted, ctx.showNewClientForm, ctx.creatingClient]).toEqual([{ name: '', type: 'person', email: '' }, false, false, false])
	})

	it('types a client as a person by default, and logs a refusal or a missing schema', async () => {
		answer(false, {})
		const ctx = bind({ newClient: { name: 'Bob', type: '', email: '' } })
		await ctx.createClient()
		expect(JSON.parse(fetch.mock.calls[0][1].body)).toEqual({ name: 'Bob', type: 'person' })
		expect(error).toHaveBeenLastCalledWith('Create client error:', new Error('Failed to create client'))
		expect([ctx.newClientSubmitted, ctx.creatingClient]).toEqual([true, false])
		const unconfigured = bind({ newClient: { name: 'Bob', type: 'person' }, config: {} })
		await unconfigured.createClient()
		expect(error).toHaveBeenLastCalledWith('Create client error:', new Error('Client schema not configured'))
		vi.stubGlobal('fetch', vi.fn())
		const unnamed = bind({ newClient: { name: '' } })
		await unnamed.createClient()
		expect(fetch).not.toHaveBeenCalled()
		expect(unnamed.newClientSubmitted).toBe(true)
	})
})
