// @vitest-environment jsdom
/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 * SPDX-License-Identifier: EUPL-1.2
 *
 * MessagingSettings, driven through its controls: the provider row buttons
 * (copy webhook URL, test connection, edit, delete with confirmation), the
 * provider and budget forms written field by field, the budget row's inline
 * inputs, the refresh buttons and the service account picker's sentences.
 *
 * @spec openspec/changes/archive/2026-07-06-outbound-messaging-provider-wiring/tasks.md#task-4.1
 */

import { flushPromises, mount } from '@vue/test-utils'
import { beforeEach, describe, expect, it, vi } from 'vitest'
import { h } from 'vue'

const storeMock = vi.hoisted(() => ({
	fetchCollection: vi.fn(),
	saveObject: vi.fn(),
	deleteObject: vi.fn(),
}))
const axiosMock = vi.hoisted(() => ({ get: vi.fn(), post: vi.fn() }))
const dialogs = vi.hoisted(() => ({ showError: vi.fn(), showSuccess: vi.fn() }))

vi.mock('@nextcloud/axios', () => ({ default: axiosMock }))
vi.mock('@nextcloud/dialogs', () => dialogs)
vi.mock('@nextcloud/router', () => ({
	generateUrl: (path, params = {}) =>
		'/index.php' + path.replace(/\{(\w+)\}/g, (whole, key) => params[key] ?? whole),
}))
vi.mock('../../src/store/modules/object.js', () => ({ useObjectStore: () => storeMock }))
vi.mock('../../src/components/admin/ServiceAccountPicker.vue', () => ({
	default: {
		name: 'ServiceAccountPicker',
		props: ['url', 'help', 'savedMessage', 'consequence', 'legend'],
		render: () => h('div'),
	},
}))
vi.mock('../../src/dialogs/ConfirmDialog.vue', () => ({
	default: {
		name: 'ConfirmDialog',
		props: ['name', 'message', 'confirmLabel'],
		emits: ['confirm', 'cancel'],
		render() {
			return h('div', { 'data-stub': 'ConfirmDialog' }, this.message)
		},
	},
}))

/**
 * A stand-in control that keeps its props and renders its label and slot.
 *
 * @param {string} name The component name.
 * @param {string} tag  The element to render.
 * @return {object} The component.
 */
function stub(name, tag = 'div') {
	return {
		name,
		inheritAttrs: false,
		props: ['modelValue', 'options', 'label', 'inputLabel', 'ariaLabelCombobox', 'name', 'disabled', 'reduce', 'variant', 'type'],
		emits: ['update:modelValue', 'click'],
		render() {
			return h(
				tag,
				{ 'data-stub': name, 'data-label': this.inputLabel || this.ariaLabelCombobox || this.$attrs['aria-label'] || this.label },
				[this.$slots.icon?.(), this.$slots.default?.()],
			)
		},
	}
}

vi.mock('@nextcloud/vue', () => ({
	NcButton: stub('NcButton', 'button'),
	NcCheckboxRadioSwitch: stub('NcCheckboxRadioSwitch'),
	NcEmptyContent: stub('NcEmptyContent'),
	NcLoadingIcon: stub('NcLoadingIcon'),
	NcSelect: stub('NcSelect'),
	NcSettingsSection: stub('NcSettingsSection', 'section'),
	NcTextField: stub('NcTextField'),
}))

globalThis.t = (app, text, vars = {}) =>
	String(text).replace(/\{(\w+)\}/g, (whole, key) => vars[key] ?? whole)

const { default: MessagingSettings } =
	await import('../../src/views/settings/MessagingSettings.vue')

const PROVIDER = { id: 'p1', displayName: 'Twilio SMS', kind: 'sms', vendor: 'twilio', sourceId: 'twilio-sms', priority: 5, active: true }
const BUDGET = { id: 'b1', providerId: 'p1', period: 'monthly', maxMessages: 100, maxCostEur: 10, alertThresholdPct: 0.8, hardStop: false, currentPeriodMessages: 90 }

/**
 * Answer each collection the page reads.
 *
 * @param {object} collections Rows per schema.
 * @return {void}
 */
function answer(collections) {
	storeMock.fetchCollection.mockImplementation((schema) => Promise.resolve(collections[schema] ?? []))
}

