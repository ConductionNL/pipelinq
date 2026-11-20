// @vitest-environment jsdom
/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Send a quote from a lead (products-quote-from-lead): the header action is
 * declared on LeadDetail and hidden while filinq is absent; the modal shows
 * the recipient, posts the template and the valid-until date, says where the
 * quote went, and refuses to send without a recipient.
 *
 * @spec openspec/changes/products-quote-from-lead/specs/product-catalog-quoting/spec.md#requirement-a-quote-is-sent-as-a-pdf-and-logged-req-qslice-002
 */

import { flushPromises, mount } from '@vue/test-utils'
import axe from 'axe-core'
import { readFileSync } from 'node:fs'
import { resolve } from 'node:path'
import { beforeAll, beforeEach, describe, expect, it, vi } from 'vitest'
import { h } from 'vue'

const letters = vi.hoisted(() => ({
	fetchLetterTemplates: vi.fn(),
}))
const quotes = vi.hoisted(() => ({
	fetchQuoteRecipient: vi.fn(),
	sendQuote: vi.fn(),
}))

vi.mock('../../src/services/letters.js', () => letters)
vi.mock('../../src/services/quotes.js', async (importOriginal) => ({
	...(await importOriginal()),
	...quotes,
}))
vi.mock('@conduction/nextcloud-vue', () => ({
	CnDialog: {
		name: 'CnDialog',
		props: ['name', 'width'],
		render() {
			return h('div', { role: 'dialog', 'aria-label': this.name }, [
				this.$slots.default?.(),
				this.$slots.actions?.(),
			])
		},
	},
}))
vi.mock('@nextcloud/vue', () => ({
	NcButton: {
		name: 'NcButton',
		props: { disabled: Boolean },
		emits: ['click'],
		render() {
			return h('button', { type: 'button', disabled: this.disabled, onClick: () => this.$emit('click') }, this.$slots.default?.())
		},
	},
	NcSelect: {
		name: 'NcSelect',
		props: ['modelValue', 'options', 'inputLabel', 'inputId'],
		emits: ['update:modelValue'],
		render() {
			return h('select', { id: this.inputId, 'aria-label': this.inputLabel })
		},
	},
	NcDateTimePickerNative: {
		name: 'NcDateTimePickerNative',
		props: ['modelValue', 'label', 'type', 'id'],
		emits: ['update:modelValue'],
		render() {
			return h('label', {}, [this.label, h('input', { type: 'date', id: this.id })])
		},
	},
}))

const LEAD = '0e1f2a3b-4c5d-4e6f-8a9b-0c1d2e3f4a5b'

let Modal

beforeAll(async () => {
	globalThis.t = (app, text, vars = {}) => text.replace(/\{(\w+)\}/g, (m, k) => (k in vars ? String(vars[k]) : m))
	Modal = (await import('../../src/modals/SendQuoteModal.vue')).default
})

beforeEach(() => {
	Object.values(letters).forEach((fn) => fn.mockReset())
	Object.values(quotes).forEach((fn) => fn.mockReset())
	letters.fetchLetterTemplates.mockResolvedValue({
		available: true,
		templates: [{ id: 'tpl-q', name: 'Offerte', description: '' }],
	})
	quotes.fetchQuoteRecipient.mockResolvedValue({ email: 'kees@degraaf.example', name: 'Kees Bakker', source: 'contact' })
})

function mountOnLead() {
	return mount(Modal, {
		attachTo: document.body,
		global: {
			mixins: [{ methods: { t: globalThis.t } }],
			mocks: { $route: { path: `/leads/${LEAD}`, params: { id: LEAD } } },
		},
	})
}

const read = (p) => readFileSync(resolve(__dirname, '../..', p), 'utf8')

