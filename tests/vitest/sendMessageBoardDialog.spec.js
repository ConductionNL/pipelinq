// @vitest-environment jsdom
/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 * SPDX-License-Identifier: EUPL-1.2
 *
 * The send-message composer as the PqBerichtSturen board draws it: the
 * recipient in the header, the channel as a two-option switch with the
 * consent state under it, the consent fields only while consent is missing,
 * an optional template next to an optional message, and the note that the
 * send is kept as a contact moment.
 *
 * @spec openspec/changes/outbound-messaging-provider-wiring/tasks.md#task-4.2
 */

import { mount } from '@vue/test-utils'
import { describe, expect, it, vi } from 'vitest'
import { h } from 'vue'

vi.mock('@nextcloud/axios', () => ({ default: { post: vi.fn() } }))
vi.mock('@nextcloud/dialogs', () => ({ showError: vi.fn(), showSuccess: vi.fn() }))
vi.mock('@nextcloud/router', () => ({ generateUrl: (url) => url }))
vi.mock('../../src/components/SavedReplyPicker.vue', () => ({
	default: { name: 'SavedReplyPicker', render: () => h('div') },
}))
vi.mock('@conduction/nextcloud-vue', () => ({
	CnDialog: {
		name: 'CnDialog',
		props: ['name', 'eyebrow', 'subtitle', 'width'],
		render() {
			return h('div', { class: 'cn-dialog', 'data-eyebrow': this.eyebrow, 'data-subtitle': this.subtitle }, [
				this.$slots.default?.(),
				this.$slots.actions?.(),
			])
		},
	},
}))
vi.mock('@nextcloud/vue', () => {
	const field = (name) => ({
		name,
		props: ['modelValue', 'label', 'inputLabel', 'options'],
		render() {
			return h('label', { class: name }, this.inputLabel || this.label)
		},
	})
	return {
		NcButton: {
			name: 'NcButton',
			props: { disabled: Boolean, variant: String },
			emits: ['click'],
			render() {
				return h('button', { disabled: this.disabled, 'data-variant': this.variant, onClick: () => this.$emit('click') }, [
					this.$slots.icon?.(),
					this.$slots.default?.(),
				])
			},
		},
		NcSelect: field('NcSelect'),
		NcTextArea: field('NcTextArea'),
		NcTextField: field('NcTextField'),
	}
})

const translate = (app, text, vars) => text.replace(/\{(\w+)\}/g, (m, key) => (vars && key in vars ? vars[key] : m))
globalThis.t = translate

const { default: SendMessageModal } = await import('../../src/modals/SendMessageModal.vue')

const PREFLIGHT = {
	channels: { sms: true, whatsapp: true },
	whatsappSessionOpen: true,
	consent: { sms: 'unknown', whatsapp: 'opted-in' },
	templates: [{ id: 'tpl-1', externalId: 'appointment_reminder', language: 'nl', body: 'Op {{1}} om {{2}}' }],
}

function build(props = {}) {
	return mount(SendMessageModal, {
		props: {
			contactId: 'contact-1',
			clientId: 'client-1',
			clientName: 'Anna de Vries',
			recipientName: 'Anna de Vries',
			recipientPhone: '06 12 34 56 79',
			preflight: PREFLIGHT,
			...props,
		},
		global: { mocks: { t: translate } },
	})
}

describe('SendMessageModal as the board dialog', () => {
	it('names the client and the recipient in the header', () => {
		const dialog = build().find('.cn-dialog')
		expect(dialog.attributes('data-eyebrow')).toBe('Message · Anna de Vries')
		expect(dialog.attributes('data-subtitle')).toBe('To Anna de Vries, 06 12 34 56 79')
	})

	it('draws the channel as a radio switch of SMS and WhatsApp', () => {
		const radios = build().findAll('[role="radiogroup"] [role="radio"]')
		expect(radios.map((radio) => radio.text())).toEqual(['SMS', 'WhatsApp'])
		expect(radios.every((radio) => radio.attributes('aria-checked') === 'false')).toBe(true)
	})

	it('opens on the only available channel', () => {
		const wrapper = build({ preflight: { ...PREFLIGHT, channels: { sms: false, whatsapp: true } } })
		const radios = wrapper.findAll('[role="radio"]')
		expect(radios[0].attributes('disabled')).toBeDefined()
		expect(radios[1].attributes('aria-checked')).toBe('true')
	})

	it('says why nothing can be chosen when no channel is configured', () => {
		const wrapper = build({ preflight: { ...PREFLIGHT, channels: { sms: false, whatsapp: false } } })
		expect(wrapper.find('[data-testid="send-message-no-channel"]').exists()).toBe(true)
	})

	it('shows the consent state of a consented channel and no consent fields', async () => {
		const wrapper = build()
		await wrapper.findAll('[role="radio"]')[1].trigger('click')
		expect(wrapper.find('[data-testid="send-message-consent-state"]').text()).toBe('Consent for WhatsApp: opted in')
		expect(wrapper.find('[data-testid="send-message-consent"]').exists()).toBe(false)
	})

	it('asks for the legal basis and evidence while consent is unknown', async () => {
		const wrapper = build({ preflight: { ...PREFLIGHT, whatsappSessionOpen: false, consent: { sms: 'unknown', whatsapp: 'unknown' } } })
		await wrapper.findAll('[role="radio"]')[1].trigger('click')
		const block = wrapper.find('[data-testid="send-message-consent"]')
		expect(block.exists()).toBe(true)
		expect(block.text()).toContain('Consent for WhatsApp: unknown')
		expect(block.text()).toContain('Legal basis (optional)')
		expect(block.text()).toContain('Evidence')
		expect(block.text()).toContain('Record consent')
	})

	it('offers an optional template next to an optional message on WhatsApp', async () => {
		const wrapper = build()
		await wrapper.findAll('[role="radio"]')[1].trigger('click')
		const labels = wrapper.findAll('label').map((label) => label.text())
		expect(labels).toContain('Template (optional)')
		expect(labels).toContain('Message (optional)')
	})

	it('notes that the message is kept as a contact moment and sends with the send icon', async () => {
		const wrapper = build()
		expect(wrapper.find('[data-testid="send-message-note"]').text()).toBe('The message is saved as a contact moment.')
		const send = wrapper.findAll('button').find((button) => button.attributes('data-variant') === 'primary')
		expect(send.text()).toBe('Send')
		expect(send.find('.send-message__send-icon').exists()).toBe(true)
	})
})
