// @vitest-environment jsdom
/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 * SPDX-License-Identifier: EUPL-1.2
 *
 * The request form's client field and the fields around it: searching
 * clients (empty, failed and overtaken searches), the client the form opens
 * on, creating a client from the New client button, the requested-at date,
 * the statuses an edit may move to and what Save emits.
 *
 * @spec openspec/changes/request-dialog-client-lookups/specs/request-management/spec.md#requirement-the-new-request-dialog-finds-the-client-or-looks-them-up
 */

import { flushPromises, mount } from '@vue/test-utils'
import { beforeEach, describe, expect, it, vi } from 'vitest'
import { h } from 'vue'

const storeMock = vi.hoisted(() => ({
	fetchCollection: vi.fn(),
	fetchCollectionForOptions: vi.fn(),
	fetchObject: vi.fn(),
	collections: { pipeline: [] },
	objects: {},
}))

vi.mock('@nextcloud/auth', () => ({
	getCurrentUser: () => ({ uid: 'pieter', displayName: 'Pieter Jansen' }),
}))
vi.mock('../../src/store/modules/object.js', () => ({
	useObjectStore: () => storeMock,
}))
vi.mock('../../src/store/modules/requestChannels.js', () => ({
	useRequestChannelsStore: () => ({
		fetchChannels: vi.fn(async () => []),
		channelNames: [],
	}),
}))
vi.mock('../../src/services/ticketCategories.js', () => ({
	fetchTicketCategories: vi.fn(async () => []),
	categoryOptions: () => [],
}))
vi.mock('../../src/dialogs/ClientCreateDialog.vue', () => ({
	default: { name: 'ClientCreateDialog', render: () => null },
}))
vi.mock('../../src/dialogs/ContactCreateDialog.vue', () => ({
	default: { name: 'ContactCreateDialog', render: () => null },
}))
vi.mock('@conduction/nextcloud-vue', () => ({
	CnResourceSelect: { name: 'CnResourceSelect', render: () => null },
	CnSearchSelect: {
		name: 'CnSearchSelect',
		props: ['modelValue', 'options', 'loading', 'filterable', 'inputLabel', 'placeholder'],
		emits: ['search', 'update:modelValue'],
		render: () => null,
	},
}))
vi.mock('@nextcloud/vue', () => {
	const field = (name) => ({
		name,
		props: ['label', 'modelValue', 'ariaLabelCombobox', 'options', 'error', 'helperText', 'getOptionLabel'],
		emits: ['update:modelValue'],
		render() {
			return h('label', { 'data-label': this.ariaLabelCombobox || this.label })
		},
	})
	return {
		NcButton: {
			name: 'NcButton',
			emits: ['click'],
			render() {
				return h('button', { onClick: () => this.$emit('click') }, this.$slots.default?.())
			},
		},
		NcCheckboxRadioSwitch: {
			name: 'NcCheckboxRadioSwitch',
			props: ['value', 'modelValue'],
			render() {
				return h('span', { 'data-type': this.value }, [this.$slots.icon?.(), this.$slots.default?.()])
			},
		},
		NcNoteCard: {
			name: 'NcNoteCard',
			render() {
				return h('p', this.$slots.default?.())
			},
		},
		NcDateTimePickerNative: field('NcDateTimePickerNative'),
		NcSelect: field('NcSelect'),
		NcTextArea: field('NcTextArea'),
		NcTextField: field('NcTextField'),
	}
})

vi.mock('vue-material-design-icons/MessageTextOutline.vue', () => ({ default: { render: () => h('i') } }))
vi.mock('vue-material-design-icons/AlertOutline.vue', () => ({ default: { render: () => h('i') } }))
vi.mock('vue-material-design-icons/AlertCircleOutline.vue', () => ({ default: { render: () => h('i') } }))
vi.mock('vue-material-design-icons/PhoneOutline.vue', () => ({ default: { render: () => h('i') } }))
vi.mock('vue-material-design-icons/ClockOutline.vue', () => ({ default: { render: () => h('i') } }))
vi.mock('vue-material-design-icons/AccountPlusOutline.vue', () => ({ default: { render: () => h('i') } }))

globalThis.t = (_app, text, vars) => (vars ? text.replace(/\{(\w+)\}/g, (m, k) => vars[k]) : text)

const { default: RequestForm } = await import('../../src/views/requests/RequestForm.vue')

/**
 * @param {object} props The form's props.
 * @return {Promise<object>} The mounted form, opened.
 */
async function mountForm(props = {}) {
	const wrapper = mount(RequestForm, { props, global: { mocks: { t: globalThis.t } } })
	await flushPromises()
	return wrapper
}

