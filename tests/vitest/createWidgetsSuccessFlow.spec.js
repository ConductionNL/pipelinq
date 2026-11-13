// @vitest-environment jsdom
/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 * SPDX-License-Identifier: EUPL-1.2
 *
 * The start request and create lead widgets, mounted: the recent requests
 * list, the success card after a create, and "Create another" starting a
 * clean form again.
 */
import { flushPromises, mount } from '@vue/test-utils'
import { afterEach, beforeAll, beforeEach, describe, expect, it, vi } from 'vitest'
import { h } from 'vue'

const stores = vi.hoisted(() => ({ fail: false }))

vi.mock('@nextcloud/router', () => ({ generateUrl: (p) => '/index.php' + p }))
vi.mock('../../src/store/store.js', () => ({
	initializeStores: vi.fn(async () => {
		if (stores.fail) {
			throw new Error('no stores')
		}
		return {
			objectStore: {
				objectTypeRegistry: {
					ticket: { register: 'r', schema: 'ticket' },
					lead: { register: 'r', schema: 'lead' },
					pipeline: { register: 'r', schema: 'pipeline' },
				},
			},
		}
	}),
}))
vi.mock('../../src/components/widgets/ClientAutocomplete.vue', () => ({
	default: { name: 'ClientAutocomplete', render: () => null },
}))
vi.mock('@nextcloud/vue', () => ({
	NcButton: {
		name: 'NcButton',
		props: ['disabled', 'variant'],
		emits: ['click'],
		render() {
			return h('button', { disabled: this.disabled, onClick: () => this.$emit('click') }, this.$slots.default?.())
		},
	},
	NcNoteCard: { name: 'NcNoteCard', render() { return h('div', { class: 'note' }, this.$slots.default?.()) } },
	NcSelect: { name: 'NcSelect', render: () => null },
	NcTextField: {
		name: 'NcTextField',
		props: ['modelValue', 'label', 'error'],
		emits: ['update:modelValue', 'keyup'],
		render() {
			return h('input', {
				'aria-label': this.label,
				'data-error': String(!!this.error),
				value: this.modelValue,
				onInput: (e) => this.$emit('update:modelValue', e.target.value),
				onKeyup: (e) => this.$emit('keyup', e),
			})
		},
	},
}))

let StartRequest
let CreateLead
let requests

beforeAll(async () => {
	globalThis.t = (app, text) => text
	globalThis.OC = { requestToken: 'tok' }
	StartRequest = (await import('../../src/views/widgets/StartRequestWidget.vue')).default
	CreateLead = (await import('../../src/views/widgets/CreateLeadWidget.vue')).default
}, 60000)

beforeEach(() => {
	stores.fail = false
	requests = []
	vi.stubGlobal('fetch', vi.fn(async (url, init = {}) => {
		requests.push([init.method || 'GET', url, init.body ? JSON.parse(init.body) : null])
		if (init.method === 'POST') {
			return { ok: true, json: async () => ({ id: url.includes('/lead') ? 'lead-9' : 'ticket-9' }) }
		}
		if (url.includes('/pipeline')) {
			return { ok: true, json: async () => ({ results: [{ id: 'p1', title: 'Sales', stages: [{ name: 'Won', order: 2 }, { name: 'New', order: 1 }] }, { id: 'p2', title: '' }] }) }
		}
		return { ok: true, json: async () => ({ results: [{ id: 't1', title: 'Lekkage', status: 'new' }, { id: 't2', title: '', status: 'closed' }] }) }
	}))
	vi.spyOn(console, 'error').mockImplementation(() => {})
})

afterEach(() => {
	vi.unstubAllGlobals()
	vi.restoreAllMocks()
})

async function mountWidget(component) {
	const wrapper = mount(component, { props: { title: 'Widget' }, global: { mocks: { t: globalThis.t } } })
	await flushPromises()
	return wrapper
}

