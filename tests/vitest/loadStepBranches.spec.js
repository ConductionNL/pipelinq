// @vitest-environment jsdom
/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Branches of four loaders pinned before they were split into named steps:
 * the subscriptions section, the billing category widget, the contact log
 * dialog's ticket and draft, and the communication history.
 */
import { beforeAll, beforeEach, describe, expect, it, vi } from 'vitest'

const lists = vi.hoisted(() => ({ fetchContactSubscriptions: vi.fn(), fetchListSubscriptions: vi.fn() }))
const http = vi.hoisted(() => ({ get: vi.fn() }))
const dialogs = vi.hoisted(() => ({ showError: vi.fn() }))
const objects = vi.hoisted(() => ({ fetchObject: vi.fn() }))
const stores = vi.hoisted(() => ({ initializeStores: vi.fn() }))

vi.mock('@conduction/nextcloud-vue', () => ({
	CnChartWidget: {},
	CnDataTable: {},
	CnDetailCard: {},
	CnDialog: {},
	CnDurationField: {},
	CnPagination: {},
	CnStatusBadge: {},
	openRowTarget: vi.fn(),
}))
vi.mock('@nextcloud/vue', () => ({
	NcActionCheckbox: {},
	NcActions: {},
	NcButton: {},
	NcEmptyContent: {},
	NcLoadingIcon: {},
	NcNoteCard: {},
	NcSelect: {},
}))
vi.mock('@nextcloud/l10n', () => ({ getLanguage: () => 'en', translate: (app, text) => text }))
vi.mock('@nextcloud/event-bus', () => ({ emit: vi.fn() }))
vi.mock('@nextcloud/router', () => ({ generateUrl: (path) => path }))
vi.mock('@nextcloud/axios', () => ({ default: http }))
vi.mock('@nextcloud/dialogs', () => dialogs)
vi.mock('../../src/dialogs/ListSignupsManageDialog.vue', () => ({ default: {} }))
vi.mock('../../src/modals/SubscriptionManageModal.vue', () => ({ default: {} }))
vi.mock('../../src/services/mailingListApi.js', () => lists)
vi.mock('../../src/store/modules/billingCategory.js', () => ({ useBillingCategoryStore: () => ({}) }))
vi.mock('../../src/store/store.js', () => stores)
vi.mock('../../src/store/modules/object.js', () => ({ useObjectStore: () => objects }))

let subscriptions
let billing
let contactLog
let history

beforeAll(async () => {
	globalThis.t = (app, text) => text
	globalThis.OC = { requestToken: 'tok' }
	subscriptions = (await import('../../src/components/marketing/SubscriptionsSection.vue')).default
	billing = (await import('../../src/components/dashboard/BillingCategoryWidget.vue')).default
	contactLog = (await import('../../src/dialogs/ContactLogDialog.vue')).default
	history = (await import('../../src/components/CommunicationHistory.vue')).default
}, 60000)

beforeEach(() => {
	Object.values(lists).forEach((fn) => fn.mockReset())
	http.get.mockReset()
	dialogs.showError.mockReset()
	objects.fetchObject.mockReset()
	stores.initializeStores.mockReset()
})

