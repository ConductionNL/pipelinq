// @vitest-environment jsdom
/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 * SPDX-License-Identifier: EUPL-1.2
 *
 * The new request dialog's client lookups on the form: the open tickets and
 * the known mark on the found clients, whether the BRP pill is offered, and
 * the client a BRP or KVK lookup puts on the ticket.
 *
 * @spec openspec/changes/request-dialog-client-lookups/specs/request-management/spec.md#requirement-the-new-request-dialog-finds-the-client-or-looks-them-up
 */
import { beforeAll, beforeEach, describe, expect, it, vi } from 'vitest'

const api = { createLookedUpClient: vi.fn(), fetchBrpAccess: vi.fn(), lookupFailed: vi.fn() }

vi.mock('@conduction/nextcloud-vue', () => ({ CnResourceSelect: {}, CnSearchSelect: {} }))
vi.mock('@nextcloud/auth', () => ({ getCurrentUser: () => ({ uid: 'ann', displayName: 'Ann' }) }))
vi.mock('@nextcloud/vue', () => ({
	NcButton: {},
	NcCheckboxRadioSwitch: {},
	NcDateTimePickerNative: {},
	NcSelect: {},
	NcTextArea: {},
	NcTextField: {},
}))
vi.mock('../../src/dialogs/ClientCreateDialog.vue', () => ({ default: {} }))
vi.mock('../../src/dialogs/ContactCreateDialog.vue', () => ({ default: {} }))
vi.mock('../../src/dialogs/ClientBrpLookupDialog.vue', () => ({ default: {} }))
vi.mock('../../src/dialogs/ClientKvkLookupDialog.vue', () => ({ default: {} }))
vi.mock('../../src/services/clientLookupsApi.js', () => api)
vi.mock('../../src/store/modules/object.js', () => ({ useObjectStore: () => ({}) }))
vi.mock('../../src/store/modules/requestChannels.js', () => ({ useRequestChannelsStore: () => ({}) }))
vi.mock('../../src/services/ticketCategories.js', () => ({ categoryOptions: () => [], fetchTicketCategories: async () => [] }))

let form

beforeAll(async () => {
	const fill = (text, vars) => text.replace(/\{(\w+)\}/g, (m, k) => (vars && k in vars ? vars[k] : m))
	globalThis.t = (app, text, vars) => fill(text, vars)
	globalThis.n = (app, one, many, count, vars) => fill(count === 1 ? one : many, vars)
	form = (await import('../../src/views/requests/RequestForm.vue')).default
}, 60000)

beforeEach(() => {
	vi.clearAllMocks()
})

/**
 * A bare form context: its data and its methods bound to it.
 *
 * @param {object} extra State to set over the defaults
 * @return {object} The context
 */
function context(extra = {}) {
	const ctx = {
		...form.data.call({}),
		request: null,
		boardLayout: true,
		$emit: vi.fn(),
		objectStore: { fetchCollectionForOptions: vi.fn(), fetchObject: vi.fn(), collections: {} },
		onClientChange: vi.fn(),
		...extra,
	}
	for (const [name, method] of Object.entries(form.methods)) {
		ctx[name] = typeof method === 'function' ? method.bind(ctx) : method
	}
	return ctx
}

describe('the found clients', () => {
	it('name their open tickets and are marked known', async () => {
		const ctx = context()
		const sanne = { id: 'c-1', name: 'Sanne de Vries', type: 'person', address: 'Lindelaan 14' }
		const oost = { id: 'c-2', name: 'Buurtvereniging Oost', type: 'organization' }
		ctx.objectStore.fetchCollectionForOptions.mockImplementation(async (type) => (type === 'client'
			? [sanne, oost]
			: [{ client: 'c-1', status: 'new' }, { client: 'c-1', status: 'completed' }, { client: 'c-2', status: 'rejected' }]))

		await ctx.onClientSearch('de Vries')

		expect(ctx.objectStore.fetchCollectionForOptions).toHaveBeenNthCalledWith(2, 'ticket', { client: ['c-1', 'c-2'], _limit: 200 })
		expect(ctx.clientOptions).toEqual([
			{ id: 'c-1', label: 'Sanne de Vries', secondary: 'Resident · Lindelaan 14 · 1 open ticket · Known', avatar: true },
			{ id: 'c-2', label: 'Buurtvereniging Oost', secondary: 'Organisation · Known', avatar: true },
		])
	})

	it('still list when the tickets cannot be counted', async () => {
		const ctx = context()
		ctx.objectStore.fetchCollectionForOptions.mockImplementation(async (type) => {
			if (type === 'ticket') throw new Error('down')
			return [{ id: 'c-1', name: 'Acme', type: 'organization' }]
		})

		await ctx.onClientSearch('acme')

		expect(ctx.clientOptions).toEqual([{ id: 'c-1', label: 'Acme', secondary: 'Organisation · Known', avatar: true }])
	})

	it('name several open tickets in the plural', () => {
		expect(context().clientRow({ id: 'c-1', name: 'Acme', type: 'organization' }, 3).secondary).toBe('Organisation · 3 open tickets · Known')
	})
})

