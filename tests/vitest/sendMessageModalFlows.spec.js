// @vitest-environment jsdom
/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 * SPDX-License-Identifier: EUPL-1.2
 *
 * The send message dialog's flows: recording consent when the channel lacks
 * it, a WhatsApp template with its parameters when the session window is
 * closed, a plain SMS body, and every way a send ends — sent and closed, or
 * the server's refusal named in the dialog.
 *
 * @spec openspec/changes/archive/2026-07-06-outbound-messaging-provider-wiring/tasks.md#task-4.2
 */
import { flushPromises, mount } from '@vue/test-utils'
import { beforeEach, describe, expect, it, vi } from 'vitest'
import { h } from 'vue'

const http = vi.hoisted(() => ({ post: vi.fn() }))
const toast = vi.hoisted(() => ({ showError: vi.fn(), showSuccess: vi.fn() }))
vi.mock('@nextcloud/axios', () => ({ default: http }))
vi.mock('@nextcloud/dialogs', () => toast)
vi.mock('@nextcloud/router', () => ({ generateUrl: (path) => '/index.php' + path }))
vi.mock('../../src/components/SavedReplyPicker.vue', () => ({
	default: {
		name: 'SavedReplyPicker',
		props: ['channel', 'language', 'values'],
		emits: ['pick'],
		render: () => h('div'),
	},
}))
for (const icon of [
	'AlertCircleOutline',
	'CheckCircleOutline',
	'InformationOutline',
	'MessageTextOutline',
	'Send',
	'ShieldCheckOutline',
	'Whatsapp',
]) {
	vi.doMock(`vue-material-design-icons/${icon}.vue`, () => ({
		default: { name: icon, render: () => h('i', { class: icon }) },
	}))
}
function field (name) {
  return {
	name,
	props: ['modelValue', 'options', 'label', 'inputLabel', 'placeholder'],
	emits: ['update:modelValue'],
	render: () => h('div'),
}
}
vi.mock('@nextcloud/vue', () => ({
	NcButton: {
		name: 'NcButton',
		props: ['disabled', 'variant'],
		emits: ['click'],
		render() {
			return h(
				'button',
				{ disabled: this.disabled, onClick: () => this.$emit('click') },
				this.$slots.default?.(),
			)
		},
	},
	NcSelect: field('NcSelect'),
	NcTextArea: field('NcTextArea'),
	NcTextField: field('NcTextField'),
}))
vi.mock('@conduction/nextcloud-vue', () => ({
	CnDialog: {
		name: 'CnDialog',
		props: ['name', 'eyebrow', 'subtitle'],
		emits: ['closing'],
		render() {
			return h('div', [
				this.$slots.default?.(),
				h('footer', this.$slots.actions?.()),
			])
		},
	},
}))

function t (app, text, vars) {
  return text.replace(/\{(\w+)\}/g, (m, k) => (vars && k in vars ? vars[k] : m))
}
globalThis.t = t
const global = { mocks: { t } }

const { default: SendMessageModal } =
	await import('../../src/modals/SendMessageModal.vue')

function preflight (over = {}) {
  return {
	channels: { sms: true, whatsapp: true },
	whatsappSessionOpen: false,
	consent: { sms: 'unknown', whatsapp: 'unknown' },
	templates: [
		{
			id: 'tpl-1',
			externalId: 'welcome',
			language: 'nl',
			body: 'Hoi {{1}}, uw afspraak is {{2}}.',
		},
	],
	...over,
}
}

function open(props = {}) {
	return mount(SendMessageModal, {
		props: {
			contactId: 'ct-1',
			clientId: 'cl-1',
			preflight: preflight(),
			...props,
		},
		global,
	})
}

const sendButton = (wrapper) => wrapper.findAll('footer button')[1]
function consentState (wrapper) {
  return wrapper.find('[data-testid="send-message-consent-state"]')
}