/**
 * Mount the page and let its three reads settle.
 *
 * @return {Promise<object>} The wrapper.
 */
async function page() {
	const wrapper = mount(MessagingSettings, { global: { mocks: { t: globalThis.t } } })
	await flushPromises()
	return wrapper
}

/**
 * The button whose text is the given label, within an optional root.
 *
 * @param {object} root  The wrapper to search.
 * @param {string} label The button text.
 * @param {number} index Which match.
 * @return {object} The button component.
 */
function button(root, label, index = 0) {
	const found = root.findAllComponents({ name: 'NcButton' }).filter((b) => b.text().trim() === label || b.attributes('data-label') === label)
	if (!found[index]) {
		throw new Error(`no button "${label}"`)
	}
	return found[index]
}

/**
 * The control of the given kind carrying the given label.
 *
 * @param {object} root  The wrapper.
 * @param {string} name  The control's component name.
 * @param {string} label Its label.
 * @param {number} index Which match.
 * @return {object} The control.
 */
function control(root, name, label, index = 0) {
	const found = root.findAllComponents({ name }).filter((c) => c.attributes('data-label') === label)
	if (!found[index]) {
		throw new Error(`no ${name} "${label}"`)
	}
	return found[index]
}

beforeEach(() => {
	vi.clearAllMocks()
	answer({ channelProvider: [PROVIDER], messageSendBudget: [BUDGET], messageTemplate: [] })
	storeMock.saveObject.mockResolvedValue({})
	storeMock.deleteObject.mockResolvedValue(true)
})

describe('MessagingSettings provider rows', () => {
	it('copies the webhook URL, and says so when the clipboard refuses', async () => {
		const writeText = vi.fn().mockResolvedValue(undefined)
		Object.defineProperty(navigator, 'clipboard', { value: { writeText }, configurable: true })
		const wrapper = await page()

		button(wrapper, 'Copy webhook URL').vm.$emit('click')
		await flushPromises()
		expect(writeText).toHaveBeenCalledWith(window.location.origin + '/index.php/apps/pipelinq/api/messaging-webhooks/sms/p1')
		expect(dialogs.showSuccess).toHaveBeenCalledWith('Webhook URL copied to clipboard.')

		writeText.mockRejectedValueOnce(new Error('denied'))
		button(wrapper, 'Copy webhook URL').vm.$emit('click')
		await flushPromises()
		expect(dialogs.showError).toHaveBeenCalledWith('Could not copy the webhook URL.')
	})

	it('tests the connection and shows the outcome on the row', async () => {
		axiosMock.post.mockResolvedValueOnce({ data: { reachable: false, cause: 'auth' } })
		const wrapper = await page()

		button(wrapper, 'Test connection').vm.$emit('click')
		await flushPromises()

		expect(axiosMock.post).toHaveBeenCalledWith('/index.php/apps/pipelinq/api/messaging/providers/p1/test')
		expect(wrapper.find('.messaging-settings__test-result--error').text()).toBe('Degraded: auth')
	})

	it('opens the form filled with the provider to edit it', async () => {
		const wrapper = await page()

		button(wrapper, 'Edit').vm.$emit('click')
		await wrapper.vm.$nextTick()

		expect(wrapper.find('.messaging-settings__form-panel h3').text()).toBe('Edit provider')
		expect(control(wrapper, 'NcTextField', 'Display name').props('modelValue')).toBe('Twilio SMS')
		expect(control(wrapper, 'NcTextField', 'OpenConnector source ID').props('modelValue')).toBe('twilio-sms')
	})

	it('deletes a provider only after the confirmation, and keeps it on cancel', async () => {
		const wrapper = await page()

		button(wrapper, 'Delete').vm.$emit('click')
		await wrapper.vm.$nextTick()
		let dialog = wrapper.findComponent({ name: 'ConfirmDialog' })
		expect(dialog.props('message')).toBe('Delete provider "Twilio SMS"?')
		dialog.vm.$emit('cancel')
		await wrapper.vm.$nextTick()
		expect(wrapper.findComponent({ name: 'ConfirmDialog' }).exists()).toBe(false)
		expect(storeMock.deleteObject).not.toHaveBeenCalled()

		button(wrapper, 'Delete').vm.$emit('click')
		await wrapper.vm.$nextTick()
		dialog = wrapper.findComponent({ name: 'ConfirmDialog' })
		dialog.vm.$emit('confirm')
		await flushPromises()
		expect(storeMock.deleteObject).toHaveBeenCalledWith('channelProvider', 'p1')
		expect(dialogs.showSuccess).toHaveBeenCalledWith('Provider deleted.')
	})
})

