// @vitest-environment jsdom
/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 * SPDX-License-Identifier: EUPL-1.2
 *
 * The send-message modal's logic, run against a plain component context with
 * HTTP and toasts doubled: which channels it offers and opens on, the header
 * lines, the consent gate per channel, the WhatsApp template rules, recording
 * consent, and every send outcome.
 *
 * @spec openspec/changes/archive/2026-07-06-outbound-messaging-provider-wiring/tasks.md#task-4.2
 */

import { beforeEach, describe, expect, it, vi } from 'vitest'

vi.hoisted(() => {
	globalThis.t = (app, text, vars = {}) => String(text).replace(/\{(\w+)\}/g, (m, k) => (k in vars ? String(vars[k]) : m))
})

const stub = (name) => ({ name, render: () => null })
const toasts = []
const http = vi.hoisted(() => ({ post: vi.fn() }))
vi.mock('@nextcloud/axios', () => ({ default: http }))
vi.mock('@nextcloud/router', () => ({ generateUrl: (path) => '/index.php' + path }))
vi.mock('@nextcloud/dialogs', () => ({
	showError: (m) => toasts.push(['error', m]),
	showSuccess: (m) => toasts.push(['success', m]),
}))
vi.mock('@nextcloud/vue', () => ({
	NcButton: stub('NcButton'),
	NcSelect: stub('NcSelect'),
	NcTextArea: stub('NcTextArea'),
	NcTextField: stub('NcTextField'),
}))
vi.mock('@conduction/nextcloud-vue', () => ({ CnDialog: stub('CnDialog') }))
vi.mock('../../src/components/SavedReplyPicker.vue', () => ({ default: stub('SavedReplyPicker') }))

const { default: SendMessageModal } = await import('../../src/modals/SendMessageModal.vue')

/**
 * The prop defaults of the component.
 *
 * @return {object} Each prop's default value.
 */
function defaults() {
	const out = {}
	for (const [name, def] of Object.entries(SendMessageModal.props)) {
		out[name] = typeof def.default === 'function' ? def.default() : def.default
	}
	return out
}

/**
 * A component context: props, data, computed values as getters and methods bound.
 *
 * @param {object} [props] Props over the defaults.
 * @return {object} The context.
 */
function modal(props = {}) {
	const ctx = { $emit: vi.fn(), ...defaults(), contactId: 'c-1', ...props }
	for (const [name, fn] of Object.entries(SendMessageModal.methods)) {
		ctx[name] = fn.bind(ctx)
	}
	Object.assign(ctx, SendMessageModal.data.call(ctx))
	for (const [name, def] of Object.entries(SendMessageModal.computed)) {
		Object.defineProperty(ctx, name, { get: () => def.call(ctx), configurable: true })
	}
	return ctx
}

const both = { channels: { sms: true, whatsapp: true }, whatsappSessionOpen: false, consent: { sms: 'opted-in', whatsapp: 'unknown' }, templates: [] }

beforeEach(() => {
	toasts.length = 0
	http.post.mockReset()
})

describe('SendMessageModal channels and header', () => {
	it('opens on the only channel with a provider, and on none when there are two', () => {
		expect(modal({ preflight: { channels: { sms: true, whatsapp: false } } }).channel).toBe('sms')
		expect(modal({ preflight: { channels: { whatsapp: true } } }).channel).toBe('whatsapp')
		expect(modal({ preflight: both }).channel).toBeNull()
		expect(modal({ preflight: {} }).channel).toBeNull()
		expect(modal({ preflight: both, initialChannel: 'whatsapp' }).channel).toBe('whatsapp')
	})

	it('offers the channels that have a provider', () => {
		expect(modal({ preflight: both }).channelOptions.map((o) => o.value)).toEqual(['sms', 'whatsapp'])
		expect(modal({ preflight: { channels: { sms: true } } }).channelOptions.map((o) => o.value)).toEqual(['sms'])
		expect(modal({ preflight: {} }).channelOptions).toEqual([])
		const choices = modal({ preflight: { channels: { whatsapp: true } } }).channelChoices
		expect(choices.map((c) => [c.value, c.available])).toEqual([['sms', false], ['whatsapp', true]])
		expect(modal({ preflight: {} }).channelChoices.every((c) => c.available === false)).toBe(true)
	})

	it('names the client and the recipient in the header', () => {
		const m = modal({ clientName: 'Acme', recipientName: 'Anna', recipientPhone: '+31600000000' })
		expect(m.eyebrow).toBe('Message · Acme')
		expect(m.subtitle).toBe('To Anna, +31600000000')
		expect(modal({ recipientName: 'Anna' }).eyebrow).toBe('Message · Anna')
		expect(modal().eyebrow).toBe('')
		expect(modal({ recipientPhone: '+31600000000' }).subtitle).toBe('To +31600000000')
		expect(modal().subtitle).toBe('')
		expect(typeof modal().today).toBe('string')
	})
})

