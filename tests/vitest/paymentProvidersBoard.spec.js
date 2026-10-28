// @vitest-environment jsdom
/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 * SPDX-License-Identifier: EUPL-1.2
 *
 * The payment providers card as the board draws it: one compact row per
 * provider (name, kind and environment, an enable switch) and the settings
 * form of one provider at a time, instead of a full form per provider.
 *
 * @spec openspec/changes/pos-payment-provider-adapter/specs/pos-payment-provider-adapter/spec.md#REQ-PAY-007
 */

import { flushPromises, mount } from '@vue/test-utils'
import { describe, expect, it, vi } from 'vitest'
import { h } from 'vue'

const api = vi.hoisted(() => ({
	listProviders: vi.fn(),
	updateProvider: vi.fn(),
	testConnection: vi.fn(),
}))
vi.mock('../../src/services/posPaymentApi.js', () => api)
vi.mock('@nextcloud/axios', () => ({ default: { get: vi.fn().mockResolvedValue({ data: { results: [] } }) } }))
vi.mock('@nextcloud/router', () => ({ generateUrl: (p) => p }))
vi.mock('@nextcloud/dialogs', () => ({ showError: vi.fn(), showSuccess: vi.fn() }))

/**
 * A stub that renders its default slot and keeps its props readable.
 *
 * @param {string} name The component name.
 * @param {string} tag The element to render.
 * @return {object} The stub.
 */
function stub(name, tag = 'div') {
	return {
		name,
		inheritAttrs: false,
		props: ['modelValue', 'name', 'description', 'type', 'variant', 'disabled', 'inputLabel', 'label'],
		emits: ['update:modelValue', 'click'],
		render() {
			return h(tag, { 'data-stub': name, 'data-type': this.type, onClick: () => this.$emit('click') }, [
				this.name,
				this.$slots.default?.(),
			])
		},
	}
}

vi.mock('@nextcloud/vue', () => ({
	NcButton: stub('NcButton', 'button'),
	NcCheckboxRadioSwitch: stub('NcCheckboxRadioSwitch'),
	NcLoadingIcon: stub('NcLoadingIcon'),
	NcNoteCard: stub('NcNoteCard'),
	NcSelect: stub('NcSelect'),
	NcSettingsSection: stub('NcSettingsSection', 'section'),
	NcTextField: stub('NcTextField'),
}))

globalThis.t = (app, text, vars = {}) =>
	String(text).replace(/\{(\w+)\}/g, (whole, key) => vars[key] ?? whole)

const { default: PaymentSettingsForm } =
	await import('../../src/views/settings/PaymentSettingsForm.vue')

const PROVIDERS = [
	{ name: 'mollie', displayName: 'Mollie', type: 'online', environment: 'sandbox', isActive: false, testMode: true, config: {} },
	{ name: 'ccv', displayName: 'CCV', type: 'terminal', environment: 'live', isActive: true, testMode: false, config: { terminalId: 'register-01' } },
	{ name: 'adyen', displayName: 'Adyen', type: 'terminal', environment: 'sandbox', isActive: false, testMode: true, config: {} },
]

/**
 * Mount the card once the providers arrived.
 *
 * @return {Promise<object>} The wrapper.
 */
async function mountCard() {
	api.listProviders.mockResolvedValue(JSON.parse(JSON.stringify(PROVIDERS)))
	api.updateProvider.mockImplementation(async (name, payload) => ({ ...PROVIDERS.find((p) => p.name === name), ...payload }))
	const wrapper = mount(PaymentSettingsForm, { global: { mocks: { t: globalThis.t } } })
	await flushPromises()
	return wrapper
}

describe('the payment providers card', () => {
	it('lists every provider as one row with its kind and environment', async () => {
		const wrapper = await mountCard()
		const rows = wrapper.findAll('[data-testid="payment-provider-row"]')

		expect(rows.map((row) => row.find('strong').text())).toEqual(['Mollie', 'CCV', 'Adyen'])
		expect(rows[1].text()).toContain('PIN-terminal · Production')
		expect(rows[0].text()).toContain('Online · Sandbox')
		expect(rows.every((row) => row.find('[data-stub="NcCheckboxRadioSwitch"][data-type="switch"]').exists())).toBe(true)
	})

	it('shows the form of one provider, the first active one, at a time', async () => {
		const wrapper = await mountCard()
		const forms = wrapper.findAll('[data-testid="payment-provider-form"]')

		expect(forms).toHaveLength(1)
		expect(forms[0].find('h3').text()).toBe('CCV')
	})

	it('opens another provider\'s form from its row', async () => {
		const wrapper = await mountCard()
		await wrapper.findAll('[data-testid="payment-provider-select"]')[2].trigger('click')

		expect(wrapper.find('[data-testid="payment-provider-form"] h3').text()).toBe('Adyen')
	})

	it('saves a provider when its row switch flips', async () => {
		const wrapper = await mountCard()
		const toggle = wrapper.findAll('[data-testid="payment-provider-row"]')[0]
			.findComponent({ name: 'NcCheckboxRadioSwitch' })
		toggle.vm.$emit('update:modelValue', true)
		await flushPromises()

		expect(api.updateProvider).toHaveBeenCalledWith('mollie', expect.objectContaining({ isActive: true }))
	})
})
