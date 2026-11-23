// @vitest-environment jsdom
/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 * SPDX-License-Identifier: EUPL-1.2
 *
 * How the client form validates, saves, loads its languages, searches the
 * account owner picker and warns about a likely duplicate. HTTP and the
 * duplicate lookup are mocked; the form itself runs for real.
 *
 * @spec openspec/specs/client-management/spec.md
 */

import { flushPromises, mount } from '@vue/test-utils'
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import { h } from 'vue'

const axiosGet = vi.hoisted(() => vi.fn())
const findClientDuplicate = vi.hoisted(() => vi.fn())

vi.mock('@nextcloud/axios', () => ({ default: { get: axiosGet } }))
vi.mock('../../src/services/clientDuplicates.js', () => ({ findClientDuplicate }))
vi.mock('@nextcloud/router', () => ({
	generateUrl: (p) => p,
	generateOcsUrl: (p) => `/ocs/${p}`,
}))
vi.mock('@nextcloud/l10n', () => ({
	getLanguage: () => 'en',
	translate: (_app, text) => text,
}))
vi.mock('@nextcloud/vue', () => {
	const stub = (name) => ({
		name,
		inheritAttrs: false,
		render() {
			return h('div', { 'data-name': name }, this.$slots.default?.())
		},
	})
	return {
		NcButton: {
			name: 'NcButton',
			emits: ['click'],
			render() {
				return h(
					'button',
					{ onClick: () => this.$emit('click') },
					this.$slots.default?.(),
				)
			},
		},
		NcCheckboxRadioSwitch: stub('NcCheckboxRadioSwitch'),
		NcSelect: stub('NcSelect'),
		NcTextArea: stub('NcTextArea'),
		NcTextField: stub('NcTextField'),
	}
})
vi.mock('../../src/components/LabelSelect.vue', () => ({
	default: { name: 'LabelSelect', render: () => h('span') },
}))

globalThis.t = (_app, text) => text

const { default: ClientForm } =
	await import('../../src/views/clients/ClientForm.vue')

/**
 * Mount the form with the given props.
 *
 * @param {object} props The props.
 * @param {object} router An optional router mock.
 * @return {object} The wrapper.
 */
function mountForm(props = {}, router = undefined) {
	return mount(ClientForm, {
		props,
		global: {
			mocks: {
				t: (_app, text) => text,
				...(router ? { $router: router } : {}),
			},
		},
	})
}

beforeEach(() => {
	axiosGet.mockReset()
	findClientDuplicate.mockReset()
	axiosGet.mockResolvedValue({ data: { languages: [] } })
})

afterEach(() => {
	vi.useRealTimers()
	delete window.OC
})

describe('ClientForm validation', () => {
	it('requires a name and a type on an empty form', async () => {
		const wrapper = mountForm()
		await flushPromises()
		expect(wrapper.vm.errors).toEqual({
			name: 'Name is required',
			type: 'Type is required',
		})
		expect(wrapper.vm.isValid).toBe(false)
		expect(wrapper.emitted('update:valid')[0]).toEqual([false])
	})

	it('treats a blank name as missing and caps it at 255 characters', async () => {
		const wrapper = mountForm()
		wrapper.vm.form.type = 'person'
		wrapper.vm.form.name = '   '
		expect(wrapper.vm.errors.name).toBe('Name is required')
		wrapper.vm.form.name = 'x'.repeat(256)
		expect(wrapper.vm.errors.name).toBe('Name must be at most 255 characters')
		wrapper.vm.form.name = 'x'.repeat(255)
		expect(wrapper.vm.errors).toEqual({})
	})

	it('flags a malformed email, phone and website only once filled in', async () => {
		const wrapper = mountForm({ initialName: 'Acme' })
		wrapper.vm.form.type = 'organization'
		expect(wrapper.vm.errors).toEqual({})
		Object.assign(wrapper.vm.form, {
			email: 'nope',
			phone: 'abc',
			website: 'example.com',
		})
		expect(wrapper.vm.errors).toEqual({
			email: 'Invalid email format',
			phone: 'Invalid phone format',
			website: 'Invalid URL format',
		})
		Object.assign(wrapper.vm.form, {
			email: 'a@b.nl',
			phone: '+31 (0)20-1234567',
			website: 'https://acme.nl',
		})
		expect(wrapper.vm.errors).toEqual({})
	})

	it('reports validity to the host when it changes', async () => {
		const wrapper = mountForm({ initialName: 'Acme' })
		wrapper.vm.form.type = 'person'
		await flushPromises()
		expect(wrapper.emitted('update:valid').at(-1)).toEqual([true])
	})
})

