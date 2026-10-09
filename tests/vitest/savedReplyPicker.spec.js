// @vitest-environment jsdom
/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Saved replies (messaging-saved-replies-and-resend, tasks 3.1, 3.2, 3.4):
 * the pure helpers, the picker over the object store, the picker inside the
 * Send message dialog, and the Write email link.
 *
 * @spec openspec/changes/messaging-saved-replies-and-resend/specs/messaging-saved-replies/spec.md#requirement-an-agent-inserts-a-saved-reply-where-they-answer-req-msr-003
 */

import { flushPromises, mount } from '@vue/test-utils'
import { beforeEach, describe, expect, it, vi } from 'vitest'
import { h } from 'vue'

const storeMock = vi.hoisted(() => ({
	fetchCollection: vi.fn(),
	fetchObject: vi.fn(),
	saveObject: vi.fn(),
}))
const stateMock = vi.hoisted(() => ({ statuses: {} }))

vi.mock('@nextcloud/axios', () => ({ default: { get: vi.fn(), post: vi.fn() } }))
vi.mock('@nextcloud/dialogs', () => ({ showError: vi.fn(), showSuccess: vi.fn() }))
vi.mock('@nextcloud/router', () => ({
	generateUrl: (path) => '/index.php' + path,
}))
vi.mock('@nextcloud/auth', () => ({
	getCurrentUser: () => ({ uid: 'pieter', displayName: 'Pieter Jansen' }),
}))
vi.mock('@nextcloud/initial-state', () => ({
	loadState: (app, key, fallback) =>
		key === 'dependency_statuses' ? stateMock.statuses : fallback,
}))
vi.mock('@nextcloud/l10n', () => ({
	translate: (app, text, vars) =>
		String(text).replace(/\{(\w+)\}/g, (whole, key) =>
			vars && key in vars ? String(vars[key]) : whole,
		),
}))
vi.mock('../../src/store/modules/object.js', () => ({
	useObjectStore: () => storeMock,
}))

function stub (name, tag = 'div') {
  return {
	name,
	inheritAttrs: false,
	props: ['modelValue', 'options', 'label', 'inputLabel'],
	emits: ['update:modelValue', 'click', 'close'],
	render() {
		return h(tag, { 'data-stub': name }, this.$slots.default?.())
	},
}
}

vi.mock('@nextcloud/vue', () => ({
	NcButton: stub('NcButton', 'button'),
	NcModal: stub('NcModal'),
	NcSelect: stub('NcSelect'),
	NcTextArea: stub('NcTextArea'),
	NcTextField: stub('NcTextField'),
}))

globalThis.t = (app, text) => text

const { composeUrl, fillPlaceholders, repliesForChannel } = await import(
	'../../src/services/savedReplies.js'
)
const { default: SavedReplyPicker } = await import(
	'../../src/components/SavedReplyPicker.vue'
)
const { default: SendMessageModal } = await import(
	'../../src/modals/SendMessageModal.vue'
)

const replies = [
	{ id: 'r1', title: 'Opening hours', body: 'Dear {{contact.name}}, we are open until five.', channels: ['sms', 'email'] },
	{ id: 'r2', title: 'Email only', body: 'See the attachment.', channels: ['email'] },
	{ id: 'r3', title: 'Switched off', body: 'Old text', channels: ['sms'], active: false },
	{ id: 'r4', title: 'Bedankt', body: 'Bedankt, {{agent.name}}', channels: ['sms', 'whatsapp'], language: 'nl' },
]