describe('SubscriptionsSection', () => {
	/**
	 * @param {object} state The section's state.
	 * @return {object} A section context.
	 */
	function ctx(state) {
		return {
			...subscriptions.methods,
			listId: '',
			contactId: '',
			cnSectionContext: null,
			loading: false,
			error: 'old',
			rows: ['old'],
			counts: {},
			t: (app, text) => text,
			...state,
		}
	}

	it('reads an id from the host, whether it is a ref or a plain object', () => {
		const read = (cnSectionContext, key) => subscriptions.methods.contextId.call(ctx({ cnSectionContext }), key)
		expect(read(null, 'listId')).toBe('')
		expect(read({ value: { listId: 'l1', contactId: 'c1' } }, 'listId')).toBe('l1')
		expect(read({ value: { listId: 'l1', contactId: 'c1' } }, 'contactId')).toBe('c1')
		expect(read({ value: null }, 'listId')).toBe('')
		expect(read({ listId: 'l2' }, 'listId')).toBe('l2')
		expect(read({ listId: 'l2' }, 'contactId')).toBe('')
		expect(read({ listId: 'l2', contactId: 'c2' }, 'other')).toBe('c2')
		expect(read('text', 'listId')).toBe('')
	})

	it('loads a list\'s subscriptions with its own counts, or counts them', async () => {
		lists.fetchListSubscriptions.mockResolvedValue({ data: [{ state: 'subscribed' }], counts: { subscribed: 9 } })
		const c = ctx({ listId: 'l1' })
		const pending = subscriptions.methods.load.call(c)
		expect(c.loading).toBe(true)
		expect(c.error).toBe('')
		await pending
		expect(lists.fetchListSubscriptions).toHaveBeenCalledWith('l1')
		expect(c.rows).toEqual([{ state: 'subscribed' }])
		expect(c.counts).toEqual({ subscribed: 9 })
		expect(c.loading).toBe(false)

		lists.fetchListSubscriptions.mockResolvedValue({})
		const counted = ctx({ cnSectionContext: { listId: 'l2' } })
		await subscriptions.methods.load.call(counted)
		expect(counted.rows).toEqual([])
		expect(typeof counted.counts).toBe('object')
	})

	it('loads a contact\'s subscriptions, does nothing without an id, and says when it failed', async () => {
		lists.fetchContactSubscriptions.mockResolvedValue([{ state: 'subscribed' }, { state: 'unsubscribed' }])
		const c = ctx({ contactId: 'c1' })
		await subscriptions.methods.load.call(c)
		expect(lists.fetchContactSubscriptions).toHaveBeenCalledWith('c1')
		expect(c.rows).toHaveLength(2)
		expect(lists.fetchListSubscriptions).not.toHaveBeenCalled()

		const none = ctx({})
		await subscriptions.methods.load.call(none)
		expect(none.loading).toBe(false)
		expect(none.error).toBe('old')

		lists.fetchContactSubscriptions.mockRejectedValue(new Error('500'))
		const failing = ctx({ contactId: 'c1' })
		await subscriptions.methods.load.call(failing)
		expect(failing.error).toBe('The subscriptions could not be loaded.')
		expect(failing.rows).toEqual(['old'])
		expect(failing.loading).toBe(false)
	})
})

