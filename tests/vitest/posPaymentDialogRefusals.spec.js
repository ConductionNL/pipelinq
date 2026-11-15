// @vitest-environment jsdom
/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 * SPDX-License-Identifier: EUPL-1.2
 *
 * The Payment received dialog when a gift card is refused or cannot be
 * checked, when the same card is added twice, and how typed points are held
 * to what the account and the sale allow.
 *
 * @spec openspec/specs/pos-transactions/spec.md#requirement-a-payment-can-be-split
 */

import { mount } from '@vue/test-utils'
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
		props: ['name', 'eyebrow', 'label', 'modelValue', 'type', 'variant', 'disabled'],
		emits: ['click', 'update:modelValue', 'closing'],
		render() {
			const slots = Object.entries(this.$slots).map(([key, fn]) => h('div', { 'data-slot': key }, fn?.()))
			return h(tag, { 'data-stub': name }, slots)
		},
	}
}

vi.mock('@conduction/nextcloud-vue', () => ({ CnDialog: passthrough('CnDialog') }))
vi.mock('@nextcloud/vue', () => ({
	NcButton: passthrough('NcButton', 'button'),
	NcNoteCard: passthrough('NcNoteCard'),
	NcTextField: passthrough('NcTextField'),
}))
vi.mock('@nextcloud/router', () => ({ generateUrl: (url) => url }))
vi.mock('@nextcloud/l10n', () => ({ getLocale: () => 'nl', getLanguage: () => 'nl', translate: (app, text) => text }))
const post = vi.hoisted(() => vi.fn())
vi.mock('@nextcloud/axios', () => ({ default: { post } }))

globalThis.t = (app, text, vars = {}) => String(text).replace(/\{(\w+)\}/g, (whole, key) => vars[key] ?? whole)

const { default: PosPaymentDialog } = await import('../../src/dialogs/PosPaymentDialog.vue')

/**
 * Mount the dialog for a sale of € 96,41 by a customer with 340 points.
 *
 * @return {object} The wrapper.
 */
function mountDialog() {
	return mount(PosPaymentDialog, {
		props: {
			total: 96.41,
			customerName: 'Sanne de Vries',
			loyalty: { accountId: 'acc-1', balance: 340, pointValue: 0.05 },
		},
		global: { mocks: { t: globalThis.t } },
	})
}

/**
 * Open the add-card form and look a card up.
 *
 * @param {object} wrapper The dialog.
 * @return {Promise<void>}
 */
async function addCard(wrapper) {
	wrapper.vm.openCard('add')
	wrapper.vm.cardSerial = 'ZD-GC-7731'
	wrapper.vm.cardPin = '1234'
	await wrapper.vm.lookupCard()
}

describe('the payment received dialog, gift card refusals and points', () => {
	beforeEach(() => {
		post.mockReset()
	})

	it('says why a gift card cannot be used, and keeps the form open', async () => {
		post.mockResolvedValueOnce({ data: { valid: false, reason: 'expired' } })
		const wrapper = mountDialog()

		await addCard(wrapper)

		expect(wrapper.vm.cardMessage).toBe('This gift card cannot be used: expired')
		expect(wrapper.vm.giftCards).toEqual([])
		expect(wrapper.vm.cardMode).toBe('add')
		expect(wrapper.vm.cardSerial).toBe('ZD-GC-7731')
		expect(wrapper.vm.looking).toBe(false)

		post.mockResolvedValueOnce({ data: null })
		await wrapper.vm.lookupCard()
		expect(wrapper.vm.cardMessage).toBe('This gift card cannot be used: unknown')
	})

	it('says a gift card could not be checked when the request fails', async () => {
		post.mockRejectedValueOnce(new Error('offline'))
		const wrapper = mountDialog()

		await addCard(wrapper)

		expect(wrapper.vm.cardMessage).toBe('The gift card could not be checked.')
		expect(wrapper.vm.looking).toBe(false)
	})

	it('adds the same card once, and clears the form after adding', async () => {
		post.mockResolvedValue({ data: { valid: true, giftCardId: 'g1', balance: '25' } })
		const wrapper = mountDialog()

		await addCard(wrapper)
		expect(wrapper.vm.giftCards).toEqual([
			{ giftCardId: 'g1', serial: 'ZD-GC-7731', pin: '1234', balance: 25, amount: 25, expiryDate: null },
		])
		expect(wrapper.vm.cardMode).toBe('')
		expect(wrapper.vm.cardSerial).toBe('')
		expect(wrapper.vm.cardPin).toBe('')

		await addCard(wrapper)
		expect(wrapper.vm.giftCards).toHaveLength(1)
		expect(wrapper.vm.giftCardMeta({ balance: 25, amount: 10 })).toMatch(/^Balance €\s?25,00 · balance after this sale €\s?15,00$/u)
	})

	it('toggles a card form and clears its message', () => {
		const wrapper = mountDialog()
		wrapper.vm.cardMessage = 'old'

		wrapper.vm.openCard('check')
		expect(wrapper.vm.cardMode).toBe('check')
		expect(wrapper.vm.cardMessage).toBe('')
		wrapper.vm.openCard('check')
		expect(wrapper.vm.cardMode).toBe('')
		wrapper.vm.openCard('add')
		expect(wrapper.vm.cardMode).toBe('add')
	})

	it('holds typed points to whole points within the balance', () => {
		const wrapper = mountDialog()

		wrapper.vm.setPoints('12.9')
		expect(wrapper.vm.points).toBe(12)
		wrapper.vm.setPoints('-5')
		expect(wrapper.vm.points).toBe(0)
		wrapper.vm.setPoints('not a number')
		expect(wrapper.vm.points).toBe(0)
		wrapper.vm.setPoints(100000)
		expect(wrapper.vm.points).toBe(340)
	})
})
