// @vitest-environment jsdom
/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 * SPDX-License-Identifier: EUPL-1.2
 *
 * What the find client widget renders: the loading state, the client rows
 * with their contact line and actions, the empty states, the new client
 * form, the inline request/lead prompt and the copy feedback.
 */
import { flushPromises, mount } from '@vue/test-utils'
import { afterEach, beforeAll, beforeEach, describe, expect, it, vi } from 'vitest'
import { h } from 'vue'

vi.mock('@nextcloud/router', () => ({ generateUrl: (p) => '/index.php' + p }))
vi.mock('@nextcloud/dialogs', () => ({ showError: vi.fn(), showSuccess: vi.fn() }))
vi.mock('@conduction/nextcloud-vue', () => ({ openRowTarget: vi.fn() }))
vi.mock('../../src/store/store.js', () => ({
	initializeStores: vi.fn(async () => ({
		objectStore: { objectTypeRegistry: { client: { register: 'r', schema: 'client' } } },
	})),
}))
vi.mock('@nextcloud/vue', () => ({
	NcButton: {
		name: 'NcButton',
		props: ['ariaLabel', 'href', 'disabled', 'variant'],
		emits: ['click'],
		render() {
			return h('button', {
				'aria-label': this.ariaLabel,
				'data-href': this.href,
				disabled: this.disabled,
				onClick: () => this.$emit('click'),
			}, [this.$slots.icon?.(), this.$slots.default?.()])
		},
	},
	NcLoadingIcon: { name: 'NcLoadingIcon', render: () => h('span', { class: 'loading-icon' }) },
	NcNoteCard: { name: 'NcNoteCard', render() { return h('div', { class: 'note' }, this.$slots.default?.()) } },
	NcSelect: { name: 'NcSelect', render: () => null },
	NcTextField: {
		name: 'NcTextField',
		props: ['modelValue', 'label', 'placeholder', 'error'],
		emits: ['update:modelValue', 'keyup'],
		render() {
			return h('input', {
				'aria-label': this.label,
				placeholder: this.placeholder,
				'data-error': String(!!this.error),
				value: this.modelValue,
				onInput: (e) => this.$emit('update:modelValue', e.target.value),
			})
		},
	},
}))

let Widget

beforeAll(async () => {
	globalThis.t = (app, text, vars = {}) => text.replace(/\{(\w+)\}/g, (_m, k) => vars[k])
	globalThis.OC = { requestToken: 'tok' }
	Widget = (await import('../../src/views/widgets/FindClientWidget.vue')).default
}, 60000)

let clients

beforeEach(() => {
	clients = []
	vi.stubGlobal('fetch', vi.fn(async () => ({ ok: true, json: async () => ({ results: clients }) })))
	vi.spyOn(console, 'error').mockImplementation(() => {})
})

afterEach(() => {
	vi.unstubAllGlobals()
	vi.restoreAllMocks()
	vi.useRealTimers()
})

async function mountWidget() {
	const wrapper = mount(Widget, { props: { title: 'Find client' }, global: { mocks: { t: globalThis.t } } })
	await flushPromises()
	return wrapper
}

const labels = (wrapper) => wrapper.findAll('.client-actions button').map((b) => b.attributes('aria-label'))