describe('ClientForm saving', () => {
	it('does not save an invalid form and shows every error', async () => {
		const wrapper = mountForm()
		wrapper.vm.onSave()
		expect(wrapper.emitted('save')).toBeUndefined()
		expect(wrapper.vm.saveAttempted).toBe(true)
		expect(wrapper.vm.shownErrors).toEqual(wrapper.vm.errors)
	})

	it('saves a new organisation with its schema type', async () => {
		const wrapper = mountForm({ initialName: 'Acme' })
		wrapper.vm.form.type = 'organization'
		wrapper.vm.onSave()
		const [data] = wrapper.emitted('save')[0]
		expect(data.name).toBe('Acme')
		expect(data['@type']).toBe('schema:Organization')
		expect(data).not.toHaveProperty('id')
	})

	it('keeps the id of an existing client and maps a person', async () => {
		const wrapper = mountForm({
			client: { id: 'c-1', name: 'Jan', type: 'person' },
		})
		await flushPromises()
		expect(wrapper.vm.form.name).toBe('Jan')
		wrapper.vm.onSave()
		const [data] = wrapper.emitted('save')[0]
		expect(data.id).toBe('c-1')
		expect(data['@type']).toBe('schema:Person')
	})

	it('falls back to a person for an unknown type', async () => {
		const wrapper = mountForm({ initialName: 'X' })
		wrapper.vm.form.type = 'robot'
		wrapper.vm.onSave()
		expect(wrapper.emitted('save')[0][0]['@type']).toBe('schema:Person')
	})

	it('emits cancel from its own cancel button', async () => {
		const wrapper = mountForm({ initialName: 'X' })
		const cancel = wrapper.findAll('button').find((b) => b.text() === 'Cancel')
		await cancel.trigger('click')
		expect(wrapper.emitted('cancel')).toHaveLength(1)
		expect(wrapper.emitted('save')).toBeUndefined()
	})
})

describe('ClientForm correspondence languages', () => {
	it('loads the languages and preselects the user language for a new client', async () => {
		axiosGet.mockResolvedValue({
			data: { languages: ['nl', 'en'], instanceDefault: 'nl' },
		})
		const wrapper = mountForm()
		await flushPromises()
		expect(axiosGet).toHaveBeenCalledWith(
			'/apps/pipelinq/api/correspondence-languages',
		)
		expect(wrapper.vm.languages).toEqual(['nl', 'en'])
		expect(wrapper.vm.form.correspondenceLanguage).toBe('en')
		expect(wrapper.vm.languageOptions.map((o) => o.id)).toEqual(['nl', 'en'])
		expect(wrapper.vm.languageOptions[1].label).toBe('English')
	})

	it('does not preselect a language for an existing client', async () => {
		axiosGet.mockResolvedValue({
			data: { languages: ['nl'], instanceDefault: 'nl' },
		})
		const wrapper = mountForm({
			client: { id: 'c-1', name: 'Jan', type: 'person' },
		})
		await flushPromises()
		expect(wrapper.vm.form.correspondenceLanguage).toBe('')
	})

	it('treats a non-array answer as no languages', async () => {
		axiosGet.mockResolvedValue({ data: { languages: 'nl' } })
		const wrapper = mountForm()
		await flushPromises()
		expect(wrapper.vm.languages).toEqual([])
		expect(wrapper.vm.languageOptions).toEqual([])
	})

	it('keeps an empty list when the request fails', async () => {
		axiosGet.mockRejectedValue(new Error('down'))
		const wrapper = mountForm()
		await flushPromises()
		expect(wrapper.vm.languages).toEqual([])
	})
})

