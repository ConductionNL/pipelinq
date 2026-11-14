// @vitest-environment jsdom
/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 * SPDX-License-Identifier: EUPL-1.2
 *
 * The request form's own actions: searching and showing the client, creating
 * one inline, the defaults of a new request (linked client, board type and
 * assignee, default pipeline) and what a save hands to its host.
 *
 * @spec exclude Client-side form behaviour.
 */
import { beforeAll, beforeEach, describe, expect, it, vi } from 'vitest'

const currentUser = { value: { uid: 'ann', displayName: 'Ann' } }

vi.mock('@conduction/nextcloud-vue', () => ({ CnResourceSelect: {}, CnSearchSelect: {} }))
vi.mock('@nextcloud/auth', () => ({ getCurrentUser: () => currentUser.value }))
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
vi.mock('../../src/store/modules/object.js', () => ({ useObjectStore: () => ({}) }))
vi.mock('../../src/store/modules/requestChannels.js', () => ({ useRequestChannelsStore: () => ({}) }))
vi.mock('../../src/services/ticketCategories.js', () => ({ categoryOptions: () => [], fetchTicketCategories: async () => [] }))

let form

beforeAll(async () => {
	globalThis.t = (app, text, vars) => text.replace(/\{(\w+)\}/g, (m, k) => (vars && k in vars ? vars[k] : m))
	form = (await import('../../src/views/requests/RequestForm.vue')).default
}, 60000)

beforeEach(() => {
	currentUser.value = { uid: 'ann', displayName: 'Ann' }
})

/**
 * A bare form context: its data, the computed values it reads as getters and
 * its methods bound to it.
 *
 * @param {object} extra State to set over the defaults
 * @return {object} The context
 */
function context(extra = {}) {
	const ctx = {
		...form.data.call({}),
		request: null,
		preLinkedClient: null,
		boardLayout: false,
		$emit: vi.fn(),
		objectStore: { fetchCollectionForOptions: vi.fn(), fetchObject: vi.fn(), collections: {} },
		onClientChange: vi.fn(),
		createClient: vi.fn(),
		placeOnFirstOpenStage: vi.fn(),
		...extra,
	}
	for (const name of ['errors', 'isValid', 'pipelines', 'requestPipelines']) {
		const getter = form.computed[name] ?? form.mixins.map((m) => m.computed?.[name]).find(Boolean)
		if (getter) Object.defineProperty(ctx, name, { get: getter.bind(ctx), configurable: true })
	}
	for (const [name, method] of Object.entries(form.methods)) {
		ctx[name] = typeof method === 'function' ? method.bind(ctx) : method
	}
	return ctx
}

describe('RequestForm.clientRow', () => {
	it('labels an organisation and a resident, with the address after the kind', () => {
		const ctx = context()
		expect(ctx.clientRow({ id: 'c-1', name: 'Acme', type: 'organization', address: 'Street 1' })).toEqual({
			id: 'c-1', label: 'Acme', secondary: 'Organisation · Street 1', avatar: true,
		})
		expect(ctx.clientRow({ id: 'c-2', type: 'person' })).toEqual({ id: 'c-2', label: '', secondary: 'Resident', avatar: true })
	})
})

describe('RequestForm.onClientSearch', () => {
	it('searches by the trimmed term and lists the clients found', async () => {
		const ctx = context()
		ctx.objectStore.fetchCollectionForOptions.mockResolvedValue([{ id: 'c-1', name: 'Acme', type: 'organization' }])
		const pending = ctx.onClientSearch('  acm ')
		expect(ctx.clientSearching).toBe(true)
		await pending
		expect(ctx.objectStore.fetchCollectionForOptions).toHaveBeenCalledWith('client', { _search: 'acm', _limit: 20 })
		expect(ctx.clientOptions).toEqual([{ id: 'c-1', label: 'Acme', secondary: 'Organisation', avatar: true }])
		expect(ctx.clientSearching).toBe(false)
	})

	it('keeps only the picked client for an empty term', async () => {
		const ctx = context({ clientOptions: [{ id: 'c-1' }, { id: 'c-2' }] })
		ctx.form.client = 'c-2'
		await ctx.onClientSearch('   ')
		expect(ctx.clientOptions).toEqual([{ id: 'c-2' }])
		expect(ctx.objectStore.fetchCollectionForOptions).not.toHaveBeenCalled()
	})

	it('lists nothing for a non-list answer or a failure', async () => {
		const ctx = context({ clientOptions: [{ id: 'old' }] })
		ctx.objectStore.fetchCollectionForOptions.mockResolvedValueOnce(null)
		await ctx.onClientSearch('a')
		expect(ctx.clientOptions).toEqual([])
		ctx.clientOptions = [{ id: 'old' }]
		ctx.objectStore.fetchCollectionForOptions.mockRejectedValueOnce(new Error('down'))
		await ctx.onClientSearch('a')
		expect(ctx.clientOptions).toEqual([])
		expect(ctx.clientSearching).toBe(false)
	})

	it('ignores the answer of a search a newer one overtook', async () => {
		const ctx = context()
		let first
		ctx.objectStore.fetchCollectionForOptions
			.mockReturnValueOnce(new Promise((resolve) => { first = resolve }))
			.mockResolvedValueOnce([{ id: 'new', name: 'Newer' }])
		const older = ctx.onClientSearch('ol')
		await ctx.onClientSearch('newer')
		first([{ id: 'old', name: 'Older' }])
		await older
		expect(ctx.clientOptions.map((o) => o.id)).toEqual(['new'])
		expect(ctx.clientSearching).toBe(false)
	})
})