describe('StartRequestWidget', () => {
	it('lists the three most recent request tickets with links', async () => {
		const wrapper = await mountWidget(StartRequest)
		const items = wrapper.findAll('.recent-list li')

		expect(requests[0][1]).toBe('/index.php/apps/openregister/api/objects/r/ticket?ticketType=request&_limit=3&_order=desc')
		expect(items.map((li) => li.find('a').text())).toEqual(['Lekkage', 'Untitled'])
		expect(items[0].find('a').attributes('href')).toBe('/index.php/apps/pipelinq/tickets/t1')
		expect(items[1].find('.recent-status').text()).toBe('closed')
	})

	it('shows a success card after creating, and Create another starts over', async () => {
		const wrapper = await mountWidget(StartRequest)
		await wrapper.find('input[aria-label="Title"]').setValue('Nieuwe melding')
		await wrapper.find('.widget-form button').trigger('click')
		await flushPromises()

		expect(wrapper.find('.widget-form').exists()).toBe(false)
		expect(wrapper.find('.widget-success .note').text()).toContain('Request created!')
		expect(wrapper.find('.widget-success a').attributes('href')).toBe('/index.php/apps/pipelinq/tickets/ticket-9')

		const before = requests.length
		await wrapper.find('.widget-success button').trigger('click')
		await flushPromises()

		expect(wrapper.find('.widget-success').exists()).toBe(false)
		expect(wrapper.find('input[aria-label="Title"]').element.value).toBe('')
		expect(wrapper.find('input[aria-label="Title"]').attributes('data-error')).toBe('false')
		expect(wrapper.vm.form).toEqual({ title: '', category: null, priority: 'normal', channel: null })
		expect(requests.slice(before).map((r) => r[0])).toEqual(['GET'])
	})

	it('logs a failed start and shows no recent list', async () => {
		stores.fail = true
		const wrapper = await mountWidget(StartRequest)

		expect(console.error).toHaveBeenCalledWith('StartRequestWidget init error:', expect.any(Error))
		expect(wrapper.find('.recent-list').exists()).toBe(false)
		expect(requests).toEqual([])
	})
})

describe('CreateLeadWidget', () => {
	it('quick-adds on Enter only once a title is typed, into the first stage', async () => {
		const wrapper = await mountWidget(CreateLead)
		const title = wrapper.find('input[aria-label="Title"]')

		await title.trigger('keyup', { key: 'Enter' })
		await flushPromises()
		expect(requests.filter((r) => r[0] === 'POST')).toEqual([])

		await title.setValue('Offerte')
		await title.trigger('keyup', { key: 'Enter' })
		await flushPromises()

		expect(requests.filter((r) => r[0] === 'POST')).toEqual([
			['POST', '/index.php/apps/openregister/api/objects/r/lead', { title: 'Offerte', status: 'open', stage: 'New', stageOrder: 1, pipeline: 'p1' }],
		])
		expect(wrapper.find('.widget-success .note').text()).toContain('Lead created!')
		expect(wrapper.find('.widget-success a').attributes('href')).toBe('/index.php/apps/pipelinq/leads/lead-9')
	})

	it('Create another clears the form but keeps the first pipeline selected', async () => {
		const wrapper = await mountWidget(CreateLead)
		await wrapper.find('input[aria-label="Title"]').setValue('Offerte')
		await wrapper.find('input[aria-label="Value"]').setValue('1200')
		await wrapper.find('.widget-form button').trigger('click')
		await flushPromises()

		await wrapper.find('.widget-success button').trigger('click')
		await flushPromises()

		expect(wrapper.find('.widget-form').exists()).toBe(true)
		expect(wrapper.vm.form).toEqual({
			title: '',
			pipeline: { id: 'p1', label: 'Sales', stages: [{ name: 'Won', order: 2 }, { name: 'New', order: 1 }] },
			value: '',
			source: null,
		})
		expect(wrapper.vm.successLink).toBe('')
		expect(wrapper.vm.pipelineOptions[1].label).toBe('Unnamed pipeline')
	})

	it('logs a failed start', async () => {
		stores.fail = true
		await mountWidget(CreateLead)

		expect(console.error).toHaveBeenCalledWith('CreateLeadWidget init error:', expect.any(Error))
	})
})
