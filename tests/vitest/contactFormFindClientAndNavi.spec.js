// @vitest-environment jsdom
/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 * SPDX-License-Identifier: EUPL-1.2
 *
 * The contact form (validation, a client made on the fly), the find client
 * widget (search, quick actions, a new client) and the Navi analytics widget
 * (asking, following up and shaping chart and table answers), each run
 * against a plain component context.
 *
 * @spec openspec/specs/client-management/spec.md
 */

import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'

vi.hoisted(() => {
	globalThis.t = (app, text, vars = {}) => String(text).replace(/\{(\w+)\}/g, (m, k) => (k in vars ? String(vars[k]) : m))
})

const stub = (name) => ({ name, render: () => null })
const http = vi.hoisted(() => ({ post: vi.fn() }))
vi.mock('@nextcloud/axios', () => ({ default: http }))
vi.mock('@nextcloud/router', () => ({ generateUrl: (path) => '/index.php' + path }))
vi.mock('@nextcloud/vue', () => ({ NcButton: stub('NcButton'), NcLoadingIcon: stub('NcLoadingIcon'), NcNoteCard: stub('NcNoteCard'), NcSelect: stub('NcSelect'), NcTextField: stub('NcTextField') }))
const openRowTarget = vi.fn()
vi.mock('@conduction/nextcloud-vue', () => ({ CnChartWidget: stub('CnChartWidget'), CnDataTable: stub('CnDataTable'), CnResourceSelect: stub('CnResourceSelect'), openRowTarget: (...args) => openRowTarget(...args) }))
vi.mock('../../src/dialogs/ClientCreateDialog.vue', () => ({ default: stub('ClientCreateDialog') }))
vi.mock('../../src/store/modules/object.js', () => ({ useObjectStore: () => ({}) }))
const stores = vi.hoisted(() => ({ initializeStores: vi.fn() }))
vi.mock('../../src/store/store.js', () => stores)
vi.mock('../../src/services/objectFetch.js', () => ({ fetchRegisteredObjects: vi.fn() }))

const { default: ContactForm } = await import('../../src/views/contacts/ContactForm.vue')
const { default: FindClientWidget } = await import('../../src/views/widgets/FindClientWidget.vue')
const { default: NaviAnalyticsWidget } = await import('../../src/views/dashboard/widgets/NaviAnalyticsWidget.vue')
const { fetchRegisteredObjects } = await import('../../src/services/objectFetch.js')

/**
 * A component context: props and extras, methods bound, data, and computed
 * values as getters.
 *
 * @param {object} comp The component definition.
 * @param {object} [props] Its props.
 * @param {object} [extra] Anything else on `this`.
 * @return {object} The context.
 */
function context(comp, props = {}, extra = {}) {
	const ctx = { $emit: vi.fn(), t: globalThis.t, ...props, ...extra }
	for (const [name, fn] of Object.entries(comp.methods || {})) {
		ctx[name] = fn.bind(ctx)
	}
	Object.assign(ctx, comp.data ? comp.data.call(ctx) : {})
	for (const [name, def] of Object.entries(comp.computed || {})) {
		Object.defineProperty(ctx, name, { get: () => def.call(ctx), configurable: true })
	}
	return ctx
}

beforeEach(() => {
	http.post.mockReset()
	openRowTarget.mockReset()
	stores.initializeStores.mockReset()
	fetchRegisteredObjects.mockReset()
	globalThis.OC = { requestToken: 'tok' }
	vi.spyOn(console, 'error').mockImplementation(() => {})
})

afterEach(() => {
	vi.restoreAllMocks()
	vi.unstubAllGlobals()
	vi.useRealTimers()
})