describe('the BRP pill', () => {
	it('shows only when the server allows the session user', async () => {
		const ctx = context()
		api.fetchBrpAccess.mockResolvedValueOnce(true)
		await ctx.loadBrpAccess()
		expect(ctx.brpAllowed).toBe(true)

		api.fetchBrpAccess.mockResolvedValueOnce(false)
		await ctx.loadBrpAccess()
		expect(ctx.brpAllowed).toBe(false)
	})
})

describe('a looked-up client', () => {
	const person = { givenNames: 'Sanne', namePrefix: 'de', surname: 'Vries', residence: { straat: 'Lindelaan', huisnummer: 14, woonplaats: 'Dorp' } }

	it('from the BRP selects the existing resident with that name and address', async () => {
		const ctx = context({ brpDialogOpen: true })
		const existing = { id: 'c-7', name: 'Sanne de Vries', type: 'person', address: 'Lindelaan 14, Dorp' }
		ctx.objectStore.fetchCollectionForOptions.mockResolvedValue([existing])

		await ctx.onBrpPersonFound(person)

		expect(ctx.brpDialogOpen).toBe(false)
		expect(ctx.objectStore.fetchCollectionForOptions).toHaveBeenCalledWith('client', { _search: 'Sanne de Vries', type: 'person', _limit: 20 })
		expect(api.createLookedUpClient).not.toHaveBeenCalled()
		expect(ctx.onClientChange).toHaveBeenCalledWith('c-7')
		expect(ctx.clientOptions[0].id).toBe('c-7')
	})

	it('from the BRP creates a resident when none matches, with name and address only', async () => {
		const ctx = context()
		ctx.objectStore.fetchCollectionForOptions.mockResolvedValue([])
		api.createLookedUpClient.mockResolvedValue({ id: 'c-8', name: 'Sanne de Vries', type: 'person' })

		await ctx.onBrpPersonFound({ ...person, bsnHash: 'h' })

		expect(api.createLookedUpClient).toHaveBeenCalledWith({ name: 'Sanne de Vries', type: 'person', address: 'Lindelaan 14, Dorp' })
		expect(ctx.onClientChange).toHaveBeenCalledWith('c-8')
		expect(ctx.lookupSaving).toBe(false)
	})

	it('from the KVK selects the client with that KVK number, else creates one', async () => {
		const company = { kvkNummer: '12345678', naam: 'Bakkerij De Graaf', rechtsvorm: 'Stichting', adres: { plaats: 'Utrecht' } }
		const ctx = context({ kvkDialogOpen: true })
		ctx.objectStore.fetchCollectionForOptions.mockResolvedValueOnce([{ id: 'c-3', name: 'De Graaf', type: 'organization', kvkNumber: '12345678' }])

		await ctx.onKvkCompanyPicked(company)

		expect(ctx.kvkDialogOpen).toBe(false)
		expect(ctx.objectStore.fetchCollectionForOptions).toHaveBeenCalledWith('client', { kvkNumber: '12345678', _limit: 20 })
		expect(ctx.onClientChange).toHaveBeenLastCalledWith('c-3')

		ctx.objectStore.fetchCollectionForOptions.mockResolvedValueOnce([])
		api.createLookedUpClient.mockResolvedValueOnce({ id: 'c-4' })
		await ctx.onKvkCompanyPicked(company)

		expect(api.createLookedUpClient).toHaveBeenCalledWith({ name: 'Bakkerij De Graaf', type: 'organization', kvkNumber: '12345678', legalForm: 'stichting', place: 'Utrecht' })
		expect(ctx.onClientChange).toHaveBeenLastCalledWith('c-4')
	})

	it('that cannot be created says so and puts nothing on the ticket', async () => {
		const ctx = context()
		ctx.objectStore.fetchCollectionForOptions.mockResolvedValue([])
		api.createLookedUpClient.mockResolvedValue(null)

		await ctx.onKvkCompanyPicked({ kvkNummer: '12345678', naam: 'Bakkerij De Graaf' })

		expect(api.lookupFailed).toHaveBeenCalledWith('Failed to create client.')
		expect(ctx.onClientChange).not.toHaveBeenCalled()
	})

	it('without a name is refused before anything is searched', async () => {
		const ctx = context()
		await ctx.onBrpPersonFound({})
		expect(api.lookupFailed).toHaveBeenCalledWith('The lookup gave no name to make a client from.')
		expect(ctx.objectStore.fetchCollectionForOptions).not.toHaveBeenCalled()
	})
})
