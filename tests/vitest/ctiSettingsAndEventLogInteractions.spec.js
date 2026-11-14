// @vitest-environment jsdom
/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 * SPDX-License-Identifier: EUPL-1.2
 *
 * The CTI admin sections react to what the admin does: the event log reloads
 * when a filter changes, shows that it is reloading, reports a failed load and
 * opens and closes an event's payload; the CTI settings form sends every field
 * the admin edited and selects stored values through each option's value.
 *
 * @spec openspec/changes/archive/2026-06-14-cti-screenpop-adapter/tasks.md#task-5.5
 */

import { flushPromises, mount } from '@vue/test-utils'
import { beforeEach, describe, expect, it, vi } from 'vitest'
import { h } from 'vue'

const ctiMock = vi.hoisted(() => ({
	getEventLog: vi.fn(),
	getConfig: vi.fn(),
	updateConfig: vi.fn(),
	testConnection: vi.fn(),
}))
const dialogsMock = vi.hoisted(() => ({ showError: vi.fn(), showSuccess: vi.fn() }))

vi.mock('../../src/services/ctiApi.js', () => ctiMock)
vi.mock('@nextcloud/dialogs', () => dialogsMock)
vi.mock('../../src/dialogs/CtiPayloadDialog.vue', () => ({
	default: {
		name: 'CtiPayloadDialog',
		props: ['payload'],
		emits: ['close'],
		render() {
			return h('div', { 'data-testid': 'payload-dialog' }, [
				String(this.payload),
				h('button', { 'data-testid': 'payload-close', onClick: () => this.$emit('close') }),
			])
		},
	},
}))

/**
 * A field stub that reports its label and lets a test emit a new value.
 *
 * @param {string} name The component name.
 * @return {object} The stub.
 */
function field(name) {
	return {
		name,
		inheritAttrs: false,
		props: ['modelValue', 'options', 'inputLabel', 'label', 'reduce', 'disabled'],
		emits: ['update:modelValue'],
		render() {
			return h('div', { 'data-field': this.inputLabel || this.label }, this.$slots.default?.())
		},
	}
}

vi.mock('@nextcloud/vue', () => ({
	NcButton: {
		name: 'NcButton',
		props: ['disabled', 'variant'],
		emits: ['click'],
		render() {
			return h('button', { disabled: this.disabled, onClick: () => this.$emit('click') }, [
				this.$slots.icon?.(),
				this.$slots.default?.(),
			])
		},
	},
	NcLoadingIcon: { name: 'NcLoadingIcon', render: () => h('span', { 'data-testid': 'loading' }) },
	NcSelect: field('NcSelect'),
	NcTextField: field('NcTextField'),
	NcCheckboxRadioSwitch: field('NcCheckboxRadioSwitch'),
	NcSettingsSection: {
		name: 'NcSettingsSection',
		render() {
			return h('section', this.$slots.default?.())
		},
	},
}))

globalThis.t = (app, text, vars = {}) =>
	String(text).replace(/\{(\w+)\}/g, (whole, key) => vars[key] ?? whole)

const mountOptions = { global: { mocks: { t: globalThis.t } } }

const { default: CtiEventLog } = await import('../../src/views/settings/CtiEventLog.vue')
const { default: CtiSettings } = await import('../../src/views/settings/CtiSettings.vue')

/**
 * The field stub carrying a label.
 *
 * @param {object} wrapper The mounted section.
 * @param {string} label The field's label.
 * @return {object} The stub's wrapper.
 */
function fieldLabelled(wrapper, label) {
	return wrapper
		.findAllComponents({ name: 'NcSelect' })
		.concat(wrapper.findAllComponents({ name: 'NcTextField' }))
		.concat(wrapper.findAllComponents({ name: 'NcCheckboxRadioSwitch' }))
		.find((stub) => (stub.props('inputLabel') || stub.props('label')) === label
			|| stub.text() === label)
}

beforeEach(() => {
	vi.clearAllMocks()
})