describe('the send message dialog', () => {
	beforeEach(() => {
		http.post.mockReset()
		toast.showError.mockReset()
		toast.showSuccess.mockReset()
	})

	it('asks for consent evidence on a closed WhatsApp window and records the opt-in', async () => {
		const wrapper = open({ initialChannel: 'whatsapp' })
		expect(consentState(wrapper).text()).toBe('Consent for WhatsApp: unknown')
		const record = wrapper.find('[data-testid="send-message-consent"] button')
		expect(record.attributes('disabled')).toBeDefined()

		const [evidence] = wrapper
			.find('[data-testid="send-message-consent"]')
			.findAllComponents({ name: 'NcTextField' })
		await evidence.vm.$emit('update:modelValue', 'Verbal yes on the phone')
		http.post.mockResolvedValueOnce({ data: { state: 'opted-in' } })
		await record.trigger('click')
		await flushPromises()

		expect(http.post).toHaveBeenCalledWith(
			'/index.php/apps/pipelinq/api/messaging/consent',
			{
				contactId: 'ct-1',
				channel: 'whatsapp',
				action: 'opt-in',
				source: 'admin-override',
				evidence: 'Verbal yes on the phone',
				legalBasis: 'consent',
			},
		)
		expect(toast.showSuccess).toHaveBeenCalledWith('Consent recorded.')
		expect(wrapper.find('[data-testid="send-message-consent"]').exists()).toBe(
			false,
		)
		expect(consentState(wrapper).text()).toBe('Consent for WhatsApp: opted in')
		expect(consentState(wrapper).find('.CheckCircleOutline').exists()).toBe(true)
	})

	it('says when recording consent failed and keeps asking', async () => {
		const wrapper = open({ initialChannel: 'whatsapp' })
		const section = wrapper.find('[data-testid="send-message-consent"]')
		await section
			.findAllComponents({ name: 'NcTextField' })[0]
			.vm.$emit('update:modelValue', 'Mail')
		http.post.mockRejectedValueOnce(new Error('down'))
		await section.find('button').trigger('click')
		await flushPromises()
		expect(toast.showError).toHaveBeenCalledWith('Failed to record consent.')
		expect(wrapper.find('[data-testid="send-message-consent"]').exists()).toBe(
			true,
		)
	})

	it('sends an approved template with one parameter per placeholder', async () => {
		const wrapper = open({
			initialChannel: 'whatsapp',
			preflight: preflight({ consent: { whatsapp: 'opted-in' } }),
		})
		expect(wrapper.text()).toContain(
			'The 24-hour WhatsApp session window is closed',
		)
		expect(sendButton(wrapper).attributes('disabled')).toBeDefined()

		const template = wrapper.findAllComponents({ name: 'NcSelect' })[0]
		expect(template.props('inputLabel')).toBe('Template')
		expect(template.props('options')).toEqual([
			{ value: 'tpl-1', label: 'welcome (nl)' },
		])
		await template.vm.$emit('update:modelValue', 'tpl-1')
		expect(wrapper.find('.send-message__template-preview').text()).toBe(
			'Hoi {{1}}, uw afspraak is {{2}}.',
		)
		const params = wrapper.findAllComponents({ name: 'NcTextField' })
		expect(params.map((p) => p.props('label'))).toEqual([
			'Parameter 1',
			'Parameter 2',
		])
		await params[0].vm.$emit('update:modelValue', 'Jan')
		await params[1].vm.$emit('update:modelValue', 'morgen')
		expect(wrapper.findComponent({ name: 'NcTextArea' }).exists()).toBe(false)

		http.post.mockResolvedValueOnce({ data: { status: 'sent', id: 'm-1' } })
		await sendButton(wrapper).trigger('click')
		await flushPromises()
		expect(http.post).toHaveBeenCalledWith(
			'/index.php/apps/pipelinq/api/messaging/send',
			{
				contactId: 'ct-1',
				channel: 'whatsapp',
				body: '',
				templateId: 'tpl-1',
				parameters: ['Jan', 'morgen'],
				clientId: 'cl-1',
			},
		)
		expect(toast.showSuccess).toHaveBeenCalledWith('Message sent.')
		expect(wrapper.emitted('sent')).toEqual([[{ status: 'sent', id: 'm-1' }]])
		expect(wrapper.emitted('close')).toHaveLength(1)
	})

	it('sends a typed SMS body and names a server refusal', async () => {
		const wrapper = open({ initialChannel: 'sms' })
		expect(consentState(wrapper).find('.AlertCircleOutline').exists()).toBe(true)
		expect(wrapper.findComponent({ name: 'NcTextArea' }).props('label')).toBe(
			'Message',
		)
		await wrapper
			.findComponent({ name: 'SavedReplyPicker' })
			.vm.$emit('pick', 'Saved hello')
		expect(
			wrapper.findComponent({ name: 'NcTextArea' }).props('modelValue'),
		).toBe('Saved hello')

		http.post.mockResolvedValueOnce({ data: { status: 'budget-exceeded' } })
		await sendButton(wrapper).trigger('click')
		await flushPromises()
		expect(http.post.mock.calls[0][1]).toMatchObject({
			channel: 'sms',
			body: 'Saved hello',
			templateId: null,
			parameters: [],
		})
		expect(wrapper.find('[role="alert"]').text()).toBe(
			'The server refused to send: the messaging budget for this period is exceeded.',
		)
		expect(wrapper.emitted('close')).toBeUndefined()
	})

	it('names each refusal the server can answer with', async () => {
		const wrapper = open({ initialChannel: 'sms' })
		await wrapper
			.findComponent({ name: 'NcTextArea' })
			.vm.$emit('update:modelValue', 'Hi')
		const answers = [
			[
				{ data: { status: 'consent-missing' } },
				'consent is missing for this channel',
			],
			[{ data: { status: 'template-required' } }, 'a template is required'],
			[
				{ data: { status: 'template-invalid', reason: 'paused' } },
				'is invalid (paused)',
			],
			[{ data: { status: 'template-invalid' } }, 'is invalid (unknown)'],
			[
				{ data: { status: 'no-provider' } },
				'no active provider is configured',
			],
			[
				{ data: { status: 'something-else' } },
				'could not process this send request',
			],
		]
		for (const [answer, text] of answers) {
			http.post.mockResolvedValueOnce(answer)
			await sendButton(wrapper).trigger('click')
			await flushPromises()
			expect(wrapper.find('[role="alert"]').text()).toContain(text)
		}

		http.post.mockRejectedValueOnce({
			response: { data: { status: 'no-provider' } },
		})
		await sendButton(wrapper).trigger('click')
		await flushPromises()
		expect(wrapper.find('[role="alert"]').text()).toContain('no active provider')

		http.post.mockRejectedValueOnce(new Error('network'))
		await sendButton(wrapper).trigger('click')
		await flushPromises()
		expect(wrapper.find('[role="alert"]').text()).toBe(
			'The message could not be delivered by the provider.',
		)
	})

	it('clears the draft and the result when the channel changes', async () => {
		const wrapper = open({
			initialChannel: 'sms',
			preflight: preflight({ whatsappSessionOpen: true }),
		})
		await wrapper
			.findComponent({ name: 'NcTextArea' })
			.vm.$emit('update:modelValue', 'Draft')
		http.post.mockResolvedValueOnce({ data: { status: 'failed' } })
		await sendButton(wrapper).trigger('click')
		await flushPromises()
		expect(wrapper.find('[role="alert"]').exists()).toBe(true)

		await wrapper.findAll('[role="radio"]')[1].trigger('click')
		expect(wrapper.find('[role="alert"]').exists()).toBe(false)
		const textarea = wrapper.findComponent({ name: 'NcTextArea' })
		expect(textarea.props('modelValue')).toBe('')
		expect(textarea.props('label')).toBe('Message (optional)')
		expect(
			wrapper.findAllComponents({ name: 'NcSelect' })[0].props('inputLabel'),
		).toBe('Template (optional)')
	})

	it('blocks a send to a contact who opted out', () => {
		const wrapper = open({
			initialChannel: 'sms',
			preflight: preflight({ consent: { sms: 'opted-out' } }),
		})
		expect(consentState(wrapper).classes()).toContain(
			'send-message__consent-state--error',
		)
		expect(sendButton(wrapper).attributes('disabled')).toBeDefined()
	})
})