describe('SendMessageModal consent and templates', () => {
	it('words the consent of the chosen channel, including a locally recorded opt-in', () => {
		const m = modal({ preflight: both, initialChannel: 'sms' })
		expect(m.consentText).toBe('Consent for SMS: opted in')
		m.channel = 'whatsapp'
		expect(m.consentText).toBe('Consent for WhatsApp: unknown')
		m.localConsent = { channel: 'whatsapp', state: 'opted-in' }
		expect(m.effectiveConsentState).toBe('opted-in')
		m.localConsent = { channel: 'sms', state: 'opted-out' }
		expect(m.effectiveConsentState).toBe('unknown')
		expect(modal({ preflight: { channels: { sms: true } } }).effectiveConsentState).toBe('unknown')
		const odd = modal({ preflight: { channels: { sms: true }, consent: { sms: 'pending' } } })
		expect(odd.consentLabel).toBe('unknown')
	})

	it('lets SMS send unless opted out, and a WhatsApp template only on an opt-in', () => {
		const sms = modal({ preflight: { ...both, consent: { sms: 'unknown' } }, initialChannel: 'sms' })
		expect(sms.consentOk).toBe(true)
		const optedOut = modal({ preflight: { ...both, consent: { sms: 'opted-out' } }, initialChannel: 'sms' })
		expect(optedOut.consentOk).toBe(false)
		const wa = modal({ preflight: both, initialChannel: 'whatsapp' })
		expect(wa.whatsappNeedsTemplate).toBe(true)
		expect(wa.consentOk).toBe(false)
		const waIn = modal({ preflight: { ...both, consent: { whatsapp: 'opted-in' } }, initialChannel: 'whatsapp' })
		expect(waIn.consentOk).toBe(true)
		expect(modal({ preflight: both }).consentOk).toBe(true)
	})

	it('offers the approved templates on WhatsApp and needs one outside the session', () => {
		const templates = [{ id: 'tp-1', externalId: 'welcome', language: 'nl', body: 'Hoi {{1}}, uw code is {{2}}' }]
		const m = modal({ preflight: { ...both, templates, consent: { whatsapp: 'opted-in' } }, initialChannel: 'whatsapp' })
		expect(m.showTemplates).toBe(true)
		expect(m.templateOptions).toEqual([{ value: 'tp-1', label: 'welcome (nl)' }])
		expect(m.useTemplate).toBe(true)
		expect(m.canSend).toBe(false)
		m.templateId = 'tp-1'
		expect(m.selectedTemplate).toBe(templates[0])
		SendMessageModal.watch.selectedTemplate.call(m, m.selectedTemplate)
		expect(m.templateParams).toEqual(['', ''])
		expect(m.canSend).toBe(true)
		SendMessageModal.watch.selectedTemplate.call(m, null)
		expect(m.templateParams).toEqual([])
		SendMessageModal.watch.selectedTemplate.call(m, { body: 'no placeholders' })
		expect(m.templateParams).toEqual([])

		const open = modal({ preflight: { ...both, whatsappSessionOpen: true }, initialChannel: 'whatsapp' })
		expect(open.showTemplates).toBe(false)
		expect(open.useTemplate).toBe(false)
		expect(modal({ preflight: both, initialChannel: 'sms' }).showTemplates).toBe(false)
		expect(modal({ preflight: both }).legalBasisOptions.map((o) => o.value)).toEqual(['consent', 'contract', 'legitimate-interest'])
	})

	it('needs a body for a free-text send and clears the draft when the channel changes', () => {
		const m = modal({ preflight: both, initialChannel: 'sms' })
		expect(m.canSend).toBe(false)
		m.body = 'Hello'
		expect(m.canSend).toBe(true)
		m.sending = true
		expect(m.canSend).toBe(false)
		m.sendResult = { status: 'failed' }
		SendMessageModal.watch.channel.call(m)
		expect([m.body, m.templateId, m.templateParams, m.sendResult]).toEqual(['', null, [], null])
	})
})

