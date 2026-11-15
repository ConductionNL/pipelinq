/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Golden for the till PIN sign-in: who is let in, who is refused, and what
 * each refusal says. Only an answer with a session for a staff member opens
 * the till; everything else leaves it closed and clears the PIN.
 *
 * @spec exclude the pos-staff-pin-permissions change is archived and no spec
 *   inherited POS staff, PIN login or roles
 */
import { beforeAll, beforeEach, describe, expect, it, vi } from 'vitest'

const http = vi.hoisted(() => ({ post: vi.fn() }))
const session = vi.hoisted(() => ({ openSession: vi.fn() }))
vi.mock('@nextcloud/axios', () => ({ default: http }))
vi.mock('@nextcloud/router', () => ({ generateUrl: (path) => path }))
vi.mock('@conduction/nextcloud-vue', () => ({ CnDialog: {} }))
vi.mock('@nextcloud/vue', () => ({ NcButton: {}, NcSelect: {}, NcTextField: {} }))
vi.mock('../../src/store/modules/posSessionStore.js', () => ({ usePosSessionStore: () => session }))

let modal

beforeAll(async () => {
	globalThis.t = (app, text) => text
	modal = (await import('../../src/modals/PinLoginModal.vue')).default
}, 60000)

beforeEach(() => {
	http.post.mockReset()
	session.openSession.mockReset()
})

/**
 * @param {object} state The modal's state.
 * @return {object} A modal context with a filled-in form.
 */
function ctx(state = {}) {
	return {
		...modal.methods,
		canSubmit: true,
		submitting: false,
		errorMessage: 'old',
		lockedUntil: 'old',
		selectedStaffId: 's1',
		pin: '1234',
		$emit: vi.fn(),
		...state,
	}
}

describe('PinLoginModal.submit lets in', () => {
	it('a staff member the server opened a session for', async () => {
		http.post.mockResolvedValue({ data: { session: { staffId: 's1', role: 'cashier' } } })
		const c = ctx()
		const pending = modal.methods.submit.call(c)
		expect(c.submitting).toBe(true)
		expect(c.errorMessage).toBe('')
		expect(c.lockedUntil).toBe('')
		await pending
		expect(http.post).toHaveBeenCalledWith('/apps/pipelinq/api/pos/staff/auth', { staffId: 's1', pin: '1234' })
		expect(session.openSession).toHaveBeenCalledWith({ staffId: 's1', role: 'cashier' })
		expect(c.$emit.mock.calls).toEqual([['loginSuccess', { staffId: 's1', role: 'cashier' }], ['close']])
		expect(c.pin).toBe('1234')
		expect(c.submitting).toBe(false)
	})
})

describe('PinLoginModal.submit refuses', () => {
	/**
	 * @param {object} c A context after a refused submit.
	 */
	function expectClosed(c) {
		expect(session.openSession).not.toHaveBeenCalled()
		expect(c.$emit).not.toHaveBeenCalled()
		expect(c.pin).toBe('')
		expect(c.submitting).toBe(false)
	}

	it('an answer without a staff session', async () => {
		for (const data of [{}, { session: null }, { session: { role: 'x' } }, null]) {
			http.post.mockResolvedValue({ data })
			const c = ctx()
			await modal.methods.submit.call(c)
			expect(c.errorMessage).toBe('An unexpected error occurred')
			expect(c.lockedUntil).toBe('')
			expectClosed(c)
		}
	})

	it('a wrong PIN, with the server\'s message or a general one', async () => {
		http.post.mockRejectedValue({ response: { status: 403, data: { error: 'PIN onjuist' } } })
		let c = ctx()
		await modal.methods.submit.call(c)
		expect(c.errorMessage).toBe('PIN onjuist')
		expect(c.lockedUntil).toBe('')
		expectClosed(c)
		http.post.mockRejectedValue({ response: { status: 403, data: {} } })
		c = ctx()
		await modal.methods.submit.call(c)
		expect(c.errorMessage).toBe('Incorrect PIN')
		expectClosed(c)
	})

	it('a locked account, in Dutch or English', async () => {
		for (const error of ['Account geblokkeerd tot 12:00', 'Account LOCKED until noon']) {
			http.post.mockRejectedValue({ response: { status: 403, data: { error } } })
			const c = ctx()
			await modal.methods.submit.call(c)
			expect(c.lockedUntil).toBe(error)
			expect(c.errorMessage).toBe('')
			expectClosed(c)
		}
	})

	it('any other failure, with the server\'s message or a general one', async () => {
		http.post.mockRejectedValue({ response: { status: 500, data: { error: 'Down' } } })
		let c = ctx()
		await modal.methods.submit.call(c)
		expect(c.errorMessage).toBe('Down')
		expectClosed(c)
		http.post.mockRejectedValue(new Error('network'))
		c = ctx()
		await modal.methods.submit.call(c)
		expect(c.errorMessage).toBe('An unexpected error occurred')
		expect(c.lockedUntil).toBe('')
		expectClosed(c)
	})

	it('a form that cannot be sent yet, without asking the server', async () => {
		const c = ctx({ canSubmit: false })
		await modal.methods.submit.call(c)
		expect(http.post).not.toHaveBeenCalled()
		expect(c.submitting).toBe(false)
		expect(c.errorMessage).toBe('old')
		expect(c.pin).toBe('1234')
	})
})
