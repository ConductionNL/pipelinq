// @vitest-environment jsdom
/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 * SPDX-License-Identifier: EUPL-1.2
 *
 * The prospect (ideal customer profile) section, mounted: it shows a loading
 * icon until the stored profile arrives, every field the admin edits reaches
 * the saved profile, and the result of a save is shown as a note.
 *
 * @spec openspec/changes/archive/2026-05-31-reverse-2026-05-26-fe-settings-ui/tasks.md#task-65
 */

import { flushPromises, mount } from '@vue/test-utils'
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import { h } from 'vue'

vi.mock('@nextcloud/router', () => ({ generateUrl: (path) => '/index.php' + path }))
vi.mock('@conduction/nextcloud-vue', () => ({
	CnSettingsSection: {
		name: 'CnSettingsSection',
		render() {
			return h('section', this.$slots.default?.())
		},
	},
}))

/**
 * A field stub keyed by its id or aria label.
 *
 * @param {string} name The component name.
 * @return {object} The stub.
 */
function field(name) {
	return {
		name,
		inheritAttrs: false,
		props: ['modelValue', 'options', 'label', 'ariaLabelCombobox'],
		emits: ['update:modelValue'],
		render() {
			return h('div')
		},
	}
}

vi.mock('@nextcloud/vue', () => ({
	NcButton: {
		name: 'NcButton',
		props: ['disabled'],
		emits: ['click'],
		render() {
			return h('button', { disabled: this.disabled, onClick: () => this.$emit('click') }, this.$slots.default?.())
		},
	},
	NcLoadingIcon: { name: 'NcLoadingIcon', render: () => h('span', { 'data-testid': 'loading' }) },
	NcNoteCard: {
		name: 'NcNoteCard',
		props: ['type'],
		render() {
			return h('div', { 'data-testid': 'note', 'data-type': this.type }, this.$slots.default?.())
		},
	},
	NcSelect: field('NcSelect'),
	NcTextField: field('NcTextField'),
}))

globalThis.t = (app, text) => text
globalThis.OC = { requestToken: 'tok' }

const mountOptions = { global: { mocks: { t: globalThis.t } } }

const { default: ProspectSettings } = await import('../../src/views/settings/ProspectSettings.vue')

let answers
let requests

beforeEach(() => {
	answers = []
	requests = []
	vi.stubGlobal('fetch', vi.fn(async (url, init) => {
		requests.push({ url, init })
		const next = answers.shift()
		if (next instanceof Error) {
			throw next
		}
		return { ok: next.ok, json: async () => next.body }
	}))
})

afterEach(() => {
	vi.unstubAllGlobals()
})

/**
 * The text field stub with a label.
 *
 * @param {object} wrapper The mounted section.
 * @param {string} label The label.
 * @return {object} The stub's wrapper.
 */
function textField(wrapper, label) {
	return wrapper.findAllComponents({ name: 'NcTextField' }).find((stub) => stub.props('label') === label)
}

/**
 * The select stub with an aria label.
 *
 * @param {object} wrapper The mounted section.
 * @param {string} label The aria label.
 * @return {object} The stub's wrapper.
 */
function select(wrapper, label) {
	return wrapper.findAllComponents({ name: 'NcSelect' }).find((stub) => stub.props('ariaLabelCombobox') === label)
}

describe('ProspectSettings mounted', () => {
	it('shows a loading icon until the stored profile arrives, then the form', async () => {
		let release
		vi.stubGlobal('fetch', vi.fn(() => new Promise((resolve) => { release = resolve })))
		const wrapper = mount(ProspectSettings, mountOptions)
		await wrapper.vm.$nextTick()

		expect(wrapper.find('[data-testid="loading"]').exists()).toBe(true)
		expect(wrapper.find('button').exists()).toBe(false)

		release({ ok: true, json: async () => ({ sbiCodes: ['62'], employeeCountMin: 5 }) })
		await flushPromises()

		expect(wrapper.find('[data-testid="loading"]').exists()).toBe(false)
		expect(textField(wrapper, 'SBI Codes').props('modelValue')).toBe('62')
		expect(textField(wrapper, 'Min Employees').props('modelValue')).toBe('5')
	})

	it('saves every field the admin edited', async () => {
		answers.push({ ok: false, body: {} }, { ok: true, body: {} })
		const wrapper = mount(ProspectSettings, mountOptions)
		await flushPromises()

		textField(wrapper, 'SBI Codes').vm.$emit('update:modelValue', '62, 72 ,')
		textField(wrapper, 'Min Employees').vm.$emit('update:modelValue', '10')
		textField(wrapper, 'Max Employees').vm.$emit('update:modelValue', '250')
		select(wrapper, 'Provinces').vm.$emit('update:modelValue', ['Utrecht'])
		select(wrapper, 'Legal Forms').vm.$emit('update:modelValue', ['BV', 'NV'])
		textField(wrapper, 'Keywords').vm.$emit('update:modelValue', 'software, IT')
		textField(wrapper, 'KVK API Key').vm.$emit('update:modelValue', 'kvk-key-1')
		await wrapper.get('#exclude-inactive').setValue(false)
		await wrapper.get('#oc-enabled').setValue(true)

		await wrapper.get('button').trigger('click')
		await flushPromises()

		expect(requests[1].init.method).toBe('PUT')
		expect(JSON.parse(requests[1].init.body)).toEqual({
			employeeCountMin: 10,
			employeeCountMax: 250,
			provinces: ['Utrecht'],
			legalForms: ['BV', 'NV'],
			excludeInactive: false,
			kvkApiKey: 'kvk-key-1',
			openCorporatesEnabled: true,
			sbiCodes: ['62', '72'],
			keywords: ['software', 'IT'],
		})
		const note = wrapper.get('[data-testid="note"]')
		expect(note.text()).toBe('ICP settings saved successfully')
		expect(note.attributes('data-type')).toBe('success')
	})

	it('shows the failure as an error note and re-enables Save', async () => {
		answers.push({ ok: false, body: {} })
		const wrapper = mount(ProspectSettings, mountOptions)
		await flushPromises()

		let release
		vi.stubGlobal('fetch', vi.fn(() => new Promise((resolve) => { release = resolve })))
		await wrapper.get('button').trigger('click')
		expect(wrapper.get('button').text()).toBe('Saving…')
		expect(wrapper.get('button').attributes('disabled')).toBeDefined()
		release({ ok: false, json: async () => ({}) })
		await flushPromises()

		const note = wrapper.get('[data-testid="note"]')
		expect(note.text()).toBe('Failed to save ICP settings')
		expect(note.attributes('data-type')).toBe('error')
		expect(wrapper.get('button').text()).toBe('Save ICP Settings')
		expect(wrapper.get('button').attributes('disabled')).toBeUndefined()
	})
})