describe('saved reply helpers', () => {
	it('lists only active replies for the channel, the party language first', () => {
		expect(repliesForChannel(replies, 'sms', 'nl').map((r) => r.id)).toEqual(['r4', 'r1'])
		expect(repliesForChannel(replies, 'whatsapp').map((r) => r.id)).toEqual(['r4'])
	})

	it('does not offer an email-only reply on WhatsApp', () => {
		expect(repliesForChannel(replies, 'whatsapp').map((r) => r.id)).not.toContain('r2')
	})

	it('fills known placeholders and leaves an unknown or empty one as written', () => {
		expect(
			fillPlaceholders('Dear {{contact.name}}, about {{ticket.title}} from {{agent.name}} {{foo.bar}}', {
				'contact.name': 'Jan de Vries',
				'agent.name': 'Pieter',
				'ticket.title': '',
			}),
		).toBe('Dear Jan de Vries, about {{ticket.title}} from Pieter {{foo.bar}}')
	})

	it('opens the Mail composer with the encoded address, subject and body', () => {
		const url = composeUrl('jan@example.nl', 'Uw vraag & meer', 'Regel 1\nRegel 2', true, (p) => '/index.php' + p)
		expect(url.startsWith('/index.php/apps/mail/compose?uri=')).toBe(true)
		const mailto = decodeURIComponent(url.split('?uri=')[1])
		expect(mailto).toBe('mailto:jan@example.nl?subject=Uw%20vraag%20%26%20meer&body=Regel%201%0ARegel%202')
	})

	it('falls back to a mailto link when Mail is not enabled', () => {
		expect(composeUrl('jan@example.nl', 'Hoi', 'Tekst', false, () => 'never')).toBe(
			'mailto:jan@example.nl?subject=Hoi&body=Tekst',
		)
	})
})

describe('SavedReplyPicker', () => {
	beforeEach(() => {
		storeMock.fetchCollection.mockReset()
		storeMock.fetchCollection.mockResolvedValue(replies)
	})

	it('reads savedReply and offers the replies for its channel', async () => {
		const wrapper = mount(SavedReplyPicker, { props: { channel: 'sms' } })
		await flushPromises()

		expect(storeMock.fetchCollection).toHaveBeenCalledWith('savedReply', { _limit: 200 })
		const select = wrapper.findComponent({ name: 'NcSelect' })
		expect(select.props('options').map((r) => r.id)).toEqual(['r4', 'r1'])
		expect(select.props('inputLabel')).toBe('Saved reply')
	})

	it('emits the text with the host values and the agent filled in', async () => {
		const wrapper = mount(SavedReplyPicker, {
			props: { channel: 'sms', values: { 'contact.name': 'Jan de Vries' } },
		})
		await flushPromises()

		wrapper.findComponent({ name: 'NcSelect' }).vm.$emit('update:modelValue', replies[0])
		wrapper.findComponent({ name: 'NcSelect' }).vm.$emit('update:modelValue', replies[3])

		expect(wrapper.emitted('pick')).toEqual([
			['Dear Jan de Vries, we are open until five.'],
			['Bedankt, Pieter Jansen'],
		])
	})
})

describe('SendMessageModal with saved replies', () => {
	const preflight = {
		channels: { sms: true, whatsapp: true },
		whatsappSessionOpen: false,
		consent: { sms: 'opted-in', whatsapp: 'opted-in' },
		templates: [],
	}

	beforeEach(() => {
		storeMock.fetchCollection.mockReset()
		storeMock.fetchCollection.mockResolvedValue(replies)
	})

	it('fills the message from a picked reply on SMS and sends nothing', async () => {
		const wrapper = mount(SendMessageModal, {
			global: { mocks: { t: (app, text) => text } },
			props: {
				contactId: 'c-1',
				preflight,
				initialChannel: 'sms',
				placeholderValues: { 'contact.name': 'Jan de Vries' },
			},
		})
		await flushPromises()

		const picker = wrapper.findComponent(SavedReplyPicker)
		expect(picker.exists()).toBe(true)
		expect(picker.props('channel')).toBe('sms')
		picker.vm.$emit('pick', 'Dear Jan de Vries, we are open until five.')
		await flushPromises()

		expect(wrapper.vm.body).toBe('Dear Jan de Vries, we are open until five.')
		expect(wrapper.emitted('sent')).toBeUndefined()
	})

	it('shows no saved reply picker when WhatsApp needs an approved template', async () => {
		const wrapper = mount(SendMessageModal, {
			global: { mocks: { t: (app, text) => text } },
			props: { contactId: 'c-1', preflight, initialChannel: 'whatsapp' },
		})
		await flushPromises()

		expect(wrapper.findComponent(SavedReplyPicker).exists()).toBe(false)
	})
})