describe('BillingCategoryWidget', () => {
	it('sums the hours per category, uncategorised last seen in order', () => {
		const buckets = billing.computed.buckets.call({
			timeEntries: [
				{ billingCategory: 'dev', hours: '1.333' },
				{ hours: 2 },
				{ billingCategory: 'dev', hours: 'x' },
				{ billingCategory: 'gone', hours: 1 },
				{ billingCategory: 'dev', hours: 1 },
			],
			categoryIndex: { dev: { name: 'Development', color: '#123' }, gone: undefined },
		})
		expect(buckets).toEqual([
			{ key: 'dev', label: 'Development', hours: 2.33, color: '#123' },
			{ key: '__uncategorized__', label: 'Uncategorized', hours: 2, color: '#6c757d' },
			{ key: 'gone', label: 'Uncategorized', hours: 1, color: '#6c757d' },
		])
		expect(billing.computed.buckets.call({ timeEntries: [{ billingCategory: 'x', hours: 1 }], categoryIndex: { x: { name: 'X' } } }))
			.toEqual([{ key: 'x', label: 'X', hours: 1, color: '#6c757d' }])
	})

	/**
	 * @param {object} registry The object type registry.
	 * @return {object} A widget context.
	 */
	function ctx(registry) {
		stores.initializeStores.mockResolvedValue({ objectStore: { objectTypeRegistry: registry } })
		return {
			...billing.methods,
			loading: false,
			timeEntries: ['old'],
			billingCategoryStore: { fetchCategories: vi.fn(async () => {}) },
		}
	}

	it('loads the time entries when the type is registered', async () => {
		const fetchMock = vi.fn(async () => ({ ok: true, json: async () => ({ results: [{ id: 1 }] }) }))
		vi.stubGlobal('fetch', fetchMock)
		const c = ctx({ timeEntry: { register: 'r', schema: 's' } })
		const pending = billing.methods.load.call(c)
		expect(c.loading).toBe(true)
		await pending
		expect(c.billingCategoryStore.fetchCategories).toHaveBeenCalled()
		expect(fetchMock).toHaveBeenCalledWith('/apps/openregister/api/objects/r/s?_limit=500', {
			headers: { 'Content-Type': 'application/json', requesttoken: 'tok', 'OCS-APIREQUEST': 'true' },
		})
		expect(c.timeEntries).toEqual([{ id: 1 }])
		expect(c.loading).toBe(false)
		fetchMock.mockResolvedValue({ ok: true, json: async () => [{ id: 2 }] })
		await billing.methods.load.call(c)
		expect(c.timeEntries).toEqual([{ id: 2 }])
		fetchMock.mockResolvedValue({ ok: false })
		await billing.methods.load.call(c)
		expect(c.timeEntries).toEqual([{ id: 2 }])
		vi.unstubAllGlobals()
	})

	it('skips an unregistered type and logs a failure', async () => {
		const fetchMock = vi.fn()
		vi.stubGlobal('fetch', fetchMock)
		const c = ctx({})
		await billing.methods.load.call(c)
		expect(fetchMock).not.toHaveBeenCalled()
		expect(c.timeEntries).toEqual(['old'])
		const error = vi.spyOn(console, 'error').mockImplementation(() => {})
		const failure = new Error('down')
		const failing = ctx({ timeEntry: { register: 'r', schema: 's' } })
		fetchMock.mockRejectedValue(failure)
		await billing.methods.load.call(failing)
		expect(error).toHaveBeenCalledWith('BillingCategoryWidget load error:', failure)
		expect(failing.loading).toBe(false)
		error.mockRestore()
		vi.unstubAllGlobals()
	})
})

describe('ContactLogDialog', () => {
	/**
	 * @param {object} state The dialog's state.
	 * @return {object} A dialog context.
	 */
	function ctx(state) {
		return {
			...contactLog.methods,
			ticketId: 't1',
			ticket: null,
			client: null,
			clientResults: [{ id: 'k1', label: 'Known' }],
			form: { title: '', channel: '', direction: '', duration: '', notes: '', other: 'x' },
			...state,
		}
	}

	it('loads the ticket and names its client when none was picked', async () => {
		objects.fetchObject.mockImplementation(async (type, id) => (type === 'ticket' ? { id, client: 'c1' } : { name: 'Acme' }))
		const c = ctx({})
		await contactLog.methods.loadTicket.call(c)
		expect(c.ticket).toEqual({ id: 't1', client: 'c1' })
		expect(c.client).toEqual({ id: 'c1', label: 'Acme' })

		objects.fetchObject.mockImplementation(async (type, id) => (type === 'ticket' ? { id, client: 'c1' } : null))
		const unnamed = ctx({})
		await contactLog.methods.loadTicket.call(unnamed)
		expect(unnamed.client).toEqual({ id: 'c1', label: 'c1' })

		const picked = ctx({ client: { id: 'p' } })
		await contactLog.methods.loadTicket.call(picked)
		expect(picked.client).toEqual({ id: 'p' })
		expect(objects.fetchObject).toHaveBeenCalledTimes(5)
	})

	it('skips a ticket without a client, without an id, and drops it on a failure', async () => {
		objects.fetchObject.mockResolvedValue({ id: 't1', client: '' })
		const c = ctx({})
		await contactLog.methods.loadTicket.call(c)
		expect(c.client).toBeNull()
		objects.fetchObject.mockResolvedValue(undefined)
		await contactLog.methods.loadTicket.call(c)
		expect(c.ticket).toBeNull()
		objects.fetchObject.mockClear()
		await contactLog.methods.loadTicket.call(ctx({ ticketId: '' }))
		expect(objects.fetchObject).not.toHaveBeenCalled()
		objects.fetchObject.mockRejectedValue(new Error('x'))
		const failing = ctx({ ticket: { id: 'old' } })
		await contactLog.methods.loadTicket.call(failing)
		expect(failing.ticket).toBeNull()
	})

	it('puts a draft back, and its other client', async () => {
		const c = ctx({})
		await contactLog.methods.applyDraftForm.call(c, { title: 'T', channel: 7, notes: 'N', other: 'y', client: 'k1' })
		expect(c.form).toEqual({ title: 'T', channel: '', direction: '', duration: '', notes: 'N', other: 'x' })
		expect(c.client).toEqual({ id: 'k1', label: 'Known' })

		objects.fetchObject.mockResolvedValue({ name: 'Fetched' })
		const fetched = ctx({})
		await contactLog.methods.applyDraftForm.call(fetched, { client: 'c9' })
		expect(objects.fetchObject).toHaveBeenCalledWith('client', 'c9')
		expect(fetched.client).toEqual({ id: 'c9', label: 'Fetched' })

		objects.fetchObject.mockResolvedValue(null)
		const nameless = ctx({})
		await contactLog.methods.applyDraftForm.call(nameless, { client: 'c9' })
		expect(nameless.client).toEqual({ id: 'c9', label: 'c9' })

		objects.fetchObject.mockRejectedValue(new Error('x'))
		const failing = ctx({})
		await contactLog.methods.applyDraftForm.call(failing, { client: 'c9' })
		expect(failing.client).toEqual({ id: 'c9', label: 'c9' })
	})

	it('keeps the client when the draft names the same one or none', async () => {
		for (const client of ['k2', '', 5, undefined]) {
			const c = ctx({ client: { id: 'k2' } })
			await contactLog.methods.applyDraftForm.call(c, { client })
			expect(c.client).toEqual({ id: 'k2' })
		}
		expect(objects.fetchObject).not.toHaveBeenCalled()
	})
})