describe('MessagingSettings provider form', () => {
	it('writes a new provider field by field and saves it', async () => {
		const wrapper = await page()
		button(wrapper, 'Add provider').vm.$emit('click')
		await wrapper.vm.$nextTick()

		const kind = control(wrapper, 'NcSelect', 'Kind')
		const vendor = control(wrapper, 'NcSelect', 'Vendor')
		expect(kind.props('reduce')({ value: 'whatsapp-bsp', label: 'WhatsApp (BSP)' })).toBe('whatsapp-bsp')
		expect(vendor.props('reduce')({ value: 'meta', label: 'Meta' })).toBe('meta')

		control(wrapper, 'NcTextField', 'Display name').vm.$emit('update:modelValue', 'Meta WhatsApp')
		kind.vm.$emit('update:modelValue', 'whatsapp-cloud-api')
		vendor.vm.$emit('update:modelValue', 'meta')
		control(wrapper, 'NcTextField', 'OpenConnector source ID').vm.$emit('update:modelValue', 'meta-wa')
		control(wrapper, 'NcTextField', 'Phone number / account ID').vm.$emit('update:modelValue', '+31600000001')
		control(wrapper, 'NcTextField', 'Webhook secret').vm.$emit('update:modelValue', 's3cret')
		control(wrapper, 'NcTextField', 'Priority (lower wins failover)').vm.$emit('update:modelValue', '2')
		const [active, sandbox] = wrapper.find('.messaging-settings__form-panel').findAllComponents({ name: 'NcCheckboxRadioSwitch' })
		active.vm.$emit('update:modelValue', false)
		sandbox.vm.$emit('update:modelValue', true)
		await wrapper.vm.$nextTick()

		button(wrapper.find('.messaging-settings__form-panel'), 'Save').vm.$emit('click')
		await flushPromises()

		expect(storeMock.saveObject).toHaveBeenCalledWith('channelProvider', {
			id: null,
			displayName: 'Meta WhatsApp',
			kind: 'whatsapp-cloud-api',
			vendor: 'meta',
			sourceId: 'meta-wa',
			phoneNumber: '+31600000001',
			webhookSecret: 's3cret',
			priority: 2,
			active: false,
			sandbox: true,
		})
		expect(wrapper.find('.messaging-settings__form-panel').exists()).toBe(false)
	})

	it('closes the form on Cancel without saving', async () => {
		const wrapper = await page()
		button(wrapper, 'Add provider').vm.$emit('click')
		await wrapper.vm.$nextTick()

		button(wrapper.find('.messaging-settings__form-panel'), 'Cancel').vm.$emit('click')
		await wrapper.vm.$nextTick()

		expect(wrapper.find('.messaging-settings__form-panel').exists()).toBe(false)
		expect(storeMock.saveObject).not.toHaveBeenCalled()
	})
})

