// @vitest-environment jsdom
/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 * SPDX-License-Identifier: EUPL-1.2
 *
 * The Payment received dialog (PqKassaAfrekenen) as the cashier drives it:
 * the buttons and forms of the dialog, a gift card that cannot be used or
 * cannot be checked, the same card twice, and removing a card again.
 *
 * @spec openspec/specs/pos-transactions/spec.md#requirement-a-payment-can-be-split
 */

import { flushPromises, mount } from '@vue/test-utils'
import { beforeEach, describe, expect, it, vi } from 'vitest'
import { h } from 'vue'

/**
 * A stub that renders its slots and keeps its props readable.
 *
 * @param {string} name The component name.
 * @param {string} [tag] The element it renders.
 * @return {object} The stub.
 */
function passthrough(name, tag = 'div') {
	return {
		name,
		inheritAttrs: false,
		props: ['name', 'eyebrow', 'label', 'modelValue', 'type', 'variant', 'disabled', 'ariaLabel'],
		emits: ['click', 'update:modelValue', 'closing'],
		render() {
			const slots = Object.entries(this.$slots).map(([key, fn]) => h('div', { 'data-slot': key }, fn?.()))
			return h(tag, { 'data-stub': name, class: this.$attrs.class }, slots)
		},
	}
}

vi.mock('@conduction/nextcloud-vue', () => ({ CnDialog: passthrough('CnDialog') }))
vi.mock('@nextcloud/vue', () => ({
	NcButton: passthrough('NcButton', 'button'),
	NcTextField: passthrough('NcTextField'),
}))
vi.mock('@nextcloud/router', () => ({ generateUrl: (url) => url }))
vi.mock('@nextcloud/l10n', () => ({ getLocale: () => 'nl', getLanguage: () => 'nl', translate: (app, text) => text }))
const post = vi.hoisted(() => vi.fn())
vi.mock('@nextcloud/axios', () => ({ default: { post } }))

globalThis.t = (app, text, vars = {}) => String(text).replace(/\{(\w+)\}/g, (whole, key) => vars[key] ?? whole)

const { default: PosPaymentDialog } = await import('../../src/dialogs/PosPaymentDialog.vue')

/**
 * Mount the dialog for a sale of € 96,41 by a customer with 340 points worth € 0,05 each.
 *
 * @return {object} The wrapper.
 */
function mountDialog() {
	return mount(PosPaymentDialog, {
		props: {
			total: 96.41,
			customerName: 'Sanne de Vries',
			loyalty: { accountId: 'acc-1', balance: 340, pointValue: 0.05 },
			restLabel: 'Card',
		},
		global: { mocks: { t: globalThis.t } },
	})
}

/**
 * The NcButton whose default slot reads this text.
 *
 * @param {object} wrapper The dialog wrapper.
 * @param {string} text The button text.
 * @return {object} The button component.
 */
function button(wrapper, text) {
	const found = wrapper.findAllComponents({ name: 'NcButton' }).find((candidate) => candidate.text().trim() === text)
	if (!found) {
		throw new Error(`No button "${text}"`)
	}
	return found
}

beforeEach(() => {
	post.mockReset()
})

