// @vitest-environment jsdom
/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 * SPDX-License-Identifier: EUPL-1.2
 *
 * The find client widget as it renders: each result with its icon, name,
 * contact line and actions, the empty states, the new-client form, the inline
 * request or lead form and the copy feedback, and the clicks and keys wired
 * to them.
 *
 * @spec exclude Client-side dashboard widget rendering.
 */
import { flushPromises, mount } from '@vue/test-utils'
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'

vi.mock('@nextcloud/router', () => ({ generateUrl: (p) => '/index.php' + p }))
vi.mock('@nextcloud/vue', () => ({
	NcButton: { name: 'NcButton', props: ['disabled', 'href'], emits: ['click'], template: '<button class="nc-button" :disabled="disabled" :data-href="href" @click="$emit(\'click\')"><slot name="icon" /><slot /></button>' },
	NcLoadingIcon: { name: 'NcLoadingIcon', template: '<i class="loading" />' },
	NcNoteCard: { name: 'NcNoteCard', template: '<div class="note"><slot /></div>' },
	NcSelect: { name: 'NcSelect', props: ['modelValue', 'options'], template: '<div class="select" />' },
	NcTextField: { name: 'NcTextField', props: ['modelValue', 'label', 'placeholder', 'error'], emits: ['update:modelValue', 'keyup'], template: '<input class="text-field" :data-label="label" :data-placeholder="placeholder" :data-error="String(!!error)" @keyup="$emit(\'keyup\', $event)">' },
}))
vi.mock('@conduction/nextcloud-vue', () => ({ openRowTarget: vi.fn() }))
vi.mock('../../src/store/store.js', () => ({ initializeStores: vi.fn() }))
vi.mock('../../src/services/objectFetch.js', () => ({ fetchRegisteredObjects: vi.fn() }))
for (const icon of ['Account', 'AccountGroup', 'ContentCopy', 'Eye', 'FileDocumentOutline', 'TrendingUp']) {
	vi.doMock(`vue-material-design-icons/${icon}.vue`, () => ({ default: { name: icon, template: `<i class="icon-${icon}" />` } }))
}

globalThis.t = (_app, text) => text
const { default: FindClientWidget } = await import('../../src/views/widgets/FindClientWidget.vue')
const { openRowTarget } = await import('@conduction/nextcloud-vue')
const { initializeStores } = await import('../../src/store/store.js')
const { fetchRegisteredObjects } = await import('../../src/services/objectFetch.js')

const CLIENTS = [
	{ id: 'c-1', name: 'Acme', type: 'organization', email: 'info@acme.nl', phone: '010 123' },
	{ id: 'c-2', name: '', type: 'person', phone: '06 1' },
]

/**
 * Mounts the widget with the given clients found.
 *
 * @param {Array<object>} clients The clients the search answers
 * @return {Promise<object>} The wrapper
 */
async function widget(clients = CLIENTS) {
	initializeStores.mockResolvedValue({ objectStore: { objectTypeRegistry: { client: { register: 'r', schema: 'client' } } } })
	fetchRegisteredObjects.mockResolvedValue(clients)
	const wrapper = mount(FindClientWidget, {
		props: { title: 'Find client' },
		global: { mocks: { t: (_app, text, vars) => text.replace(/\{(\w+)\}/g, (m, k) => (vars && k in vars ? vars[k] : m)) } },
	})
	await flushPromises()
	return wrapper
}

/**
 * The button with the given aria label inside an element.
 *
 * @param {object} scope The element
 * @param {string} label The aria label
 * @return {object} The button
 */
function button(scope, label) {
	return scope.find(`button[aria-label="${label}"]`)
}

beforeEach(() => {
	vi.clearAllMocks()
})

afterEach(() => {
	vi.unstubAllGlobals()
})

