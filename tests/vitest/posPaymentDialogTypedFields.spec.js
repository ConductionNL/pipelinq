// @vitest-environment jsdom
/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 * SPDX-License-Identifier: EUPL-1.2
 *
 * The Payment received dialog's gift card and sell forms filled in through
 * their text fields, as the cashier types: the typed number and PIN are what
 * the lookup sends, the lookup button waits for a number, and the sell form
 * only accepts an amount above zero, handing over what was typed. A sale
 * whose total cannot be split into positive parts says the parts do not add
 * up and keeps check-out closed.
 *
 * @spec openspec/specs/pos-transactions/spec.md#requirement-a-payment-can-be-split
 */
import { flushPromises, mount } from '@vue/test-utils'
import { beforeEach, describe, expect, it, vi } from 'vitest'
import { h } from 'vue'

const post = vi.hoisted(() => vi.fn())
vi.mock('@nextcloud/axios', () => ({ default: { post } }))
vi.mock('@nextcloud/router', () => ({ generateUrl: (url) => url }))
vi.mock('@nextcloud/l10n', () => ({ getLocale: () => 'nl', getLanguage: () => 'nl', translate: (app, text) => text }))
vi.mock('@conduction/nextcloud-vue', () => ({
	CnDialog: { name: 'CnDialog', emits: ['closing'], render() { return h('div', [this.$slots.default?.(), this.$slots.actions?.()]) } },
}))
vi.mock('@nextcloud/vue', () => ({
	NcButton: {
		name: 'NcButton',
		props: ['variant', 'disabled', 'type'],
		emits: ['click'],
		render() { return h('button', { type: this.type || 'button', disabled: this.disabled, onClick: () => this.$emit('click') }, this.$slots.default?.()) },
	},
	NcTextField: {
		name: 'NcTextField',
		props: ['modelValue', 'label', 'type'],
		emits: ['update:modelValue'],
		render() { return h('input', { 'data-label': this.label, value: this.modelValue, onInput: (e) => this.$emit('update:modelValue', e.target.value) }) },
	},
}))

globalThis.t = (app, text, vars = {}) => String(text).replace(/\{(\w+)\}/g, (whole, key) => vars[key] ?? whole)

const { default: Dialog } = await import('../../src/dialogs/PosPaymentDialog.vue')
const { formatEur } = await import('../../src/services/posTotals.js')

const mountDialog = (props = {}) => mount(Dialog, { props: { total: 50, restLabel: 'Card', ...props }, global: { mocks: { t: globalThis.t } } })
const field = (w, label) => w.find(`input[data-label="${label}"]`)
const button = (w, text) => w.findAll('button').find((b) => b.text().trim() === text)

beforeEach(() => {
	post.mockReset()
})

describe('the payment dialog forms, typed into', () => {
	it('looks up the gift card number and PIN that were typed', async () => {
		post.mockResolvedValue({ data: { valid: true, giftCardId: 'g-1', balance: 20 } })
		const w = mountDialog()
		await button(w, 'Add gift card').trigger('click')
		expect(button(w, 'Add').attributes('disabled')).toBeDefined()
		await field(w, 'Gift card number').setValue('GC-1234')
		await field(w, 'PIN').setValue('9876')
		expect(button(w, 'Add').attributes('disabled')).toBeUndefined()
		await w.find('form').trigger('submit')
		await flushPromises()
		expect(post).toHaveBeenCalledWith('/apps/pipelinq/api/loyalty/gift-card/validate', { serial: 'GC-1234', pin: '9876' })
		expect(w.vm.giftCards).toEqual([{ giftCardId: 'g-1', serial: 'GC-1234', pin: '9876', balance: 20, amount: 20, expiryDate: null }])
		expect(w.find('form').exists()).toBe(false)
	})

	it('checks the balance of the typed card number', async () => {
		post.mockResolvedValue({ data: { valid: true, giftCardId: 'g-2', balance: 7.5 } })
		const w = mountDialog()
		await button(w, 'Check gift card balance').trigger('click')
		await field(w, 'Gift card number').setValue('GC-77')
		await button(w, 'Check balance').trigger('click')
		await w.find('form').trigger('submit')
		await flushPromises()
		expect(post).toHaveBeenCalledWith('/apps/pipelinq/api/loyalty/gift-card/validate', { serial: 'GC-77', pin: '' })
		expect(w.find('[role="status"].pos-payment__card-message').text()).toBe(`Gift card GC-77 has ${formatEur(7.5)}.`)
		expect(w.vm.giftCards).toEqual([])
	})

	it('sells a gift card for the typed amount, and only for more than zero', async () => {
		const w = mountDialog()
		await button(w, 'Sell gift card').trigger('click')
		const add = () => button(w, 'Add to the sale')
		expect(add().attributes('disabled')).toBeDefined()
		await field(w, 'Gift card amount').setValue('0')
		expect(add().attributes('disabled')).toBeDefined()
		await field(w, 'Gift card amount').setValue('25.50')
		expect(add().attributes('disabled')).toBeUndefined()
		await w.find('form').trigger('submit')
		expect(w.emitted('sellGiftCard')).toEqual([[{ amount: 25.5 }]])
		expect(w.find('form').exists()).toBe(false)
	})

	it('says the parts do not add up for a total no positive part can pay', () => {
		const w = mountDialog({ total: -5 })
		const note = w.find('[data-testid="pos-payment-note"]')
		expect(note.classes()).toContain('pos-payment__note--open')
		expect(note.text()).toBe(`The parts do not add up to ${formatEur(-5)} yet.`)
		expect(w.find('[data-testid="payment-confirm"]').attributes('disabled')).toBeDefined()
	})
})