describe('the payment received dialog, driven by the cashier', () => {
	it('puts the whole total on the rest while no points or gift cards are used', () => {
		const wrapper = mountDialog()
		expect(wrapper.vm.split.rest).toBe(96.41)
		expect(wrapper.vm.split.giftCards).toEqual([])
		expect(wrapper.vm.split.closes).toBe(true)
		expect(wrapper.find('[data-testid="pos-payment-note"]').classes()).toContain('pos-payment__note--closes')
	})

	it('uses the maximum points the account and the sale allow from the button, and reads typed points', async () => {
		const wrapper = mountDialog()
		button(wrapper, 'Use the maximum').vm.$emit('click')
		expect(wrapper.vm.points).toBe(340)
		const field = wrapper.findAllComponents({ name: 'NcTextField' })[0]
		field.vm.$emit('update:modelValue', '120')
		expect(wrapper.vm.points).toBe(120)
		field.vm.$emit('update:modelValue', 'not a number')
		expect(wrapper.vm.points).toBe(0)
		field.vm.$emit('update:modelValue', '99999')
		expect(wrapper.vm.points).toBe(340)
		field.vm.$emit('update:modelValue', '-5')
		expect(wrapper.vm.points).toBe(0)
	})

	it('opens the gift card form from a chip and closes it with the same chip', async () => {
		const wrapper = mountDialog()
		button(wrapper, 'Add gift card').vm.$emit('click')
		await flushPromises()
		expect(wrapper.vm.cardMode).toBe('add')
		expect(wrapper.findAll('form')).toHaveLength(1)
		button(wrapper, 'Check gift card balance').vm.$emit('click')
		expect(wrapper.vm.cardMode).toBe('check')
		button(wrapper, 'Check gift card balance').vm.$emit('click')
		await flushPromises()
		expect(wrapper.vm.cardMode).toBe('')
		expect(wrapper.findAll('form')).toHaveLength(0)
	})

	it('says why a gift card cannot be used, and adds nothing', async () => {
		post.mockResolvedValueOnce({ data: { valid: false, reason: 'expired' } })
		const wrapper = mountDialog()
		wrapper.vm.openCard('add')
		wrapper.vm.cardSerial = 'ZD-GC-1'
		await flushPromises()
		await wrapper.find('form').trigger('submit')
		await flushPromises()
		expect(post).toHaveBeenCalledWith('/apps/pipelinq/api/loyalty/gift-card/validate', { serial: 'ZD-GC-1', pin: '' })
		expect(wrapper.vm.cardMessage).toBe('This gift card cannot be used: expired')
		expect(wrapper.vm.giftCards).toHaveLength(0)
		expect(wrapper.vm.cardMode).toBe('add')
		expect(wrapper.vm.looking).toBe(false)
	})

	it('falls back to an unknown reason when the server gives none', async () => {
		post.mockResolvedValueOnce({ data: null })
		const wrapper = mountDialog()
		wrapper.vm.openCard('check')
		wrapper.vm.cardSerial = 'ZD-GC-2'
		await wrapper.vm.lookupCard()
		expect(wrapper.vm.cardMessage).toBe('This gift card cannot be used: unknown')
	})

	it('says the card could not be checked when the request fails, and stops looking', async () => {
		post.mockRejectedValueOnce(new Error('offline'))
		const wrapper = mountDialog()
		wrapper.vm.openCard('add')
		wrapper.vm.cardSerial = 'ZD-GC-3'
		await wrapper.vm.lookupCard()
		expect(wrapper.vm.cardMessage).toBe('The gift card could not be checked.')
		expect(wrapper.vm.looking).toBe(false)
		expect(wrapper.vm.giftCards).toHaveLength(0)
	})

	it('adds the same gift card once, pays with it, and removes it again', async () => {
		post.mockResolvedValue({ data: { valid: true, giftCardId: 'g1', balance: '30' } })
		const wrapper = mountDialog()
		for (let i = 0; i < 2; i++) {
			wrapper.vm.openCard('add')
			wrapper.vm.cardSerial = 'ZD-GC-4'
			wrapper.vm.cardPin = '9999'
			await wrapper.vm.lookupCard()
		}
		await flushPromises()
		expect(wrapper.vm.giftCards).toEqual([{ giftCardId: 'g1', serial: 'ZD-GC-4', pin: '9999', balance: 30, amount: 30, expiryDate: null }])
		expect(wrapper.findAll('[data-testid="pos-payment-gift-card"]')).toHaveLength(1)
		expect(wrapper.vm.giftCardMeta(wrapper.vm.split.giftCards[0])).not.toContain('valid until')

		wrapper.vm.confirm()
		const payment = wrapper.emitted('confirm')[0][0]
		expect(payment.points).toBe(0)
		expect(payment.giftCards).toHaveLength(1)
		expect(payment.giftCards[0].giftCardId).toBe('g1')
		expect(payment.rest).toBeCloseTo(66.41, 2)

		const remove = wrapper.findAllComponents({ name: 'NcButton' }).find((candidate) => candidate.props('ariaLabel') === 'Remove gift card ZD-GC-4')
		remove.vm.$emit('click')
		await flushPromises()
		expect(wrapper.vm.giftCards).toHaveLength(0)
		expect(wrapper.findAll('[data-testid="pos-payment-gift-card"]')).toHaveLength(0)
	})

	it('toggles the sell form from Sell gift card, and submitting it hands the amount over and closes it', async () => {
		const wrapper = mountDialog()
		button(wrapper, 'Sell gift card').vm.$emit('click')
		await flushPromises()
		expect(wrapper.vm.selling).toBe(true)
		wrapper.vm.sellAmount = '12.50'
		await wrapper.find('form').trigger('submit')
		expect(wrapper.emitted('sellGiftCard')[0][0]).toEqual({ amount: 12.5 })
		expect(wrapper.vm.selling).toBe(false)
		expect(wrapper.vm.sellAmount).toBe('')
	})

	it('closes from Cancel and from the dialog itself', () => {
		const wrapper = mountDialog()
		button(wrapper, 'Cancel').vm.$emit('click')
		wrapper.findComponent({ name: 'CnDialog' }).vm.$emit('closing')
		expect(wrapper.emitted('close')).toHaveLength(2)
	})
})