describe('CommunicationHistory.fetchHistory', () => {
	/**
	 * @param {object} state The history's state.
	 * @return {object} A history context.
	 */
	function ctx(state) {
		return {
			...history.methods,
			entityId: 'e1',
			page: 2,
			limit: 20,
			loading: false,
			items: ['old'],
			total: 9,
			pages: 9,
			buildUrl: () => '/history',
			t: (app, text) => text,
			...state,
		}
	}

	it('loads a page of contact moments', async () => {
		http.get.mockResolvedValue({ data: { results: [{ id: 1 }], total: '5', pages: '3', page: '2' } })
		const c = ctx({})
		const pending = history.methods.fetchHistory.call(c)
		expect(c.loading).toBe(true)
		await pending
		expect(http.get).toHaveBeenCalledWith('/history', { params: { type: 'contactmomenten', _page: 2, _limit: 20 } })
		expect(c).toMatchObject({ items: [{ id: 1 }], total: 5, pages: 3, page: 2, loading: false })

		http.get.mockResolvedValue({ data: { results: 'x' } })
		const odd = ctx({ page: 4 })
		await history.methods.fetchHistory.call(odd)
		expect(odd).toMatchObject({ items: [], total: 0, pages: 1, page: 4 })
		http.get.mockResolvedValue({})
		await history.methods.fetchHistory.call(odd)
		expect(odd).toMatchObject({ items: [], total: 0, pages: 1, page: 4 })
	})

	it('does nothing without an entity, and clears the list on a failure', async () => {
		const none = ctx({ entityId: '' })
		await history.methods.fetchHistory.call(none)
		expect(http.get).not.toHaveBeenCalled()
		expect(none.items).toEqual(['old'])
		http.get.mockRejectedValue(new Error('x'))
		const failing = ctx({})
		await history.methods.fetchHistory.call(failing)
		expect(dialogs.showError).toHaveBeenCalledWith('Could not load communication history')
		expect(failing).toMatchObject({ items: [], total: 0, pages: 1, page: 2, loading: false })
	})
})