describe('ClientForm account owner search', () => {
	it('starts with the signed-in user as owner and only option', async () => {
		window.OC = { getCurrentUser: () => ({ uid: 'anna', displayName: 'Anna' }) }
		const wrapper = mountForm()
		expect(wrapper.vm.form.accountOwner).toBe('anna')
		expect(wrapper.vm.userOptions).toEqual([{ id: 'anna', displayName: 'Anna' }])
	})

	it('ignores a query shorter than two characters', async () => {
		const wrapper = mountForm()
		await flushPromises()
		axiosGet.mockClear()
		await wrapper.vm.searchUsers('a')
		await wrapper.vm.searchUsers('')
		expect(axiosGet).not.toHaveBeenCalled()
	})

	it('maps the autocomplete answer to options', async () => {
		const wrapper = mountForm()
		await flushPromises()
		axiosGet.mockResolvedValue({
			data: { ocs: { data: [{ id: 'bob', label: 'Bob B' }, { id: 'cy' }] } },
		})
		await wrapper.vm.searchUsers('bo')
		expect(axiosGet).toHaveBeenLastCalledWith('/ocs/core/autocomplete/get', {
			params: {
				search: 'bo',
				itemType: 'pipelinq',
				itemId: 'client',
				'shareTypes[]': 0,
				limit: 20,
			},
		})
		expect(wrapper.vm.userOptions).toEqual([
			{ id: 'bob', displayName: 'Bob B' },
			{ id: 'cy', displayName: 'cy' },
		])
		expect(wrapper.vm.searchingUsers).toBe(false)
	})

	it('keeps the previous options when the search fails', async () => {
		const wrapper = mountForm()
		await flushPromises()
		wrapper.vm.userOptions = [{ id: 'old', displayName: 'Old' }]
		axiosGet.mockRejectedValue(new Error('down'))
		await wrapper.vm.searchUsers('ol')
		expect(wrapper.vm.userOptions).toEqual([{ id: 'old', displayName: 'Old' }])
		expect(wrapper.vm.searchingUsers).toBe(false)
	})
})

describe('ClientForm duplicate warning', () => {
	it('looks for a duplicate after typing on the board layout', async () => {
		vi.useFakeTimers()
		findClientDuplicate.mockResolvedValue({
			id: 'c-9',
			field: 'email',
			name: 'Acme',
		})
		const router = { resolve: vi.fn(() => ({ href: '/clients/c-9' })) }
		const wrapper = mountForm({ boardLayout: true }, router)
		wrapper.vm.form.email = 'info@acme.nl'
		await wrapper.vm.$nextTick()
		expect(findClientDuplicate).not.toHaveBeenCalled()
		await vi.advanceTimersByTimeAsync(400)
		expect(findClientDuplicate).toHaveBeenCalledWith({
			email: 'info@acme.nl',
			name: '',
		})
		expect(router.resolve).toHaveBeenCalledWith({
			name: 'ClientDetail',
			params: { id: 'c-9' },
		})
		expect(wrapper.vm.duplicate).toEqual({
			id: 'c-9',
			name: 'Acme',
			field: 'email address',
			href: '/clients/c-9',
		})
	})

	it('labels a name match and has no link without a router', async () => {
		findClientDuplicate.mockResolvedValue({ id: 'c-2', field: 'name' })
		const wrapper = mountForm({ boardLayout: true })
		await wrapper.vm.checkDuplicate()
		expect(wrapper.vm.duplicate).toEqual({ id: 'c-2', field: 'name', href: '' })
	})

	it('clears the warning when nothing matches', async () => {
		findClientDuplicate.mockResolvedValue(null)
		const wrapper = mountForm({ boardLayout: true })
		wrapper.vm.duplicate = { id: 'x' }
		await wrapper.vm.checkDuplicate()
		expect(wrapper.vm.duplicate).toBeNull()
	})

	it('drops an answer for input that changed meanwhile', async () => {
		let resolve
		findClientDuplicate.mockReturnValue(
			new Promise((r) => {
				resolve = r
			}),
		)
		const wrapper = mountForm({ boardLayout: true })
		const pending = wrapper.vm.checkDuplicate()
		wrapper.vm.form.name = 'Changed'
		resolve({ id: 'c-3', field: 'name' })
		await pending
		expect(wrapper.vm.duplicate).toBeNull()
	})

	it('does not check outside the board layout or for an existing client', async () => {
		vi.useFakeTimers()
		const plain = mountForm()
		plain.vm.form.name = 'Acme'
		const existing = mountForm({
			boardLayout: true,
			client: { id: 'c-1', name: 'A', type: 'person' },
		})
		existing.vm.form.name = 'Acme'
		await vi.advanceTimersByTimeAsync(1000)
		expect(findClientDuplicate).not.toHaveBeenCalled()
	})
})