describe('FindClientWidget results', () => {
	it('shows each client with its icon, name or fallback, and contact line', async () => {
		const wrapper = await widget()
		const rows = wrapper.findAll('.client-row')
		expect(rows.map((row) => [
			row.find('.client-icon i').classes(),
			row.find('.client-name').text(),
			row.find('.client-contact').text(),
			row.find('.client-info').attributes('aria-label'),
		])).toEqual([
			[['icon-AccountGroup'], 'Acme', 'info@acme.nl · 010 123', 'Open client Acme'],
			[['icon-Account'], 'Unnamed', '06 1', 'Open client '],
		])
	})

	it('links each client page and offers the copy only with an e-mail address', async () => {
		const wrapper = await widget()
		const [acme, other] = wrapper.findAll('.client-row')
		expect(button(acme, 'View client').attributes('data-href')).toBe('/index.php/apps/pipelinq/clients/c-1')
		expect(button(acme, 'Copy email').exists()).toBe(true)
		expect(button(other, 'Copy email').exists()).toBe(false)
	})

	it('opens a client on click, middle click, Enter and Space', async () => {
		const wrapper = await widget()
		const info = wrapper.find('.client-info')
		await info.trigger('click')
		await info.trigger('auxclick')
		await info.trigger('keydown.enter')
		await info.trigger('keydown.space')
		expect(openRowTarget).toHaveBeenCalledTimes(4)
		expect(openRowTarget.mock.calls.every(([, url]) => url === '/index.php/apps/pipelinq/clients/c-1')).toBe(true)
	})

	it('says nothing was found, naming the search term when there is one', async () => {
		const wrapper = await widget([])
		expect(wrapper.find('.empty-state').text()).toBe('No clients found')
		wrapper.vm.searchQuery = 'zz'
		await flushPromises()
		expect(wrapper.find('.empty-state').text()).toBe('No clients found for "zz"')
	})

	it('shows the loading icon instead of the results while loading', async () => {
		const wrapper = await widget()
		wrapper.vm.loading = true
		await flushPromises()
		expect(wrapper.find('.loading').exists()).toBe(true)
		expect(wrapper.find('.client-results').exists()).toBe(false)
		expect(wrapper.find('.empty-state').exists()).toBe(false)
	})
})

describe('FindClientWidget new client form', () => {
	it('toggles from the New client button and marks a missing name after a try', async () => {
		const wrapper = await widget()
		expect(wrapper.find('.new-client-form').exists()).toBe(false)
		await wrapper.find('.search-bar .nc-button').trigger('click')
		const form = wrapper.find('.new-client-form')
		expect(form.exists()).toBe(true)
		expect(form.find('.text-field').attributes('data-error')).toBe('false')
		await form.find('.nc-button').trigger('click')
		expect(wrapper.vm.newClientSubmitted).toBe(true)
		expect(wrapper.find('.new-client-form .text-field').attributes('data-error')).toBe('true')
		await wrapper.find('.search-bar .nc-button').trigger('click')
		expect(wrapper.find('.new-client-form').exists()).toBe(false)
	})

	it('disables the add button and says so while creating', async () => {
		const wrapper = await widget()
		wrapper.vm.showNewClientForm = true
		wrapper.vm.creatingClient = true
		await flushPromises()
		const add = wrapper.find('.new-client-form .nc-button')
		expect(add.text()).toBe('Creating…')
		expect(add.attributes('disabled')).toBeDefined()
	})
})

describe('FindClientWidget inline request or lead', () => {
	it('opens a request form for the client from its row', async () => {
		const wrapper = await widget()
		await button(wrapper.find('.client-row'), 'Create request for this client').trigger('click')
		const action = wrapper.find('.inline-action')
		expect(action.find('.note').text()).toBe('Creating request for Acme')
		expect(action.find('.text-field').attributes('data-placeholder')).toBe('Request title')
		const [create] = action.findAll('.action-buttons .nc-button')
		expect(create.attributes('disabled')).toBeDefined()
	})

	it('opens a lead form, enables Create with a title and cancels', async () => {
		const wrapper = await widget()
		await button(wrapper.find('.client-row'), 'Create lead for this client').trigger('click')
		expect(wrapper.find('.inline-action .note').text()).toBe('Creating lead for Acme')
		expect(wrapper.find('.inline-action .text-field').attributes('data-placeholder')).toBe('Lead title')
		wrapper.vm.actionTitle = 'Big deal'
		await flushPromises()
		const [create, cancel] = wrapper.findAll('.inline-action .action-buttons .nc-button')
		expect(create.attributes('disabled')).toBeUndefined()
		await cancel.trigger('click')
		expect(wrapper.find('.inline-action').exists()).toBe(false)
	})

	it('submits on Enter in the title and from the Create button', async () => {
		const wrapper = await widget()
		const submit = vi.spyOn(wrapper.vm, 'submitAction').mockResolvedValue()
		wrapper.vm.actionClient = CLIENTS[0]
		wrapper.vm.actionType = 'lead'
		wrapper.vm.actionTitle = 'Deal'
		await flushPromises()
		await wrapper.find('.inline-action .text-field').trigger('keyup.enter')
		await wrapper.findAll('.inline-action .action-buttons .nc-button')[0].trigger('click')
		expect(submit).toHaveBeenCalledTimes(2)
	})
})

describe('FindClientWidget copy feedback', () => {
	it('confirms a copied e-mail address', async () => {
		vi.stubGlobal('navigator', { clipboard: { writeText: vi.fn(async () => {}) } })
		const wrapper = await widget()
		await button(wrapper.find('.client-row'), 'Copy email').trigger('click')
		await flushPromises()
		expect(navigator.clipboard.writeText).toHaveBeenCalledWith('info@acme.nl')
		expect(wrapper.find('.copy-feedback').text()).toBe('Email copied!')
	})
})