describe('RequestForm.loadPickedClient', () => {
	it('fetches the picked client and puts it first', async () => {
		const ctx = context({ clientOptions: [{ id: 'other' }] })
		ctx.form.client = 'c-1'
		ctx.objectStore.fetchObject.mockResolvedValue({ id: 'c-1', name: 'Acme' })
		await ctx.loadPickedClient()
		expect(ctx.objectStore.fetchObject).toHaveBeenCalledWith('client', 'c-1')
		expect(ctx.clientOptions.map((o) => o.id)).toEqual(['c-1', 'other'])
	})

	it('fetches nothing without a client or for one already listed', async () => {
		const ctx = context()
		await ctx.loadPickedClient()
		ctx.form.client = 'c-1'
		ctx.clientOptions = [{ id: 'c-1' }]
		await ctx.loadPickedClient()
		expect(ctx.objectStore.fetchObject).not.toHaveBeenCalled()
	})

	it('drops a client picked away from while it loaded, and survives a failure', async () => {
		const ctx = context()
		ctx.form.client = 'c-1'
		ctx.objectStore.fetchObject.mockImplementationOnce(async () => {
			ctx.form.client = 'c-2'
			return { id: 'c-1', name: 'Acme' }
		})
		await ctx.loadPickedClient()
		expect(ctx.clientOptions).toEqual([])
		ctx.objectStore.fetchObject.mockRejectedValueOnce(new Error('gone'))
		await expect(ctx.loadPickedClient()).resolves.toBeUndefined()
		expect(ctx.clientOptions).toEqual([])
	})
})

describe('RequestForm.newClient', () => {
	it('lists the created client once, first, and picks it', async () => {
		const ctx = context({ clientOptions: [{ id: 'c-1' }, { id: 'c-0' }] })
		ctx.createClient.mockResolvedValue({ id: 'c-1', name: 'Acme', type: 'organization' })
		await ctx.newClient()
		expect(ctx.createClient).toHaveBeenCalledWith('')
		expect(ctx.clientOptions).toEqual([
			{ id: 'c-1', label: 'Acme', secondary: 'Organisation', avatar: true },
			{ id: 'c-0' },
		])
		expect(ctx.onClientChange).toHaveBeenCalledWith('c-1')
	})

	it('changes nothing when the dialog is cancelled', async () => {
		const ctx = context({ clientOptions: [{ id: 'c-0' }] })
		ctx.createClient.mockResolvedValue(null)
		await ctx.newClient()
		expect(ctx.clientOptions).toEqual([{ id: 'c-0' }])
		expect(ctx.onClientChange).not.toHaveBeenCalled()
	})
})

describe('RequestForm new request defaults', () => {
	const PIPELINES = [
		{ id: 'p-lead', title: 'Leads', entityType: 'lead', isDefault: true },
		{ id: 'p-1', title: 'Requests', entityType: 'request' },
		{ id: 'p-2', title: 'Default requests', entityType: 'request', isDefault: true },
	]

	it('links the given client and places the request on the default request pipeline', () => {
		const ctx = context({ preLinkedClient: 'c-7' })
		ctx.objectStore.collections.pipeline = PIPELINES
		ctx.prepareNewRequest()
		expect(ctx.form.client).toBe('c-7')
		expect(ctx.form.pipeline).toBe('p-2')
		expect(ctx.placeOnFirstOpenStage).toHaveBeenCalledWith(PIPELINES[2])
		expect(ctx.form.ticketType).toBeUndefined()
	})

	it('on the board form makes it a request assigned to the signed-in user', () => {
		const ctx = context({ boardLayout: true })
		ctx.loadPickedClient = vi.fn()
		ctx.prepareNewRequest()
		expect(ctx.loadPickedClient).toHaveBeenCalledTimes(1)
		expect(ctx.form.ticketType).toBe('request')
		expect(ctx.form.assignee).toBe('ann')
		currentUser.value = null
		ctx.prepareNewRequest()
		expect(ctx.form.assignee).toBeNull()
	})

	it('leaves the pipeline empty without a default request pipeline', () => {
		const ctx = context()
		ctx.objectStore.collections.pipeline = [PIPELINES[0], PIPELINES[1]]
		ctx.autoAssignDefaultPipeline()
		expect(ctx.form.pipeline).toBeNull()
		expect(ctx.placeOnFirstOpenStage).not.toHaveBeenCalled()
	})
})

describe('RequestForm saving', () => {
	it('marks the title touched as it is typed', () => {
		const ctx = context()
		ctx.onTitleInput('Broken lamp')
		expect(ctx.form.title).toBe('Broken lamp')
		expect(ctx.titleTouched).toBe(true)
	})

	it('emits nothing without a title, but shows its error', () => {
		const ctx = context()
		ctx.form.title = '   '
		ctx.onSave()
		expect(ctx.titleTouched).toBe(true)
		expect(ctx.$emit).not.toHaveBeenCalled()
	})

	it('emits the form without its empty optional fields', () => {
		const ctx = context()
		ctx.form = { ...ctx.form, title: 'Broken lamp', description: '', client: 'c-1', category: '', ticketType: 'melding' }
		ctx.onSave()
		expect(ctx.$emit).toHaveBeenCalledWith('save', {
			title: 'Broken lamp',
			description: '',
			status: 'new',
			priority: 'normal',
			client: 'c-1',
			ticketType: 'melding',
		})
	})
})