describe('FindClientWidget rendering', () => {
	it('shows the loading state while clients load', async () => {
		let release
		vi.stubGlobal('fetch', vi.fn(() => new Promise((resolve) => { release = resolve })))
		const wrapper = mount(Widget, { props: { title: 'Find client' }, global: { mocks: { t: globalThis.t } } })
		await flushPromises()

		expect(wrapper.find('.loading-state .loading-icon').exists()).toBe(true)
		expect(wrapper.find('.empty-state').exists()).toBe(false)

		release({ ok: true, json: async () => ({ results: [] }) })
		await flushPromises()
		expect(wrapper.find('.loading-state').exists()).toBe(false)
		expect(wrapper.find('.empty-state').text()).toBe('No clients found')
	})

	it('renders each client with its contact line and actions', async () => {
		clients = [
			{ id: 'c1', name: 'Gemeente Utrecht', type: 'organization', email: 'info@utrecht.nl', phone: '030' },
			{ id: 'c2', name: '', type: 'person', phone: '06' },
		]
		const wrapper = await mountWidget()
		const rows = wrapper.findAll('.client-row')

		expect(rows).toHaveLength(2)
		expect(rows[0].find('.client-name').text()).toBe('Gemeente Utrecht')
		expect(rows[0].find('.client-contact').text()).toBe('info@utrecht.nl · 030')
		expect(rows[0].find('.client-info').attributes('aria-label')).toBe('Open client Gemeente Utrecht')
		expect(rows[1].find('.client-name').text()).toBe('Unnamed')
		expect(rows[1].find('.client-contact').text()).toBe('06')
		expect(labels(rows[0])).toEqual(['View client', 'Create request for this client', 'Create lead for this client', 'Copy email'])
		expect(labels(rows[1])).toEqual(['View client', 'Create request for this client', 'Create lead for this client'])
		expect(rows[0].find('button[aria-label="View client"]').attributes('data-href')).toBe('/index.php/apps/pipelinq/clients/c1')
	})

	it('names the search in the empty state', async () => {
		const wrapper = await mountWidget()
		await wrapper.setData({ searchQuery: 'xyz' })

		expect(wrapper.find('.empty-state').text()).toBe('No clients found for "xyz"')
	})

	it('toggles the new client form and marks a missing name once submitted', async () => {
		const wrapper = await mountWidget()
		expect(wrapper.find('.new-client-form').exists()).toBe(false)

		await wrapper.find('.search-bar button').trigger('click')
		expect(wrapper.find('.new-client-form').exists()).toBe(true)
		expect(wrapper.find('.new-client-form input[aria-label="Name"]').attributes('data-error')).toBe('false')

		await wrapper.setData({ newClientSubmitted: true })
		expect(wrapper.find('.new-client-form input[aria-label="Name"]').attributes('data-error')).toBe('true')

		await wrapper.setData({ creatingClient: true })
		expect(wrapper.find('.new-client-form button').text()).toBe('Creating…')
		expect(wrapper.find('.new-client-form button').attributes('disabled')).toBeDefined()
	})

	it('prompts for a request or lead title for the chosen client and cancels it', async () => {
		clients = [{ id: 'c1', name: 'Jan', type: 'person' }]
		const wrapper = await mountWidget()

		await wrapper.find('button[aria-label="Create request for this client"]').trigger('click')
		expect(wrapper.find('.inline-action .note').text()).toBe('Creating request for Jan')
		expect(wrapper.find('.inline-action input').attributes('placeholder')).toBe('Request title')
		expect(wrapper.find('.action-buttons button').attributes('disabled')).toBeDefined()

		await wrapper.find('button[aria-label="Create lead for this client"]').trigger('click')
		expect(wrapper.find('.inline-action .note').text()).toBe('Creating lead for Jan')
		expect(wrapper.find('.inline-action input').attributes('placeholder')).toBe('Lead title')

		await wrapper.find('.inline-action input').setValue('Offerte')
		expect(wrapper.find('.action-buttons button').attributes('disabled')).toBeUndefined()

		await wrapper.findAll('.action-buttons button')[1].trigger('click')
		expect(wrapper.find('.inline-action').exists()).toBe(false)
	})

	it('copies the email and shows feedback for two seconds', async () => {
		vi.useFakeTimers()
		const writeText = vi.fn(async () => {})
		vi.stubGlobal('navigator', { clipboard: { writeText } })
		clients = [{ id: 'c1', name: 'Jan', email: 'jan@example.nl' }]
		const wrapper = await mountWidget()

		await wrapper.find('button[aria-label="Copy email"]').trigger('click')
		await flushPromises()

		expect(writeText).toHaveBeenCalledWith('jan@example.nl')
		expect(wrapper.find('.copy-feedback').text()).toBe('Email copied!')

		vi.advanceTimersByTime(2000)
		await flushPromises()
		expect(wrapper.find('.copy-feedback').exists()).toBe(false)
	})
})