describe('ContactForm', () => {
	const form = (props = {}) => {
		const f = context(ContactForm, { contact: {}, preSelectedClient: null, ...props })
		ContactForm.watch.contact.handler.call(f, f.contact)
		return f
	}

	it('fills from the contact, or from the preselected client', () => {
		const f = form({ contact: { id: 'p1', name: 'Ann', client: 'c1', email: 'a@b.nl' } })
		expect([f.form, f.selectedClient, f.isValid]).toEqual([{ name: 'Ann', client: 'c1', role: '', email: 'a@b.nl', phone: '' }, 'c1', true])
		const pre = form({ preSelectedClient: 'c2' })
		ContactForm.mounted.call(pre)
		expect([pre.selectedClient, pre.form.client, pre.isValid]).toEqual(['c2', 'c2', false])
		ContactForm.watch.selectedClient.call(pre, 'c3')
		expect(pre.form.client).toBe('c3')
	})

	it('names each missing or malformed field and saves only a sound contact', () => {
		const f = form()
		Object.assign(f.form, { email: 'nope', phone: '12' })
		f.onSave()
		expect([f.errors, f.$emit.mock.calls.length]).toEqual([{ name: 'Name is required', client: 'Client is required', email: 'Invalid email format', phone: 'Invalid phone format' }, 0])
		Object.assign(f.form, { name: 'Ann', email: 'ann@example.nl', phone: '+31 (0)6-1234 5678' })
		f.onClientSelected('c1')
		ContactForm.watch.selectedClient.call(f, f.selectedClient)
		f.validateField('unknown')
		f.onSave()
		expect(f.$emit.mock.calls[0]).toEqual(['save', { name: 'Ann', client: 'c1', role: '', email: 'ann@example.nl', phone: '+31 (0)6-1234 5678' }])
		const edit = form({ contact: { id: 'p1', name: 'Ann', client: 'c1' } })
		edit.onSave()
		expect(edit.$emit.mock.calls[0][1].id).toBe('p1')
		f.onClientSelected(undefined)
		expect([f.selectedClient, f.errors.client]).toEqual([null, 'Client is required'])
	})

	it('makes a client on the fly, resolving with it or with nothing', async () => {
		const f = form()
		const made = f.createClient('Acme')
		expect([f.pendingClientName, f.clientDialogOpen]).toEqual(['Acme', true])
		f.onClientCreated('c9', { name: 'Acme' })
		expect([await made, f.clientDialogOpen, f.resolveCreate]).toEqual([{ name: 'Acme', id: 'c9' }, false, null])
		const none = f.createClient()
		f.onClientCreated('', null)
		expect([await none, f.pendingClientName]).toEqual([null, ''])
		const closed = f.createClient('X')
		f.closeClientDialog()
		expect(await closed).toBeNull()
		f.closeClientDialog()
		f.onClientCreated('c1')
		expect(f.clientDialogOpen).toBe(false)
	})
})