describe('SendQuoteModal', () => {
	it('shows the recipient and sends the quote with the template and the date', async () => {
		quotes.sendQuote.mockResolvedValue({
			number: 'OFF-2026-0042',
			recipient: { email: 'kees@degraaf.example' },
			path: '/Pipelinq/OFF-2026-0042 Nieuw kantoor.pdf',
			contactMomentId: 'cm-1',
		})
		const w = mountOnLead()
		await flushPromises()

		expect(quotes.fetchQuoteRecipient).toHaveBeenCalledWith(LEAD)
		expect(w.find('[data-testid="quote-recipient"]').text()).toBe('The quote goes to Kees Bakker (kees@degraaf.example).')

		w.vm.validUntil = new Date(2026, 11, 17)
		await w.find('[data-testid="quote-send"]').trigger('click')
		await flushPromises()

		expect(quotes.sendQuote).toHaveBeenCalledWith(LEAD, { templateId: 'tpl-q', validUntil: '2026-12-17' })
		expect(w.find('[data-testid="quote-result"]').text()).toContain('Quote OFF-2026-0042 was sent to kees@degraaf.example.')
		expect(w.find('[data-testid="quote-result"]').text()).toContain('/Pipelinq/OFF-2026-0042 Nieuw kantoor.pdf')
		w.unmount()
	})

	it('asks for an email address and does not send without a recipient', async () => {
		quotes.fetchQuoteRecipient.mockResolvedValue(null)
		const w = mountOnLead()
		await flushPromises()

		expect(w.find('[data-testid="quote-no-recipient"]').exists()).toBe(true)
		expect(w.find('[data-testid="quote-send"]').attributes('disabled')).toBeDefined()
		await w.find('[data-testid="quote-send"]').trigger('click')
		expect(quotes.sendQuote).not.toHaveBeenCalled()
		w.unmount()
	})

	it('shows the server\'s message when the send is refused', async () => {
		quotes.sendQuote.mockRejectedValue({ response: { data: { message: 'The quote was made and filed, but the mail could not be sent.' } } })
		const w = mountOnLead()
		await flushPromises()

		await w.find('[data-testid="quote-send"]').trigger('click')
		await flushPromises()

		expect(w.find('[role="alert"]').text()).toBe('The quote was made and filed, but the mail could not be sent.')
		expect(w.find('[data-testid="quote-result"]').exists()).toBe(false)
		w.unmount()
	})

	it('has no accessibility violations', async () => {
		const w = mountOnLead()
		await flushPromises()

		const results = await axe.run(w.element, { rules: { 'color-contrast': { enabled: false } } })

		expect(results.violations.map((v) => v.id)).toEqual([])
		w.unmount()
	})
})

describe('the Send quote header action', () => {
	const manifest = JSON.parse(read('src/manifest.json'))
	const lead = manifest.pages.find((p) => p.id === 'LeadDetail')
	const action = lead.config.headerActions.find((a) => a.id === 'send-quote')

	it('opens the quote modal from LeadDetail', () => {
		expect(action).toMatchObject({ type: 'open-modal', target: 'SendQuoteModal', label: 'Send quote' })
	})

	it('is hidden, not disabled, while filinq is absent', () => {
		expect(action.visibleWhen).toEqual({
			endpoint: '/apps/pipelinq/api/letters/templates',
			field: 'available',
			op: 'eq',
			value: true,
		})
		expect(action.disabledWhen).toBeUndefined()
	})

	it('is registered under its target name', () => {
		expect(read('src/registry.js')).toMatch(/SendQuoteModal: \{\n\t\tkind: 'modal',\n\t\tcomponent: SendQuoteModal,/)
	})
})

describe('the quote date helpers', () => {
	it('default to thirty days ahead and write the date as Y-m-d', async () => {
		const { defaultValidUntil, isoDate } = await vi.importActual('../../src/services/quotes.js')

		expect(isoDate(defaultValidUntil(new Date(2026, 10, 17, 15, 30)))).toBe('2026-12-17')
		expect(isoDate(new Date(2027, 0, 5))).toBe('2027-01-05')
	})
})
