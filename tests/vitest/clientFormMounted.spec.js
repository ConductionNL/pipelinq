// @vitest-environment jsdom
/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 * SPDX-License-Identifier: EUPL-1.2
 *
 * The client form as mounted: typing into each field reaches the saved
 * client, errors wait until a field changes or a save is tried, the account
 * owner search asks Nextcloud's autocomplete only from two characters, the
 * language list is labelled and preselected, and the board layout warns
 * about a likely duplicate with a link to it and a "Save anyway".
 *
 * @spec openspec/specs/client-forms/spec.md#requirement-errors-wait-for-the-user
 */
import { flushPromises, mount } from '@vue/test-utils'
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import { h } from 'vue'

const http = vi.hoisted(() => ({ get: vi.fn() }))
const findClientDuplicate = vi.hoisted(() => vi.fn())
vi.mock('@nextcloud/axios', () => ({ default: http }))
vi.mock('@nextcloud/router', () => ({
	generateUrl: (path) => '/index.php' + path,
	generateOcsUrl: (path) => '/ocs/v2.php/' + path,
}))
vi.mock('@nextcloud/l10n', () => ({
	getLanguage: () => 'nl',
	translate: (app, text) => text,
}))
vi.mock('../../src/services/clientDuplicates.js', () => ({ findClientDuplicate }))
vi.mock('../../src/components/LabelSelect.vue', () => ({
	default: { name: 'LabelSelect', props: ['modelValue'], render: () => h('div') },
}))
vi.mock('vue-material-design-icons/AlertOutline.vue', () => ({
	default: { name: 'AlertOutline', render: () => h('i') },
}))

function field(name) {
	return {
		name,
		props: [
			'modelValue',
			'error',
			'helperText',
			'label',
			'options',
			'value',
			'loading',
		],
		emits: ['update:modelValue', 'search'],
		render() {
			return h('div', { class: name, 'data-error': String(!!this.error) }, [
				this.helperText || '',
				this.$slots.default?.(),
			])
		},
	}
}
vi.mock('@nextcloud/vue', () => ({
	NcButton: {
		name: 'NcButton',
		props: ['disabled', 'variant'],
		emits: ['click'],
		render() {
			return h(
				'button',
				{ disabled: this.disabled, onClick: () => this.$emit('click') },
				this.$slots.default?.(),
			)
		},
	},
	NcCheckboxRadioSwitch: field('NcCheckboxRadioSwitch'),
	NcSelect: field('NcSelect'),
	NcTextArea: field('NcTextArea'),
	NcTextField: field('NcTextField'),
}))

function t(app, text, vars) {
	return text.replace(/\{(\w+)\}/g, (m, k) => (vars && k in vars ? vars[k] : m))
}
globalThis.t = t

const { default: ClientForm } =
	await import('../../src/views/clients/ClientForm.vue')

function byTestId(wrapper, id) {
	return wrapper.findAllComponents('[data-testid="' + id + '"]')[0]
}

function mountForm(props = {}, mocks = {}) {
	return mount(ClientForm, { props, global: { mocks: { t, ...mocks } } })
}

