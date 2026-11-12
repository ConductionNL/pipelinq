// @vitest-environment jsdom
/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 * SPDX-License-Identifier: EUPL-1.2
 *
 * The client autocomplete (debounced search through the client schema,
 * picking and clearing) and the social publications section (loading,
 * retrying, the prepared share text and confirming a manual share), each run
 * against a plain component context.
 *
 * @spec openspec/specs/social-publishing/spec.md
 */

import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'

vi.hoisted(() => {
	globalThis.t = (app, text, vars = {}) => String(text).replace(/\{(\w+)\}/g, (m, k) => (k in vars ? String(vars[k]) : m))
})

const stub = (name) => ({ name, render: () => null })
const bus = vi.hoisted(() => ({ subscribed: [], unsubscribed: [] }))
vi.mock('@nextcloud/router', () => ({ generateUrl: (path) => '/index.php' + path }))
vi.mock('@nextcloud/event-bus', () => ({ subscribe: (name) => bus.subscribed.push(name), unsubscribe: (name) => bus.unsubscribed.push(name) }))
vi.mock('@nextcloud/vue', () => ({ NcButton: stub('NcButton'), NcLoadingIcon: stub('NcLoadingIcon'), NcNoteCard: stub('NcNoteCard'), NcTextField: stub('NcTextField') }))
const stores = vi.hoisted(() => ({ initializeStores: vi.fn() }))
vi.mock('../../src/store/store.js', () => stores)
const socialApi = vi.hoisted(() => ({ confirmShare: vi.fn(), fetchPublications: vi.fn(), fetchShare: vi.fn(), retryPublication: vi.fn() }))
vi.mock('../../src/services/socialApi.js', () => socialApi)

const { default: ClientAutocomplete } = await import('../../src/components/widgets/ClientAutocomplete.vue')
const { default: SocialPublicationsSection } = await import('../../src/components/marketing/SocialPublicationsSection.vue')

/**
 * A component context: props and extras, methods (mixins first) bound,
 * data, and computed values as getters.
 *
 * @param {object} comp The component definition.
 * @param {object} [props] Its props.
 * @param {object} [extra] Anything else on `this`.
 * @return {object} The context.
 */
function context(comp, props = {}, extra = {}) {
	const ctx = { $emit: vi.fn(), ...props, ...extra }
	const methods = Object.assign({}, ...(comp.mixins || []).map((m) => m.methods || {}), comp.methods || {})
	for (const [name, fn] of Object.entries(methods)) {
		ctx[name] = fn.bind(ctx)
	}
	Object.assign(ctx, comp.data ? comp.data.call(ctx) : {})
	return ctx
}

beforeEach(() => {
	bus.subscribed.length = 0
	bus.unsubscribed.length = 0
	stores.initializeStores.mockReset()
	for (const fn of Object.values(socialApi)) {
		fn.mockReset()
	}
	globalThis.OC = { requestToken: 'tok' }
	vi.spyOn(console, 'error').mockImplementation(() => {})
})

afterEach(() => {
	vi.restoreAllMocks()
	vi.unstubAllGlobals()
	vi.useRealTimers()
})

describe('ClientAutocomplete', () => {
	const registry = { client: { register: 'r', schema: 'client' } }

	it('reads the client schema from the stores, and survives a failure', async () => {
		stores.initializeStores.mockResolvedValueOnce({ objectStore: { objectTypeRegistry: registry } })
		const c = context(ClientAutocomplete, { value: { id: 'c0' } })
		await ClientAutocomplete.mounted.call(c)
		expect([c.config, c.selectedClient]).toEqual([registry, { id: 'c0' }])
		stores.initializeStores.mockRejectedValueOnce(new Error('x'))
		const d = context(ClientAutocomplete, { value: null })
		await ClientAutocomplete.mounted.call(d)
		expect(d.config).toBeNull()
		ClientAutocomplete.watch.value.call(d, { id: 'c9' })
		expect(d.selectedClient).toEqual({ id: 'c9' })
		expect([ClientAutocomplete.props.placeholder.default(), ClientAutocomplete.props.label.default()]).toEqual(['Search client…', 'Client'])
	})

	it('searches after a pause, only with a schema, two characters and no client picked', async () => {
		vi.useFakeTimers()
		const fetchSpy = vi.fn(async () => ({ ok: true, json: async () => ({ results: [{ id: 'c1' }] }) }))
		vi.stubGlobal('fetch', fetchSpy)
		const c = context(ClientAutocomplete, { value: null })
		c.config = registry
		c.query = 'ac me'
		c.onInput()
		c.onInput()
		await vi.advanceTimersByTimeAsync(300)
		expect(fetchSpy.mock.calls).toEqual([['/index.php/apps/openregister/api/objects/r/client?_search=ac+me&_limit=10', { headers: { 'Content-Type': 'application/json', requesttoken: 'tok', 'OCS-APIREQUEST': 'true' } }]])
		expect([c.results, c.showDropdown]).toEqual([[{ id: 'c1' }], true])
		c.query = 'a'
		await c.searchClients()
		expect([c.results, c.showDropdown]).toEqual([[], false])
		c.config = {}
		c.query = 'acme'
		await c.searchClients()
		c.selectedClient = { id: 'c1' }
		c.onInput()
		await vi.advanceTimersByTimeAsync(300)
		expect(fetchSpy).toHaveBeenCalledTimes(1)
	})

	it('shows no dropdown for an empty or failed search', async () => {
		const c = context(ClientAutocomplete, { value: null })
		c.config = registry
		c.query = 'acme'
		vi.stubGlobal('fetch', vi.fn(async () => ({ ok: true, json: async () => [] })))
		await c.searchClients()
		expect([c.results, c.showDropdown]).toEqual([[], false])
		vi.stubGlobal('fetch', vi.fn(async () => ({ ok: false })))
		c.results = [{ id: 'stale' }]
		await c.searchClients()
		expect([c.results, c.showDropdown]).toEqual([[], false])
	})

	it('picks and clears a client, telling the parent', () => {
		const c = context(ClientAutocomplete, { value: null })
		Object.assign(c, { query: 'ac', results: [{ id: 'c1' }], showDropdown: true })
		c.selectClient({ id: 'c1' })
		expect([c.selectedClient, c.query, c.results, c.showDropdown]).toEqual([{ id: 'c1' }, '', [], false])
		c.query = 'x'
		c.clearSelection()
		expect([c.selectedClient, c.query, c.$emit.mock.calls]).toEqual([null, '', [['input', { id: 'c1' }], ['input', null]]])
	})
})