describe('FindClientWidget', () => {
	const registry = { client: { register: 'r', schema: 'client' }, ticket: { register: 'r', schema: 'ticket' }, lead: { register: 'r', schema: 'lead' } }
	const widget = () => context(FindClientWidget, { title: 'Find' })

	it('loads the registry and the first clients', async () => {
		stores.initializeStores.mockResolvedValueOnce({ objectStore: { objectTypeRegistry: registry } })
		fetchRegisteredObjects.mockResolvedValueOnce(Array.from({ length: 25 }, (_, i) => ({ id: `c${i}` })))
		const w = widget()
		await FindClientWidget.mounted.call(w)
		expect([fetchRegisteredObjects.mock.calls[0][1], w.filteredClients.length, w.loading]).toEqual([{ type: 'client', params: { _search: '', _limit: 20 } }, 20, false])
		stores.initializeStores.mockRejectedValueOnce(new Error('x'))
		const broken = widget()
		await broken.fetchData()
		expect([broken.config, broken.loading]).toEqual([null, false])
		await broken.searchClients()
		expect(fetchRegisteredObjects).toHaveBeenCalledTimes(1)
	})

	it('searches after a pause and keeps only the latest answer', async () => {
		vi.useFakeTimers()
		const w = widget()
		w.config = registry
		let first
		fetchRegisteredObjects.mockImplementationOnce(() => new Promise((resolve) => { first = resolve })).mockResolvedValueOnce([{ id: 'new' }])
		w.searchQuery = ' acme '
		FindClientWidget.watch.searchQuery.call(w)
		FindClientWidget.watch.searchQuery.call(w)
		await vi.advanceTimersByTimeAsync(300)
		const late = w.searchClients()
		await vi.advanceTimersByTimeAsync(0)
		first([{ id: 'old' }])
		await late
		await vi.advanceTimersByTimeAsync(0)
		expect([fetchRegisteredObjects.mock.calls[0][1].params._search, w.clients]).toEqual(['acme', [{ id: 'new' }]])
		fetchRegisteredObjects.mockRejectedValueOnce(new Error('x'))
		await w.searchClients()
		expect(w.clients).toEqual([{ id: 'new' }])
		FindClientWidget.beforeUnmount.call(w)
	})

	it('opens a client, also on space, and starts a request or a lead for it', () => {
		const w = widget()
		const event = { preventDefault: vi.fn() }
		w.viewClient({ id: 'c1' }, 'e')
		w.viewClientOnSpace({ id: 'c2' }, event)
		expect([openRowTarget.mock.calls, event.preventDefault.mock.calls.length]).toEqual([[['e', '/index.php/apps/pipelinq/clients/c1'], [event, '/index.php/apps/pipelinq/clients/c2']], 1])
		w.createRequestForClient({ id: 'c1' })
		expect([w.actionType, w.actionClient]).toEqual(['request', { id: 'c1' }])
		w.actionTitle = 'x'
		w.createLeadForClient({ id: 'c2' })
		expect([w.actionType, w.actionTitle]).toEqual(['lead', ''])
		w.cancelAction()
		expect([w.actionClient, w.actionType]).toEqual([null, ''])
	})

	it('creates the request or lead and goes to it', async () => {
		vi.useFakeTimers({ now: new Date('2026-05-15T12:00:00Z'), toFake: ['Date'] })
		const fetchSpy = vi.fn(async () => ({ ok: true, json: async () => ({ id: 't1' }) }))
		vi.stubGlobal('fetch', fetchSpy)
		const location = { href: '' }
		vi.stubGlobal('location', location)
		const w = widget()
		w.config = registry
		await w.submitAction()
		expect(fetchSpy).not.toHaveBeenCalled()
		w.createRequestForClient({ id: 'c1' })
		w.actionTitle = 'Callback'
		await w.submitAction()
		expect(fetchSpy.mock.calls[0][0]).toBe('/index.php/apps/openregister/api/objects/r/ticket')
		expect(JSON.parse(fetchSpy.mock.calls[0][1].body)).toEqual({ title: 'Callback', client: 'c1', ticketType: 'request', status: 'new', priority: 'normal', occurredAt: '2026-05-15T12:00:00.000Z' })
		expect(window.location.href).toBe('/index.php/apps/pipelinq/tickets/t1')
		w.createLeadForClient({ id: 'c1' })
		w.actionTitle = 'Upsell'
		fetchSpy.mockResolvedValueOnce({ ok: true, json: async () => ({ uuid: 'l1' }) })
		await w.submitAction()
		expect([JSON.parse(fetchSpy.mock.calls[1][1].body), window.location.href]).toEqual([{ title: 'Upsell', client: 'c1', status: 'open' }, '/index.php/apps/pipelinq/leads/l1'])
		fetchSpy.mockResolvedValueOnce({ ok: false })
		await w.submitAction()
		w.config = {}
		await w.submitAction()
		expect([fetchSpy.mock.calls.length, w.actionSubmitting]).toEqual([3, false])
	})

	it('adds a new client at the top of the list', async () => {
		const fetchSpy = vi.fn(async () => ({ ok: true, json: async () => ({ id: 'c9', name: 'Acme' }) }))
		vi.stubGlobal('fetch', fetchSpy)
		const w = widget()
		w.config = registry
		w.clients = [{ id: 'c1' }]
		await w.createClient()
		expect([w.newClientSubmitted, fetchSpy.mock.calls.length]).toEqual([true, 0])
		w.showNewClientForm = true
		w.newClient = { name: 'Acme', type: { id: 'organization' }, email: 'info@acme.nl' }
		await w.createClient()
		expect(JSON.parse(fetchSpy.mock.calls[0][1].body)).toEqual({ name: 'Acme', type: 'organization', email: 'info@acme.nl' })
		expect([w.clients.map((c) => c.id), w.newClient, w.showNewClientForm, w.newClientSubmitted]).toEqual([['c9', 'c1'], { name: '', type: 'person', email: '' }, false, false])
		w.newClient = { name: 'Solo', type: '' }
		await w.createClient()
		expect(JSON.parse(fetchSpy.mock.calls[1][1].body)).toEqual({ name: 'Solo', type: 'person' })
		fetchSpy.mockResolvedValueOnce({ ok: false })
		w.newClient = { name: 'Fail', type: 'person' }
		await w.createClient()
		expect([w.clients.length, w.creatingClient, w.newClient.name]).toEqual([3, false, 'Fail'])
	})

	it('copies an email and shows that for two seconds', async () => {
		vi.useFakeTimers()
		const writeText = vi.fn(async () => {})
		vi.stubGlobal('navigator', { clipboard: { writeText } })
		const w = widget()
		await w.copyEmail({ email: 'a@b.nl' })
		expect([writeText.mock.calls[0], w.copyFeedback]).toEqual([['a@b.nl'], true])
		vi.advanceTimersByTime(2000)
		expect(w.copyFeedback).toBe(false)
		writeText.mockRejectedValueOnce(new Error('denied'))
		await w.copyEmail({ email: 'a@b.nl' })
		expect(w.copyFeedback).toBe(false)
	})
})