describe('the mounted client form', () => {
	beforeEach(() => {
		vi.useFakeTimers()
		http.get.mockReset()
		findClientDuplicate.mockReset()
		http.get.mockResolvedValue({
			data: { languages: ['en', 'nl', 'xx_YY'], instanceDefault: 'en' },
		})
		window.OC = { getCurrentUser: () => ({ uid: 'anna', displayName: 'Anna' }) }
	})

	afterEach(() => {
		vi.useRealTimers()
		delete window.OC
	})

	it('saves what was typed into each field with the schema type', async () => {
		const wrapper = mountForm()
		await flushPromises()
		const type = byTestId(wrapper, 'client-type-select')
		await type.vm.$emit('update:modelValue', 'organization')
		for (const [id, value] of [
			['client-name-input', 'Acme'],
			['client-email-input', 'info@acme.nl'],
			['client-phone-input', '+31 20 123 4567'],
			['client-website-input', 'https://acme.nl'],
			['client-address-input', 'Dam 1'],
		]) {
			await byTestId(wrapper, id).vm.$emit('update:modelValue', value)
		}
		await wrapper
			.find('[data-testid="client-notes-input"]')
			.setValue('Key account')
		await wrapper.find('[data-testid="client-form-save"]').trigger('click')

		const [saved] = wrapper.emitted('save')[0]
		expect(saved).toMatchObject({
			name: 'Acme',
			type: 'organization',
			email: 'info@acme.nl',
			phone: '+31 20 123 4567',
			website: 'https://acme.nl',
			address: 'Dam 1',
			notes: 'Key account',
			accountOwner: 'anna',
			correspondenceLanguage: 'nl',
			'@type': 'schema:Organization',
		})
		expect(saved.id).toBeUndefined()
		expect(wrapper.emitted('update:valid').map((e) => e[0])).toEqual([
			false,
			true,
		])
	})

	it('keeps the client id when editing and starts from the initial name', async () => {
		const wrapper = mountForm({ initialName: 'Typed in picker' })
		expect(byTestId(wrapper, 'client-name-input').props('modelValue')).toBe(
			'Typed in picker',
		)
		await wrapper.setProps({
			client: { id: 'c-1', name: 'Bob', type: 'person' },
		})
		await flushPromises()
		await wrapper.find('[data-testid="client-form-save"]').trigger('click')
		const [saved] = wrapper.emitted('save')[0]
		expect(saved).toMatchObject({
			id: 'c-1',
			name: 'Bob',
			'@type': 'schema:Person',
		})
		// An existing client keeps its own language: none is preselected.
		expect(saved.correspondenceLanguage).toBeFalsy()
	})

	it('shows an error only once its field changed, and all of them after a failed save', async () => {
		const wrapper = mountForm()
		await flushPromises()
		const email = byTestId(wrapper, 'client-email-input')
		expect(byTestId(wrapper, 'client-name-input').props('error')).toBe(false)

		await email.vm.$emit('update:modelValue', 'not-an-email')
		expect(email.props('helperText')).toBe('Invalid email format')
		expect(
			byTestId(wrapper, 'client-name-input').props('helperText'),
		).toBeUndefined()
		expect(wrapper.find('.field-error').exists()).toBe(false)

		await byTestId(wrapper, 'client-form-save').vm.$emit('click')
		await wrapper.vm.$nextTick()
		expect(wrapper.emitted('save')).toBeUndefined()
		expect(byTestId(wrapper, 'client-name-input').props('helperText')).toBe(
			'Name is required',
		)
		expect(wrapper.find('.field-error').text()).toBe('Type is required')
		expect(
			wrapper.find('[data-testid="client-form-save"]').attributes('disabled'),
		).toBeDefined()
	})

	it('emits cancel and can leave its buttons to the host', async () => {
		const wrapper = mountForm()
		await wrapper.find('[data-testid="client-form-cancel"]').trigger('click')
		expect(wrapper.emitted('cancel')).toHaveLength(1)
		const bare = mountForm({ showActions: false })
		expect(bare.find('[data-testid="client-form-save"]').exists()).toBe(false)
	})

	it('labels the instance languages and preselects the user language', async () => {
		const wrapper = mountForm()
		await flushPromises()
		expect(http.get).toHaveBeenCalledWith(
			'/index.php/apps/pipelinq/api/correspondence-languages',
		)
		const options = byTestId(wrapper, 'client-language-select').props('options')
		const names = new Intl.DisplayNames(['nl'], { type: 'language' })
		expect(options).toEqual([
			{ id: 'en', label: names.of('en') },
			{ id: 'nl', label: names.of('nl') },
			{ id: 'xx_YY', label: names.of('xx-YY') || 'xx_YY' },
		])
		expect(byTestId(wrapper, 'client-language-select').props('modelValue')).toBe(
			'nl',
		)
	})

	it('leaves the language list empty when it cannot be read', async () => {
		http.get.mockRejectedValueOnce(new Error('down'))
		const wrapper = mountForm()
		await flushPromises()
		expect(byTestId(wrapper, 'client-language-select').props('options')).toEqual(
			[],
		)
	})

	it('searches account owners from two characters and keeps them on failure', async () => {
		const wrapper = mountForm()
		await flushPromises()
		http.get.mockClear()
		const owner = byTestId(wrapper, 'client-account-owner-select')
		expect(owner.props('options')).toEqual([{ id: 'anna', displayName: 'Anna' }])

		owner.vm.$emit('search', 'b')
		await flushPromises()
		expect(http.get).not.toHaveBeenCalled()

		http.get.mockResolvedValueOnce({
			data: { ocs: { data: [{ id: 'bob', label: 'Bob B' }, { id: 'bea' }] } },
		})
		owner.vm.$emit('search', 'be')
		await wrapper.vm.$nextTick()
		expect(owner.props('loading')).toBe(true)
		await flushPromises()
		expect(http.get).toHaveBeenCalledWith('/ocs/v2.php/core/autocomplete/get', {
			params: {
				search: 'be',
				itemType: 'pipelinq',
				itemId: 'client',
				'shareTypes[]': 0,
				limit: 20,
			},
		})
		expect(owner.props('options')).toEqual([
			{ id: 'bob', displayName: 'Bob B' },
			{ id: 'bea', displayName: 'bea' },
		])
		expect(owner.props('loading')).toBe(false)

		http.get.mockRejectedValueOnce(new Error('down'))
		owner.vm.$emit('search', 'bo')
		await flushPromises()
		expect(owner.props('options')).toHaveLength(2)
		expect(owner.props('loading')).toBe(false)
	})
})

