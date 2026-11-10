/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Branches of two sends pinned before they were split into named steps:
 * saving the answer to a customer, and sending a message again.
 */
import { beforeAll, beforeEach, describe, expect, it, vi } from 'vitest'

const store = vi.hoisted(() => ({ fetchObject: vi.fn(), saveObject: vi.fn() }))
const http = vi.hoisted(() => ({ post: vi.fn(), get: vi.fn() }))
vi.mock('@nextcloud/l10n', () => ({ translate: (app, text) => text }))
vi.mock('@nextcloud/axios', () => ({ default: http }))
vi.mock('@nextcloud/event-bus', () => ({ emit: vi.fn(), subscribe: vi.fn(), unsubscribe: vi.fn() }))
vi.mock('@nextcloud/router', () => ({ generateUrl: (path, vars = {}) => path.replace(/\{(\w+)\}/g, (match, key) => vars[key]) }))
vi.mock('@nextcloud/vue', () => ({ NcButton: {}, NcCheckboxRadioSwitch: {}, NcEmptyContent: {}, NcLoadingIcon: {}, NcNoteCard: {}, NcSelect: {}, NcTextArea: {}, NcTextField: {} }))
vi.mock('../../src/components/SavedReplyPicker.vue', () => ({ default: {} }))
vi.mock('../../src/modals/SendMessageModal.vue', () => ({ default: {} }))
vi.mock('../../src/store/modules/object.js', () => ({ useObjectStore: () => store }))

let reply
let conversation

beforeAll(async () => {
	globalThis.t = (app, text) => text
	reply = (await import('../../src/components/CustomerReplySection.vue')).default
	conversation = (await import('../../src/views/messaging/MessagingConversationSection.vue')).default
}, 60000)

beforeEach(() => {
	store.fetchObject.mockReset()
	store.saveObject.mockReset()
	http.post.mockReset()
})

describe('CustomerReplySection.save', () => {
	/**
	 * @param {object} state The section's state.
	 * @return {object} A section context.
	 */
	function ctx(state) {
		return {
			...reply.methods,
			busy: false,
			canSave: true,
			notice: 'old',
			noticeType: '',
			objectId: 't1',
			message: ' Hello ',
			ticket: null,
			answersAfter: vi.fn(() => ['answers']),
			$emit: vi.fn(),
			...state,
		}
	}

	it('saves the answer, waiting for the customer when asked', async () => {
		store.fetchObject.mockResolvedValue({ id: 'x', status: 'open', title: 'T' })
		store.saveObject.mockImplementation(async (type, next) => ({ ...next, saved: true }))
		const c = ctx({})
		const pending = reply.methods.save.call(c, true)
		expect(c.busy).toBe(true)
		expect(c.notice).toBe('')
		await pending
		expect(c.answersAfter).toHaveBeenCalledWith({ id: 'x', status: 'open', title: 'T' }, 'Hello')
		expect(store.saveObject).toHaveBeenCalledWith('ticket', { id: 't1', status: 'awaiting_customer', title: 'T', customerMessage: 'Hello', portalAnswers: ['answers'] })
		expect(c.ticket.saved).toBe(true)
		expect(c.$emit).toHaveBeenCalledWith('saved', c.ticket)
		expect(c).toMatchObject({ notice: 'The answer is saved. The customer can read it on the portal.', noticeType: 'success', busy: false })
		const keep = ctx({})
		await reply.methods.save.call(keep, false)
		expect(store.saveObject.mock.calls[1][1].status).toBe('open')
	})

	it('says it could not save, and does nothing while busy or empty', async () => {
		store.fetchObject.mockResolvedValue(null)
		const c = ctx({})
		await reply.methods.save.call(c, false)
		expect(c).toMatchObject({ notice: 'Could not save the answer.', noticeType: 'error', busy: false })
		expect(store.saveObject).not.toHaveBeenCalled()
		store.fetchObject.mockResolvedValue({ id: 't1' })
		store.saveObject.mockResolvedValue(null)
		const unsaved = ctx({})
		await reply.methods.save.call(unsaved, false)
		expect(unsaved.notice).toBe('Could not save the answer.')
		expect(unsaved.$emit).not.toHaveBeenCalled()
		store.fetchObject.mockClear()
		await reply.methods.save.call(ctx({ busy: true }), false)
		await reply.methods.save.call(ctx({ canSave: false }), false)
		expect(store.fetchObject).not.toHaveBeenCalled()
		store.fetchObject.mockRejectedValue(new Error('x'))
		const failing = ctx({})
		await expect(reply.methods.save.call(failing, false)).rejects.toThrow('x')
		expect(failing.busy).toBe(false)
	})
})

describe('MessagingConversationSection.resend', () => {
	/**
	 * @param {object} state The section's state.
	 * @return {object} A section context.
	 */
	function ctx(state) {
		return {
			...conversation.methods,
			resending: '',
			resendErrors: { other: 'kept' },
			composerChannel: 'email',
			showComposer: false,
			onSent: vi.fn(),
			resendReason: (status) => `reason:${status}`,
			...state,
		}
	}

	it('reloads when the message was sent', async () => {
		http.post.mockResolvedValue({ data: { status: 'sent' } })
		const c = ctx({})
		const pending = conversation.methods.resend.call(c, { id: 'm1' })
		expect(c.resending).toBe('m1')
		expect(c.resendErrors).toEqual({ other: 'kept', m1: '' })
		await pending
		expect(http.post).toHaveBeenCalledWith('/apps/pipelinq/api/messaging/messages/m1/resend')
		expect(c.onSent).toHaveBeenCalled()
		expect(c.resending).toBe('')
	})

	it('opens the WhatsApp composer when a template is required', async () => {
		http.post.mockRejectedValue({ response: { data: { status: 'template-required' } } })
		const c = ctx({})
		await conversation.methods.resend.call(c, { id: 'm1' })
		expect(c).toMatchObject({ composerChannel: 'whatsapp', showComposer: true })
		expect(c.resendErrors.m1).toBe('')
	})

	it('shows the reason of a refusal on the row, and waits for a running resend', async () => {
		for (const [answer, reason] of [
			[Promise.resolve({ data: { status: 'expired' } }), 'reason:expired'],
			[Promise.resolve({ data: null }), 'reason:failed'],
			[Promise.reject(new Error('x')), 'reason:failed'],
		]) {
			http.post.mockReturnValue(answer)
			const c = ctx({})
			await conversation.methods.resend.call(c, { id: 'm2' })
			expect(c.resendErrors).toEqual({ other: 'kept', m2: reason })
			expect(c.onSent).not.toHaveBeenCalled()
		}
		http.post.mockClear()
		await conversation.methods.resend.call(ctx({ resending: 'm9' }), { id: 'm1' })
		expect(http.post).not.toHaveBeenCalled()
	})
})