describe('MessagingSettings budgets', () => {
	it('edits a budget row inline and saves it', async () => {
		const wrapper = await page()
		const row = wrapper.find('[data-testid="messaging-budget"]')

		const period = control(row, 'NcSelect', 'Period')
		expect(period.props('reduce')({ value: 'weekly' })).toBe('weekly')
		period.vm.$emit('update:modelValue', 'weekly')
		await row.find('input[aria-label="Max messages"]').setValue('200')
		await row.find('input[aria-label="Max cost (EUR)"]').setValue('25.5')
		await row.find('input[aria-label="Alert threshold (0-1)"]').setValue('0.9')
		row.findComponent({ name: 'NcCheckboxRadioSwitch' }).vm.$emit('update:modelValue', true)
		await wrapper.vm.$nextTick()

		button(row, 'Save').vm.$emit('click')
		await flushPromises()

		expect(storeMock.saveObject).toHaveBeenCalledWith('messageSendBudget', expect.objectContaining({
			id: 'b1', period: 'weekly', maxMessages: 200, maxCostEur: 25.5, alertThresholdPct: 0.9, hardStop: true,
		}))
		expect(dialogs.showSuccess).toHaveBeenCalledWith('Budget saved.')
	})

	it('deletes a budget after confirmation', async () => {
		const wrapper = await page()
		button(wrapper.find('[data-testid="messaging-budget"]'), 'Delete').vm.$emit('click')
		await wrapper.vm.$nextTick()

		const dialog = wrapper.findComponent({ name: 'ConfirmDialog' })
		expect(dialog.props('message')).toBe('Delete this send budget?')
		dialog.vm.$emit('cancel')
		await wrapper.vm.$nextTick()
		expect(wrapper.findComponent({ name: 'ConfirmDialog' }).exists()).toBe(false)

		button(wrapper.find('[data-testid="messaging-budget"]'), 'Delete').vm.$emit('click')
		await wrapper.vm.$nextTick()
		wrapper.findComponent({ name: 'ConfirmDialog' }).vm.$emit('confirm')
		await flushPromises()
		expect(storeMock.deleteObject).toHaveBeenCalledWith('messageSendBudget', 'b1')
	})

	it('creates a budget from the form, and Cancel discards it', async () => {
		const wrapper = await page()
		button(wrapper, 'Add budget').vm.$emit('click')
		await wrapper.vm.$nextTick()
		const form = () => wrapper.find('.messaging-settings__form-panel')

		const provider = control(form(), 'NcSelect', 'Provider')
		const period = control(form(), 'NcSelect', 'Period')
		expect(provider.props('options')).toEqual([{ value: 'p1', label: 'Twilio SMS' }])
		expect(provider.props('reduce')({ value: 'p1' })).toBe('p1')
		expect(period.props('reduce')({ value: 'daily' })).toBe('daily')
		control(form(), 'NcTextField', 'Tenant ID').vm.$emit('update:modelValue', 'tenant-a')
		provider.vm.$emit('update:modelValue', 'p1')
		period.vm.$emit('update:modelValue', 'daily')
		control(form(), 'NcTextField', 'Max messages (0 = no cap)').vm.$emit('update:modelValue', '50')
		control(form(), 'NcTextField', 'Max cost EUR (0 = no cap)').vm.$emit('update:modelValue', '5')
		form().findComponent({ name: 'NcCheckboxRadioSwitch' }).vm.$emit('update:modelValue', true)
		await wrapper.vm.$nextTick()

		button(form(), 'Save').vm.$emit('click')
		await flushPromises()
		expect(storeMock.saveObject).toHaveBeenCalledWith('messageSendBudget', {
			tenantId: 'tenant-a', providerId: 'p1', period: 'daily', maxMessages: 50, maxCostEur: 5, hardStop: true,
		})
		expect(form().exists()).toBe(false)

		button(wrapper, 'Add budget').vm.$emit('click')
		await wrapper.vm.$nextTick()
		button(form(), 'Cancel').vm.$emit('click')
		await wrapper.vm.$nextTick()
		expect(form().exists()).toBe(false)
		expect(storeMock.saveObject).toHaveBeenCalledTimes(1)
	})
})

describe('MessagingSettings refresh and picker', () => {
	it('refreshes each list from its own button', async () => {
		const wrapper = await page()
		storeMock.fetchCollection.mockClear()

		button(wrapper, 'Refresh', 0).vm.$emit('click')
		button(wrapper, 'Refresh', 1).vm.$emit('click')
		button(wrapper, 'Refresh', 2).vm.$emit('click')
		await flushPromises()

		expect(storeMock.fetchCollection.mock.calls.map((c) => c[0])).toEqual(['channelProvider', 'messageSendBudget', 'messageTemplate'])
	})

	it('gives the service account picker its sentences with the group and user filled in', async () => {
		const wrapper = await page()
		const picker = wrapper.findComponent({ name: 'ServiceAccountPicker' })

		expect(picker.props('url')).toBe('/index.php/apps/pipelinq/api/messaging/service-account')
		expect(picker.props('help')('pipelinq-messaging-service')).toContain('It joins the group pipelinq-messaging-service,')
		expect(picker.props('savedMessage')('svc-sms')).toBe('Incoming messages are now saved as svc-sms.')
	})
})