describe('the board layout client form', () => {
	beforeEach(() => {
		vi.useFakeTimers()
		http.get.mockReset()
		http.get.mockResolvedValue({ data: {} })
		findClientDuplicate.mockReset()
	})

	afterEach(() => {
		vi.useRealTimers()
	})

	it('picks the type with radios and fills each board field', async () => {
		const wrapper = mountForm({ boardLayout: true })
		const radios = wrapper.findAllComponents({ name: 'NcCheckboxRadioSwitch' })
		expect(radios.length).toBeGreaterThan(1)
		const person = radios.find((r) => r.props('value') === 'person')
		await person.vm.$emit('update:modelValue', 'person')
		for (const [id, value] of [
			['client-name-input', 'Piet'],
			['client-email-input', 'piet@x.nl'],
			['client-phone-input', '0612345678'],
			['client-address-input', 'Straat 1'],
			['client-website-input', 'https://piet.nl'],
			['client-notes-input', 'Note'],
		]) {
			await byTestId(wrapper, id).vm.$emit('update:modelValue', value)
		}
		expect(wrapper.vm.form).toMatchObject({
			type: 'person',
			name: 'Piet',
			email: 'piet@x.nl',
			phone: '0612345678',
			address: 'Straat 1',
			website: 'https://piet.nl',
			notes: 'Note',
		})
	})

	it('warns about a likely duplicate after a pause, links to it and saves anyway', async () => {
		findClientDuplicate.mockResolvedValue({
			id: 'c-9',
			name: 'Piet',
			field: 'email',
		})
		const resolve = vi.fn(() => ({ href: '/clients/c-9' }))
		const wrapper = mountForm({ boardLayout: true }, { $router: { resolve } })
		wrapper.vm.form.type = 'person'
		await byTestId(wrapper, 'client-email-input').vm.$emit(
			'update:modelValue',
			'piet@x.nl',
		)
		await byTestId(wrapper, 'client-name-input').vm.$emit(
			'update:modelValue',
			'Piet',
		)
		await vi.advanceTimersByTimeAsync(399)
		expect(findClientDuplicate).not.toHaveBeenCalled()

		await vi.advanceTimersByTimeAsync(1)
		await flushPromises()
		expect(findClientDuplicate).toHaveBeenCalledTimes(1)
		expect(findClientDuplicate).toHaveBeenCalledWith({
			email: 'piet@x.nl',
			name: 'Piet',
		})
		expect(resolve).toHaveBeenCalledWith({
			name: 'ClientDetail',
			params: { id: 'c-9' },
		})
		const warning = wrapper.find('[data-testid="client-duplicate"]')
		expect(warning.text()).toContain(
			'Possible duplicate: Piet has the same email address',
		)
		expect(
			wrapper.find('[data-testid="client-duplicate-open"]').attributes('href'),
		).toBe('/clients/c-9')

		await wrapper.find('[data-testid="client-duplicate-save"]').trigger('click')
		expect(wrapper.emitted('save')[0][0]).toMatchObject({
			name: 'Piet',
			'@type': 'schema:Person',
		})
	})

	it('does not look for duplicates when editing an existing client', async () => {
		const wrapper = mountForm({
			boardLayout: true,
			client: { id: 'c-1', name: 'A' },
		})
		await byTestId(wrapper, 'client-name-input').vm.$emit(
			'update:modelValue',
			'Another',
		)
		await vi.advanceTimersByTimeAsync(1000)
		expect(findClientDuplicate).not.toHaveBeenCalled()
	})
})