describe('CTI event log', () => {
	it('reloads with the chosen platform and event type, each reduced to its value', async () => {
		ctiMock.getEventLog.mockResolvedValue({ events: [] })
		const wrapper = mount(CtiEventLog, mountOptions)
		await flushPromises()
		expect(ctiMock.getEventLog).toHaveBeenCalledTimes(1)

		const platform = fieldLabelled(wrapper, 'Platform')
		expect(platform.props('reduce')({ value: 'asterisk', label: 'Asterisk' })).toBe('asterisk')
		platform.vm.$emit('update:modelValue', 'asterisk')
		await flushPromises()
		expect(ctiMock.getEventLog).toHaveBeenLastCalledWith({ platform: 'asterisk', event_type: '' })

		const eventType = fieldLabelled(wrapper, 'Event type')
		expect(eventType.props('reduce')({ value: 'ended', label: 'Ended' })).toBe('ended')
		eventType.vm.$emit('update:modelValue', 'ended')
		await flushPromises()
		expect(ctiMock.getEventLog).toHaveBeenLastCalledWith({ platform: 'asterisk', event_type: 'ended' })
		expect(ctiMock.getEventLog).toHaveBeenCalledTimes(3)
	})

	it('shows it is reloading and disables Reload until the log answers', async () => {
		let answer
		ctiMock.getEventLog.mockReturnValue(new Promise((resolve) => { answer = resolve }))
		const wrapper = mount(CtiEventLog, mountOptions)
		await wrapper.vm.$nextTick()

		const reload = wrapper.get('button')
		expect(reload.text()).toBe('Reloading…')
		expect(reload.attributes('disabled')).toBeDefined()
		expect(wrapper.find('[data-testid="loading"]').exists()).toBe(true)

		answer(null)
		await flushPromises()
		expect(reload.text()).toBe('Reload')
		expect(reload.attributes('disabled')).toBeUndefined()
		expect(wrapper.find('[data-testid="loading"]').exists()).toBe(false)
		expect(wrapper.text()).toContain('No webhook events in the selected range.')
	})

	it('reports a failed load with its message, or as a network error without one', async () => {
		ctiMock.getEventLog.mockRejectedValueOnce(new Error('HTTP 500'))
		const wrapper = mount(CtiEventLog, mountOptions)
		await flushPromises()
		expect(dialogsMock.showError).toHaveBeenLastCalledWith('Failed to load event log: HTTP 500')

		ctiMock.getEventLog.mockRejectedValueOnce({})
		await wrapper.get('button').trigger('click')
		await flushPromises()
		expect(dialogsMock.showError).toHaveBeenLastCalledWith('Failed to load event log: network error')
		expect(wrapper.get('button').text()).toBe('Reload')
	})

	it('opens an event\'s payload and closes it again', async () => {
		ctiMock.getEventLog.mockResolvedValue({
			events: [{ id: 'e1', platform: 'callvoip', event_type: 'ringing', payload_json: '{"from":"+31"}' }],
		})
		const wrapper = mount(CtiEventLog, mountOptions)
		await flushPromises()
		expect(wrapper.find('[data-testid="payload-dialog"]').exists()).toBe(false)

		await wrapper.get('[data-testid="cti-event"] button').trigger('click')
		expect(wrapper.get('[data-testid="payload-dialog"]').text()).toBe('{"from":"+31"}')

		await wrapper.get('[data-testid="payload-close"]').trigger('click')
		expect(wrapper.find('[data-testid="payload-dialog"]').exists()).toBe(false)
	})
})

describe('CTI settings', () => {
	it('sends every field the admin edited', async () => {
		ctiMock.getConfig.mockResolvedValue({})
		ctiMock.updateConfig.mockImplementation(async (config) => ({ ...config }))
		const wrapper = mount(CtiSettings, mountOptions)
		await flushPromises()

		const edits = {
			Platform: 'asterisk',
			'API base URL': 'https://pbx.example.nl/api',
			'Auth method': 'oauth',
			'OpenConnector credential reference': 'pbx-source',
			'Default outbound caller ID': '+31201234567',
			'Screen-pop delay (ms)': 250,
			'Default country code (ISO-3166)': 'BE',
			'Enable inbound screen-pop': false,
			'Enable outbound click-to-dial': false,
		}
		for (const [label, value] of Object.entries(edits)) {
			fieldLabelled(wrapper, label).vm.$emit('update:modelValue', value)
		}
		await wrapper.get('form').trigger('submit')
		await flushPromises()

		expect(ctiMock.updateConfig).toHaveBeenCalledWith({
			platform: 'asterisk',
			api_base_url: 'https://pbx.example.nl/api',
			auth_method: 'oauth',
			credentials_ref: 'pbx-source',
			screen_pop_enabled: false,
			screen_pop_delay_ms: 250,
			click_to_dial_enabled: false,
			default_outbound_caller_id: '+31201234567',
			default_country_code: 'BE',
		})
		expect(dialogsMock.showSuccess).toHaveBeenCalledWith('CTI configuration saved.')
	})

	it('selects platform and auth method through the option value', async () => {
		ctiMock.getConfig.mockResolvedValue({})
		const wrapper = mount(CtiSettings, mountOptions)
		await flushPromises()

		const platform = fieldLabelled(wrapper, 'Platform')
		const auth = fieldLabelled(wrapper, 'Auth method')
		expect(platform.props('reduce')({ value: 'ringcentral', label: 'RingCentral' })).toBe('ringcentral')
		expect(auth.props('reduce')({ value: 'api_key', label: 'API key' })).toBe('api_key')
		expect(auth.props('options').map((option) => option.value)).toEqual(['basic', 'oauth', 'api_key', 'webhook-secret'])
	})
})