describe('SendMessageModal actions', () => {
	it('records consent for the chosen channel and says so', async () => {
		const m = modal({ preflight: both, initialChannel: 'whatsapp' })
		await m.recordConsent()
		expect(http.post).not.toHaveBeenCalled()

		m.consentEvidence = 'Signed form 2026-11-01'
		http.post.mockResolvedValueOnce({ data: { state: 'opted-in' } })
		await m.recordConsent()
		expect(http.post).toHaveBeenCalledWith('/index.php/apps/pipelinq/api/messaging/consent', {
			contactId: 'c-1',
			channel: 'whatsapp',
			action: 'opt-in',
			source: 'admin-override',
			evidence: 'Signed form 2026-11-01',
			legalBasis: 'consent',
		})
		expect([m.localConsent, m.recordingConsent, toasts[0]]).toEqual([{ channel: 'whatsapp', state: 'opted-in' }, false, ['success', 'Consent recorded.']])

		http.post.mockRejectedValueOnce(new Error('403'))
		await m.recordConsent()
		expect([toasts[1], m.recordingConsent]).toEqual([['error', 'Failed to record consent.'], false])
	})

	it('sends a free-text message, then closes on success', async () => {
		const m = modal({ preflight: both, initialChannel: 'sms', clientId: 'cl-1' })
		await m.send()
		expect(http.post).not.toHaveBeenCalled()

		m.body = 'Your order is ready'
		http.post.mockResolvedValueOnce({ data: { status: 'sent', id: 'msg-1' } })
		await m.send()
		expect(http.post).toHaveBeenCalledWith('/index.php/apps/pipelinq/api/messaging/send', {
			contactId: 'c-1',
			channel: 'sms',
			body: 'Your order is ready',
			templateId: null,
			parameters: [],
			clientId: 'cl-1',
		})
		expect(m.$emit.mock.calls).toEqual([['sent', { status: 'sent', id: 'msg-1' }], ['close']])
		expect([m.sending, toasts[0]]).toEqual([false, ['success', 'Message sent.']])
	})

	it('sends a template with its parameters and no body', async () => {
		const templates = [{ id: 'tp-1', externalId: 'welcome', language: 'nl', body: 'Hoi {{1}}' }]
		const m = modal({ preflight: { ...both, templates, consent: { whatsapp: 'opted-in' } }, initialChannel: 'whatsapp' })
		m.templateId = 'tp-1'
		m.templateParams = ['Anna']
		m.body = 'ignored'
		http.post.mockResolvedValueOnce({ data: { status: 'queued' } })
		await m.send()
		const payload = http.post.mock.calls[0][1]
		expect([payload.body, payload.templateId, payload.parameters]).toEqual(['', 'tp-1', ['Anna']])
		expect(m.$emit).not.toHaveBeenCalled()
		expect(m.sendResult).toEqual({ status: 'queued' })
	})

	it('explains each refusal the server gives', async () => {
		const m = modal({ preflight: both, initialChannel: 'sms' })
		expect(m.resultMessage).toBe('')
		const cases = {
			'consent-missing': 'The server refused to send: consent is missing for this channel.',
			'budget-exceeded': 'The server refused to send: the messaging budget for this period is exceeded.',
			'template-required': 'The server refused to send: the WhatsApp session window has expired and a template is required.',
			'no-provider': 'The server refused to send: no active provider is configured for this channel.',
			failed: 'The message could not be delivered by the provider.',
			teapot: 'The server could not process this send request.',
		}
		for (const [status, text] of Object.entries(cases)) {
			m.sendResult = { status }
			expect(m.resultMessage).toBe(text)
		}
		m.sendResult = { status: 'template-invalid', reason: 'paused' }
		expect(m.resultMessage).toBe('The server refused to send: the selected template is invalid (paused).')
		m.sendResult = { status: 'template-invalid' }
		expect(m.resultMessage).toBe('The server refused to send: the selected template is invalid (unknown).')

		m.body = 'Hi'
		http.post.mockRejectedValueOnce({ response: { data: { status: 'budget-exceeded' } } })
		await m.send()
		expect(m.sendResult).toEqual({ status: 'budget-exceeded' })
		http.post.mockRejectedValueOnce(new Error('network'))
		await m.send()
		expect([m.sendResult, m.sending]).toEqual([{ status: 'failed' }, false])
	})
})