describe('NaviAnalyticsWidget', () => {
	const navi = () => context(NaviAnalyticsWidget)

	it('asks a question and keeps the conversation going', async () => {
		const n = navi()
		await n.submitQuery()
		expect(http.post).not.toHaveBeenCalled()
		http.post.mockResolvedValueOnce({ data: { conversationId: 'conv1', textResponse: 'Up 5%', resultType: 'chart', chartData: { type: 'line' }, suggestedFollowUps: ['Why?'] } })
		n.query = '  How are sales?  '
		await n.submitQuery()
		expect(http.post.mock.calls[0]).toEqual(['/index.php/apps/pipelinq/api/navi/query', { query: 'How are sales?', conversationId: null }])
		expect([n.query, n.conversationId, n.messages]).toEqual(['', 'conv1', [{ role: 'user', text: 'How are sales?' }, { role: 'assistant', text: 'Up 5%', resultType: 'chart', chartData: { type: 'line' }, tableData: null, suggestedFollowUps: ['Why?'] }]])
		http.post.mockResolvedValueOnce({ data: null })
		n.selectSuggestion('Why?')
		await new Promise((resolve) => setTimeout(resolve, 0))
		expect([http.post.mock.calls[1][1], n.messages[3]]).toEqual([{ query: 'Why?', conversationId: 'conv1' }, { role: 'assistant', text: '', resultType: 'text', chartData: null, tableData: null, suggestedFollowUps: [] }])
		expect(n.inputId).toMatch(/^navi-question-/)
	})

	it('says when Navi cannot answer and asks nothing while busy', async () => {
		const n = navi()
		http.post.mockRejectedValueOnce(new Error('x'))
		n.query = 'Q'
		await n.submitQuery()
		expect([n.error, n.loading]).toEqual(['Navi could not answer that question. Please try again.', false])
		n.loading = true
		n.query = 'Again'
		await n.submitQuery()
		expect(http.post).toHaveBeenCalledTimes(1)
	})

	it('shapes chart and table answers for the widgets', () => {
		const n = navi()
		expect([n.resolveChartType({ type: 'pie' }), n.resolveChartType({ type: 'radar' }), n.resolveChartType(null)]).toEqual(['pie', 'bar', 'bar'])
		expect([n.resolveSeries({ series: [[1, 2], { data: [3] }, {}] }), n.resolveSeries({})]).toEqual([[[1, 2], [3], []], []])
		expect([n.resolveTableColumns({ columns: ['Name', 'Won'] }), n.resolveTableColumns(null)]).toEqual([[{ key: 'col0', label: 'Name' }, { key: 'col1', label: 'Won' }], []])
		expect([n.resolveTableRows({ rows: [['Ann', 3], ['Bob', 1]] }), n.resolveTableRows({})]).toEqual([[{ id: 0, col0: 'Ann', col1: 3 }, { id: 1, col0: 'Bob', col1: 1 }], []])
	})
})