describe('SocialPublicationsSection', () => {
	const section = (props = {}) => context(SocialPublicationsSection, { postId: '', cnSectionContext: null, ...props })

	it('loads the publications of the post on the page and follows page refreshes', async () => {
		socialApi.fetchPublications.mockResolvedValue([{ id: 'u1', status: 'published' }])
		const s = section({ cnSectionContext: { id: 'p1' } })
		SocialPublicationsSection.mixins[0].mounted.call(s)
		SocialPublicationsSection.mixins[0].beforeUnmount.call(s)
		await new Promise((resolve) => setTimeout(resolve, 0))
		expect([socialApi.fetchPublications.mock.calls[0], s.rows, s.loaded, bus.subscribed, bus.unsubscribed]).toEqual([['p1'], [{ id: 'u1', status: 'published' }], true, ['cn:page:refresh'], ['cn:page:refresh']])
		expect([section({ postId: 'own', cnSectionContext: { objectId: 'x' } }).effectiveId(), section({ cnSectionContext: { objectId: 'o' } }).effectiveId()]).toEqual(['own', 'o'])
		await section().load()
		expect(socialApi.fetchPublications).toHaveBeenCalledTimes(1)
		socialApi.fetchPublications.mockRejectedValueOnce(new Error('x'))
		await s.load()
		expect([s.error, s.loading, s.loaded]).toEqual(['The publications could not be loaded.', false, true])
	})

	it('describes a publication and offers a retry only for a recoverable failure', () => {
		const s = section()
		expect([s.rowId({ uuid: 'u' }), s.rowId(null), s.networkLabel('mastodon')]).toEqual(['u', '', 'Mastodon'])
		expect([s.retryable({ status: 'failed', failureCode: 'unavailable' }), s.retryable({ status: 'failed', failureCode: 'auth_revoked' }), s.retryable({ status: 'published', failureCode: 'unavailable' })]).toEqual([true, false, false])
		expect(typeof s.chip('failed')).toBe('object')
	})

	it('retries a publication and reloads, or says why not', async () => {
		const s = section({ postId: 'p1' })
		socialApi.fetchPublications.mockResolvedValue([])
		await s.retry({ id: 'u1' })
		expect([socialApi.retryPublication.mock.calls[0], socialApi.fetchPublications.mock.calls.length, s.busy]).toEqual([['u1'], 1, ''])
		socialApi.retryPublication.mockRejectedValueOnce({ response: { data: { error: 'Still offline' } } })
		await s.retry({ id: 'u1' })
		socialApi.retryPublication.mockRejectedValueOnce({})
		const t2 = section({ postId: 'p1' })
		await t2.retry({ id: 'u1' })
		expect([s.error, t2.error]).toEqual(['Still offline', 'The retry did not work.'])
	})

	it('prepares, copies and confirms a manual share', async () => {
		const s = section({ postId: 'p1' })
		socialApi.fetchShare.mockResolvedValueOnce({ publicationId: 'u1', body: 'Copy me' })
		await s.openShare({ id: 'u1' })
		expect([socialApi.fetchShare.mock.calls[0], s.share.body, s.busy]).toEqual([['u1'], 'Copy me', ''])
		const writeText = vi.fn(async () => {})
		vi.stubGlobal('navigator', { clipboard: { writeText } })
		await s.copyPrepared()
		expect(writeText).toHaveBeenCalledWith('Copy me')
		writeText.mockRejectedValueOnce(new Error('denied'))
		await s.copyPrepared()
		expect(s.error).toBe('The text could not be copied. Select it and copy it by hand.')
		socialApi.fetchPublications.mockResolvedValue([])
		await s.confirm()
		expect([socialApi.confirmShare.mock.calls[0], s.share, s.error]).toEqual([['u1'], null, ''])
	})

	it('says when the share text cannot be loaded or the share recorded', async () => {
		const s = section({ postId: 'p1' })
		socialApi.fetchShare.mockRejectedValueOnce({ response: { data: { error: 'No text' } } })
		await s.openShare({ id: 'u1' })
		expect(s.error).toBe('No text')
		socialApi.fetchShare.mockRejectedValueOnce({})
		await s.openShare({ id: 'u1' })
		expect(s.error).toBe('The prepared text could not be loaded.')
		socialApi.confirmShare.mockRejectedValueOnce({ response: { data: { error: 'Already shared' } } })
		await s.confirm()
		expect([socialApi.confirmShare.mock.calls[0], s.error]).toEqual([[''], 'Already shared'])
		socialApi.confirmShare.mockRejectedValueOnce({})
		await s.confirm()
		expect(s.error).toBe('The share could not be recorded.')
	})
})