/**
 * @return {{promise: Promise, resolve: Function, reject: Function}} A promise settled by hand.
 */
function deferred() {
	let resolve
	let reject
	const promise = new Promise((res, rej) => {
		resolve = res
		reject = rej
	})
	return { promise, resolve, reject }
}

beforeEach(() => {
	storeMock.fetchCollection.mockReset().mockResolvedValue([])
	storeMock.fetchCollectionForOptions.mockReset().mockResolvedValue([])
	storeMock.fetchObject.mockReset().mockResolvedValue(null)
	storeMock.collections = { pipeline: [] }
})

describe('RequestForm client search', () => {
	it('lists the matching clients as rows with their kind and address', async () => {
		const wrapper = await mountForm()
		storeMock.fetchCollectionForOptions.mockResolvedValue([
			{ id: 'c1', name: 'Gemeente Dorp', type: 'organization', address: 'Markt 1' },
			{ id: 'c2', type: 'person' },
		])

		await wrapper.vm.onClientSearch('  dorp ')

		expect(storeMock.fetchCollectionForOptions).toHaveBeenCalledWith('client', { _search: 'dorp', _limit: 20 })
		expect(wrapper.vm.clientOptions).toEqual([
			{ id: 'c1', label: 'Gemeente Dorp', secondary: 'Organisation · Markt 1', avatar: true },
			{ id: 'c2', label: '', secondary: 'Resident', avatar: true },
		])
		expect(wrapper.vm.clientSearching).toBe(false)
	})

	it('keeps only the picked client when the search is cleared, without asking the server', async () => {
		const wrapper = await mountForm()
		wrapper.vm.form.client = 'c2'
		wrapper.vm.clientOptions = [{ id: 'c1' }, { id: 'c2' }, { id: 'c3' }]

		await wrapper.vm.onClientSearch('   ')
		await wrapper.vm.onClientSearch(null)

		expect(storeMock.fetchCollectionForOptions).not.toHaveBeenCalled()
		expect(wrapper.vm.clientOptions).toEqual([{ id: 'c2' }])
	})

	it('empties the list when the search fails, and reads a non-list answer as no clients', async () => {
		const wrapper = await mountForm()
		wrapper.vm.clientOptions = [{ id: 'old' }]
		storeMock.fetchCollectionForOptions.mockRejectedValueOnce(new Error('down'))

		await wrapper.vm.onClientSearch('jan')

		expect(wrapper.vm.clientOptions).toEqual([])
		expect(wrapper.vm.clientSearching).toBe(false)

		wrapper.vm.clientOptions = [{ id: 'old' }]
		storeMock.fetchCollectionForOptions.mockResolvedValueOnce({ results: [] })
		await wrapper.vm.onClientSearch('jan')
		expect(wrapper.vm.clientOptions).toEqual([])
	})

	it('drops the answer of a search a newer one overtook', async () => {
		const wrapper = await mountForm()
		const slow = deferred()
		storeMock.fetchCollectionForOptions
			.mockReturnValueOnce(slow.promise)
			.mockResolvedValueOnce([{ id: 'new', name: 'Nieuw' }])

		const first = wrapper.vm.onClientSearch('ja')
		await wrapper.vm.onClientSearch('jan')
		slow.resolve([{ id: 'stale', name: 'Oud' }])
		await first

		expect(wrapper.vm.clientOptions.map((o) => o.id)).toEqual(['new'])
		expect(wrapper.vm.clientSearching).toBe(false)
	})

	it('ignores the failure of a search a newer one overtook', async () => {
		const wrapper = await mountForm()
		const slow = deferred()
		storeMock.fetchCollectionForOptions
			.mockReturnValueOnce(slow.promise)
			.mockResolvedValueOnce([{ id: 'new', name: 'Nieuw' }])

		const first = wrapper.vm.onClientSearch('ja')
		await wrapper.vm.onClientSearch('jan')
		slow.reject(new Error('late'))
		await first

		expect(wrapper.vm.clientOptions.map((o) => o.id)).toEqual(['new'])
	})
})

