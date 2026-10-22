// @vitest-environment jsdom
/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Saved replies (messaging-saved-replies-and-resend). The server lists and
 * fills them (GET /api/saved-replies, SavedReplyServiceTest); every composer
 * offers them through nextcloud-vue's CnTemplateSelect over that endpoint:
 * the Send message dialog (per channel, for the contact), the Write email
 * dialog, and the answer to the customer. Also the Write email link.
 *
 * @spec openspec/changes/answer-and-saved-replies-on-the-server/specs/messaging-saved-replies/spec.md#requirement-the-server-offers-saved-replies-filled-for-the-record
 */

import { flushPromises, mount } from '@vue/test-utils'
import { beforeEach, describe, expect, it, vi } from 'vitest'
import { h } from 'vue'

const storeMock = vi.hoisted(() => ({ fetchCollection: vi.fn(), fetchObject: vi.fn() }))
const axiosMock = vi.hoisted(() => ({ get: vi.fn(), post: vi.fn() }))

vi.mock('@nextcloud/axios', () => ({ default: axiosMock }))
vi.mock('@nextcloud/dialogs', () => ({ showError: vi.fn(), showSuccess: vi.fn() }))
vi.mock('@nextcloud/router', () => ({
	generateUrl: (path, params = {}) => '/index.php' + path.replace(/\{(\w+)\}/g, (m, k) => params[k] ?? m),
}))
vi.mock('@nextcloud/initial-state', () => ({ loadState: (app, key, fallback) => fallback }))
vi.mock('@nextcloud/l10n', () => ({
	translate: (app, text, vars) => String(text).replace(/\{(\w+)\}/g, (whole, key) => (vars && key in vars ? String(vars[key]) : whole)),
}))
vi.mock('../../src/store/modules/object.js', () => ({ useObjectStore: () => storeMock }))

function stub(name, tag = 'div') {
	return {
		name,
		inheritAttrs: false,
		props: ['modelValue', 'options', 'label', 'inputLabel', 'url', 'params'],
		emits: ['update:modelValue', 'click', 'close', 'apply'],
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
vi.mock('@conduction/nextcloud-vue', () => ({ CnTemplateSelect: stub('CnTemplateSelect') }))

globalThis.t = (app, text) => text

const { composeUrl } = await import('../../src/services/savedReplies.js')
const { default: SendMessageModal } = await import('../../src/modals/SendMessageModal.vue')
const { default: WriteEmailModal } = await import('../../src/modals/WriteEmailModal.vue')

const PREFLIGHT = {
	channels: { sms: true, whatsapp: true },
	whatsappSessionOpen: false,
	consent: { sms: 'opted-in', whatsapp: 'opted-in' },
	templates: [],
}

const mountModal = (component, props) => mount(component, { props, global: { mocks: { t: (app, text) => text } } })

beforeEach(() => {
	axiosMock.get.mockReset()
	axiosMock.get.mockResolvedValue({ data: PREFLIGHT })
	storeMock.fetchCollection.mockReset()
})

describe('SendMessageModal', () => {
	it('reads the preflight for the contact and offers the saved replies of the channel', async () => {
		const wrapper = mountModal(SendMessageModal, { contactId: 'c-1', initialChannel: 'sms' })
		await flushPromises()

		expect(axiosMock.get).toHaveBeenCalledWith('/index.php/apps/pipelinq/api/messaging/preflight/c-1')
		const picker = wrapper.findComponent({ name: 'CnTemplateSelect' })
		expect(picker.props('url')).toBe('/apps/pipelinq/api/saved-replies')
		expect(picker.props('params')).toEqual({ channel: 'sms', contact: 'c-1', client: '' })
		picker.vm.$emit('apply', { id: 'r1', name: 'Hours', values: { body: 'Dear Jan, we are open until five.' } })
		await flushPromises()

		expect(wrapper.vm.body).toBe('Dear Jan, we are open until five.')
		expect(wrapper.emitted('sent')).toBeUndefined()
	})

	it('offers no saved reply when WhatsApp needs an approved template', async () => {
		const wrapper = mountModal(SendMessageModal, { contactId: 'c-1', initialChannel: 'whatsapp' })
		await flushPromises()

		expect(wrapper.findComponent({ name: 'CnTemplateSelect' }).exists()).toBe(false)
	})

	it('opened for a client, asks which of its contacts and writes to the first by default', async () => {
		storeMock.fetchCollection.mockResolvedValue([{ id: 'c-7', name: 'Jan' }, { id: 'c-8', name: 'Piet' }])
		const wrapper = mountModal(SendMessageModal, { clientId: 'k-1', initialChannel: 'sms' })
		await flushPromises()

		expect(storeMock.fetchCollection).toHaveBeenCalledWith('contact', { client: 'k-1', _limit: 100 })
		expect(wrapper.vm.effectiveContactId).toBe('c-7')
		expect(axiosMock.get).toHaveBeenLastCalledWith('/index.php/apps/pipelinq/api/messaging/preflight/c-7')
		expect(wrapper.findComponent({ name: 'CnTemplateSelect' }).props('params')).toEqual({ channel: 'sms', contact: 'c-7', client: 'k-1' })
	})
})

describe('WriteEmailModal', () => {
	it('offers the email saved replies for the party', async () => {
		const wrapper = mountModal(WriteEmailModal, { address: 'jan@example.nl', contactId: 'c-1' })
		const picker = wrapper.findComponent({ name: 'CnTemplateSelect' })
		expect(picker.props('params')).toEqual({ channel: 'email', contact: 'c-1', client: '' })
		picker.vm.$emit('apply', { id: 'r2', name: 'Thanks', values: { body: 'Thank you.' } })
		await flushPromises()
		expect(wrapper.vm.body).toBe('Thank you.')
	})
})

describe('the Write email link', () => {
	it('opens the Mail composer with the encoded address, subject and body', () => {
		const url = composeUrl('jan@example.nl', 'Uw vraag & meer', 'Regel 1\nRegel 2', true, (p) => '/index.php' + p)
		expect(url.startsWith('/index.php/apps/mail/compose?uri=')).toBe(true)
		const mailto = decodeURIComponent(url.split('?uri=')[1])
		expect(mailto).toBe('mailto:jan@example.nl?subject=Uw%20vraag%20%26%20meer&body=Regel%201%0ARegel%202')
	})

	it('falls back to a mailto link when Mail is not enabled', () => {
		expect(composeUrl('jan@example.nl', 'Hoi', 'Tekst', false, () => 'never')).toBe('mailto:jan@example.nl?subject=Hoi&body=Tekst')
	})
})
