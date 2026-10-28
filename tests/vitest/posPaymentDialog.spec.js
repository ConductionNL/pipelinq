// @vitest-environment jsdom
/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 * SPDX-License-Identifier: EUPL-1.2
 *
 * The Payment received dialog (PqKassaAfrekenen): the total split over
 * loyalty points, gift cards and the rest, checking out once the parts add
 * up, and selling a gift card onto the sale.
 *
 * @spec openspec/changes/pos-sale-board-look/specs/pos-transactions/spec.md#requirement-a-payment-can-be-split
 */

import { flushPromises, mount } from '@vue/test-utils'
import { readFileSync } from 'fs'
import { resolve } from 'path'
import { describe, expect, it, vi } from 'vitest'
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
const post = vi.fn(async () => ({ data: { valid: true, giftCardId: 'g1', balance: 25, expiryDate: '2028-08-14' } }))
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
			eyebrow: 'Check out · Desk 2 · Sanne de Vries',
			customerName: 'Sanne de Vries',
			loyalty: { accountId: 'acc-1', balance: 340, pointValue: 0.05 },
			restLabel: 'Card',
			restHint: 'The rest, at Desk 2',
		},
		global: { mocks: { t: globalThis.t } },
	})
}

describe('the payment received dialog', () => {
	it('is a board dialog named Payment received, with the check-out line above it', () => {
		const dialog = mountDialog().findComponent({ name: 'CnDialog' })
		expect(dialog.props('name')).toBe('Payment received')
		expect(dialog.props('eyebrow')).toBe('Check out · Desk 2 · Sanne de Vries')
	})

	it('says what the points are worth and what is left after the sale', async () => {
		const wrapper = mountDialog()
		wrapper.vm.usePoints(200)
		await flushPromises()
		expect(wrapper.vm.pointsMeta).toMatch(/^Sanne de Vries has 340 points · 20 points is €\s?1,00 · after this sale 140 points left$/u)
		expect(wrapper.vm.pointsValue).toBe(10)
	})

	it('adds a gift card by number and PIN, and closes when the parts add up', async () => {
		const wrapper = mountDialog()
		wrapper.vm.usePoints(200)
		wrapper.vm.openCard('add')
		wrapper.vm.cardSerial = 'ZD-GC-7731'
		wrapper.vm.cardPin = '1234'
		await wrapper.vm.lookupCard()
		expect(post).toHaveBeenCalledWith('/apps/pipelinq/api/loyalty/gift-card/validate', { serial: 'ZD-GC-7731', pin: '1234' })
		expect(wrapper.vm.split.parts).toEqual([10, 25, 61.41])
		expect(wrapper.vm.split.closes).toBe(true)
		expect(wrapper.vm.closingText).toMatch(/^Payment closes: €\s?10,00 \+ €\s?25,00 \+ €\s?61,41 is €\s?96,41$/u)
	})

	it('hands the split to the page on check out', async () => {
		const wrapper = mountDialog()
		wrapper.vm.usePoints(200)
		wrapper.vm.confirm()
		expect(wrapper.emitted('confirm')[0][0]).toEqual({ points: 200, pointsValue: 10, giftCards: [], rest: 86.41 })
	})

	it('only checks a balance without adding the card', async () => {
		const wrapper = mountDialog()
		wrapper.vm.openCard('check')
		wrapper.vm.cardSerial = 'ZD-GC-7731'
		await wrapper.vm.lookupCard()
		expect(wrapper.vm.giftCards).toHaveLength(0)
		expect(wrapper.vm.cardMessage).toMatch(/^Gift card ZD-GC-7731 has €\s?25,00\.$/u)
	})

	it('sells a gift card onto the sale', () => {
		const wrapper = mountDialog()
		wrapper.vm.sellAmount = '25'
		wrapper.vm.sellGiftCard()
		expect(wrapper.emitted('sellGiftCard')[0][0]).toEqual({ amount: 25 })
	})
})

describe('the payment received dialog as the board draws it (PQB-7)', () => {
	const source = readFileSync(resolve(__dirname, '../../src/dialogs/PosPaymentDialog.vue'), 'utf8')
	const rule = (selector) => (source.match(new RegExp(selector.replace(/[.*+?^${}()|[\]\\]/g, '\\$&') + ' \\{([^}]*)\\}')) || [])[1] || ''

	it('sums the split up in a green status line, not a note card', async () => {
		const wrapper = mountDialog()
		wrapper.vm.usePoints(200)
		await flushPromises()
		const note = wrapper.find('[data-testid="pos-payment-note"]')
		expect(wrapper.findComponent({ name: 'NcNoteCard' }).exists()).toBe(false)
		expect(note.attributes('role')).toBe('status')
		expect(note.classes()).toContain('pos-payment__note--closes')
		expect(rule('.pos-payment__note')).toMatch(/border-radius: 8px/)
		expect(rule('.pos-payment__note--closes')).toMatch(/background: var\(--color-success-light/)
	})

	it('draws Add gift card and Check gift card balance as soft round pills', () => {
		const chips = mountDialog().findAllComponents({ name: 'NcButton' }).filter((button) => button.vm.$attrs.class === 'pos-payment__chip')
		expect(chips.map((chip) => chip.props('variant'))).toEqual(['tertiary', 'tertiary'])
		expect(rule('.pos-payment__chip.pos-payment__chip')).toMatch(/border-radius: 17px/)
		expect(rule('.pos-payment__chip.pos-payment__chip')).toMatch(/background: var\(--color-primary-element-light\)/)
	})
})