describe('RequestForm opening on a client', () => {
	it('opens a new request on the pre-linked client and lists it by name on the board', async () => {
		storeMock.fetchObject.mockResolvedValue({ id: 'c9', name: 'Jansen', type: 'person' })

		const wrapper = await mountForm({ preLinkedClient: 'c9', boardLayout: true })

		expect(wrapper.vm.form.client).toBe('c9')
		expect(storeMock.fetchObject).toHaveBeenCalledWith('client', 'c9')
		expect(wrapper.vm.clientOptions).toEqual([{ id: 'c9', label: 'Jansen', secondary: 'Resident', avatar: true }])
	})

	it('sets the pre-linked client without reading it outside the board', async () => {
		const wrapper = await mountForm({ preLinkedClient: 'c9' })

		expect(wrapper.vm.form.client).toBe('c9')
		expect(storeMock.fetchObject).not.toHaveBeenCalled()
		expect(wrapper.vm.form.ticketType).toBeUndefined()
	})

	it('does not read a client that is already listed, or none at all', async () => {
		const wrapper = await mountForm()
		wrapper.vm.clientOptions = [{ id: 'c1' }]

		wrapper.vm.form.client = 'c1'
		await wrapper.vm.loadPickedClient()
		wrapper.vm.form.client = null
		await wrapper.vm.loadPickedClient()

		expect(storeMock.fetchObject).not.toHaveBeenCalled()
	})

	it('keeps the list when the client cannot be read or the pick changed meanwhile', async () => {
		const wrapper = await mountForm()
		wrapper.vm.form.client = 'c1'
		storeMock.fetchObject.mockRejectedValueOnce(new Error('gone'))
		await wrapper.vm.loadPickedClient()
		expect(wrapper.vm.clientOptions).toEqual([])

		const slow = deferred()
		storeMock.fetchObject.mockReturnValueOnce(slow.promise)
		const loading = wrapper.vm.loadPickedClient()
		wrapper.vm.form.client = 'c2'
		slow.resolve({ id: 'c1', name: 'Eerst' })
		await loading
		expect(wrapper.vm.clientOptions).toEqual([])
	})
})

describe('RequestForm.newClient', () => {
	it('puts the created client first in the list, once, and on the ticket', async () => {
		const wrapper = await mountForm()
		wrapper.vm.clientOptions = [{ id: 'c1' }, { id: 'c5' }]
		wrapper.vm.form.contact = 'k1'

		const creating = wrapper.vm.newClient()
		expect(wrapper.vm.clientDialogOpen).toBe(true)
		expect(wrapper.vm.pendingName).toBe('')
		wrapper.vm.resolveCreate({ id: 'c5', name: 'Nieuw BV', type: 'organization' })
		await creating

		expect(wrapper.vm.clientOptions).toEqual([
			{ id: 'c5', label: 'Nieuw BV', secondary: 'Organisation', avatar: true },
			{ id: 'c1' },
		])
		expect(wrapper.vm.form.client).toBe('c5')
		expect(wrapper.vm.form.contact).toBeNull()
	})

	it('changes nothing when the dialog is cancelled', async () => {
		const wrapper = await mountForm()
		wrapper.vm.form.client = 'c1'

		const creating = wrapper.vm.newClient()
		wrapper.vm.resolveCreate(null)
		await creating

		expect(wrapper.vm.clientOptions).toEqual([])
		expect(wrapper.vm.form.client).toBe('c1')
	})
})

describe('RequestForm fields', () => {
	it('stores the picked requested-at date as a day and reads it back as a date', async () => {
		const wrapper = await mountForm()

		wrapper.vm.occurredAtDate = new Date(2026, 2, 5, 23, 30)
		expect(wrapper.vm.form.occurredAt).toBe('2026-03-05')
		expect(wrapper.vm.occurredAtDate).toEqual(new Date(2026, 2, 5))

		wrapper.vm.occurredAtDate = null
		expect(wrapper.vm.form.occurredAt).toBeNull()
		expect(wrapper.vm.occurredAtDate).toBeNull()
	})

	it('offers only new for a new request, and the current status with its transitions for an edit', async () => {
		const created = await mountForm()
		expect(created.vm.availableStatuses).toEqual(['new'])

		const edited = await mountForm({ request: { id: 'r1', title: 'Lek', status: 'new' } })
		expect(edited.vm.isEdit).toBe(true)
		expect(edited.vm.availableStatuses).toEqual(['new', 'in_progress', 'awaiting_customer', 'rejected', 'completed'])
	})

	it('emits nothing without a subject, and the request without its empty fields with one', async () => {
		const wrapper = await mountForm({ request: { id: 'r1', title: '  ', status: 'new', priority: 'high' } })

		wrapper.vm.onSave()
		expect(wrapper.vm.showTitleError).toBe(true)
		expect(wrapper.emitted('save')).toBeUndefined()

		wrapper.vm.onTitleInput('Lekkage')
		wrapper.vm.onSave()
		expect(wrapper.emitted('save')).toEqual([[{ id: 'r1', title: 'Lekkage', description: '', status: 'new', priority: 'high' }]])
	})

	it('emits cancel from the cancel button', async () => {
		const wrapper = await mountForm()

		const cancel = wrapper.findAll('button').find((b) => b.text() === 'Cancel')
		await cancel.trigger('click')

		expect(wrapper.emitted('cancel')).toEqual([[]])
	})
})
