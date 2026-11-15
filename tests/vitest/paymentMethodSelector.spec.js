// @vitest-environment jsdom
/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 * SPDX-License-Identifier: EUPL-1.2
 *
 * The payment-method dropdown at the till. A cashier is not an admin, and the
 * provider list it used to read was admin-only, so a cashier never saw a card
 * provider. It now reads the cashier-readable methods endpoint, keeps its
 * source strings in English, and words its hint by provider type, not by a
 * provider name written into the code.
 *
 * @spec openspec/specs/pos-payment-methods/spec.md#requirement-a-cashier-reads-the-payment-methods-of-the-active-providers
 */

import { flushPromises, mount } from '@vue/test-utils'
import { readFileSync } from 'node:fs'
import { resolve } from 'node:path'
import { beforeEach, describe, expect, it, vi } from 'vitest'
import { h } from 'vue'

const api = vi.hoisted(() => ({ listPaymentMethods: vi.fn(), listProviders: vi.fn() }))

vi.mock('../../src/services/posPaymentApi.js', () => api)
vi.mock('@nextcloud/vue', () => ({
	NcSelect: {
		name: 'NcSelect',
		props: ['modelValue', 'options', 'inputLabel', 'loading'],
		emits: ['update:modelValue'],
		render() {
			return h('ul', this.options.map((o) => h('li', { 'data-value': o.value }, o.label)))
		},
	},
}))

globalThis.t = (_app, text, vars = {}) =>
	text.replace(/\{(\w+)\}/g, (_all, key) => vars[key] ?? `{${key}}`)

const PaymentMethodSelector = (await import('../../src/components/pos/PaymentMethodSelector.vue')).default

const methods = [
	{ provider: 'mollie', providerLabel: 'Mollie', type: 'online', method: 'ideal' },
	{ provider: 'ccv', providerLabel: 'CCV', type: 'terminal', method: 'card' },
]

/**
 * Mount the selector.
 *
 * @param {object} props The props.
 * @return {Promise<object>} The wrapper, after the methods load.
 */
async function mountSelector(props = {}) {
	const wrapper = mount(PaymentMethodSelector, { props, global: { mocks: { t: globalThis.t } } })
	await flushPromises()
	return wrapper
}

beforeEach(() => {
	api.listPaymentMethods.mockReset()
	api.listProviders.mockReset()
})

describe('PaymentMethodSelector', () => {
	it('reads the cashier-readable methods, not the admin-only provider list', async () => {
		api.listPaymentMethods.mockResolvedValue(methods)
		const wrapper = await mountSelector()
		expect(api.listPaymentMethods).toHaveBeenCalledTimes(1)
		expect(api.listProviders).not.toHaveBeenCalled()
		const labels = wrapper.findAll('li').map((li) => li.text())
		expect(labels).toEqual(['Cash', 'Gift voucher', 'Mollie: iDEAL', 'CCV: Card'])
	})

	it('offers Account only with a client', async () => {
		api.listPaymentMethods.mockResolvedValue([])
		const wrapper = await mountSelector({ clientSelected: true })
		expect(wrapper.findAll('li').map((li) => li.attributes('data-value'))).toEqual(['cash', 'voucher', 'account'])
	})

	it('keeps the static tenders when the methods cannot be read', async () => {
		api.listPaymentMethods.mockRejectedValue(new Error('offline'))
		const wrapper = await mountSelector()
		expect(wrapper.findAll('li').map((li) => li.text())).toEqual(['Cash', 'Gift voucher'])
	})

	it('words the hint by provider type', async () => {
		api.listPaymentMethods.mockResolvedValue(methods)
		const online = await mountSelector({ modelValue: 'mollie:ideal' })
		expect(online.find('[data-testid="payment-method-hint"]').text()).toBe('The customer is sent to Mollie to finish the payment.')
		const terminal = await mountSelector({ modelValue: 'ccv:card' })
		expect(terminal.find('[data-testid="payment-method-hint"]').text()).toBe('The customer pays at the CCV card terminal.')
		const cash = await mountSelector({ modelValue: 'cash' })
		expect(cash.find('[data-testid="payment-method-hint"]').exists()).toBe(false)
	})

	it('emits the provider and the method of the choice', async () => {
		api.listPaymentMethods.mockResolvedValue(methods)
		const wrapper = await mountSelector()
		wrapper.findComponent({ name: 'NcSelect' }).vm.$emit('update:modelValue', 'mollie:ideal')
		expect(wrapper.emitted('change')[0][0]).toEqual({ providerName: 'mollie', paymentMethod: 'ideal', combined: 'mollie:ideal' })
	})

	it('has English source strings and no em-dash', () => {
		const source = readFileSync(resolve(__dirname, '../../src/components/pos/PaymentMethodSelector.vue'), 'utf8')
		expect(source).not.toMatch(/Contant|Cadeaubon|Rekening/)
		expect(source).not.toContain('—')
		expect(source).not.toMatch(/'mollie'|'ccv'/)
	})
})
