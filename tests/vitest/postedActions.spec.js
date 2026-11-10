// @vitest-environment jsdom
/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 * SPDX-License-Identifier: EUPL-1.2
 *
 * The posted actions on a booking (confirm deposit, complete, no-show,
 * reschedule, cancel) and on a cash shift (a drop): what they post, what
 * they say, and where the page goes afterwards.
 *
 * @spec openspec/specs/appointment-booking/spec.md
 */
import { afterEach, beforeAll, beforeEach, describe, expect, it, vi } from 'vitest'

const dialogs = vi.hoisted(() => ({ showError: vi.fn(), showSuccess: vi.fn() }))
const bus = vi.hoisted(() => ({ emit: vi.fn() }))
vi.mock('@nextcloud/dialogs', () => dialogs)
vi.mock('@nextcloud/event-bus', () => bus)
vi.mock('@nextcloud/router', () => ({ generateUrl: (p) => '/index.php' + p }))
vi.mock('@nextcloud/vue', () => ({ NcButton: {} }))
vi.mock('../../src/dialogs/CancelBookingDialog.vue', () => ({ default: {} }))
vi.mock('../../src/dialogs/RescheduleBookingDialog.vue', () => ({ default: {} }))

let actions
let postShiftAction

beforeAll(async () => {
	globalThis.t = (app, text) => text
	globalThis.OC = { requestToken: 'tok' }
	actions = (await import('../../src/components/bookings/BookingHeaderActions.vue')).default
	postShiftAction = (await import('../../src/services/posShiftActions.js')).postShiftAction
}, 60000)

beforeEach(() => {
	dialogs.showError.mockReset()
	dialogs.showSuccess.mockReset()
	bus.emit.mockReset()
})

afterEach(() => {
	vi.unstubAllGlobals()
})

function answer(ok, body) {
	vi.stubGlobal('fetch', vi.fn(async () => {
		if (ok instanceof Error) throw ok
		return { ok, json: body instanceof Error ? async () => { throw body } : async () => body }
	}))
}

const init = (body) => ({ method: 'POST', headers: { 'Content-Type': 'application/json', requesttoken: 'tok', 'OCS-APIREQUEST': 'true' }, body })

function booking(state = {}) {
	const ctx = { objectId: 'b1', booking: {}, busy: false, showReschedule: true, showCancel: true, $router: { push: vi.fn() }, ...state }
	for (const [name, fn] of Object.entries(actions.methods)) {
		if (!(name in ctx)) {
			ctx[name] = fn.bind(ctx)
		}
	}
	return ctx
}

describe('the booking actions', () => {
	it('posts the action, says so and refreshes the page', async () => {
		answer(true, {})
		const ctx = booking()
		await ctx.run('complete', {}, 'Booking marked completed.')
		expect(fetch).toHaveBeenCalledWith('/index.php/apps/pipelinq/api/bookings/b1/complete', init('{}'))
		expect(dialogs.showSuccess).toHaveBeenCalledWith('Booking marked completed.')
		expect(bus.emit).toHaveBeenCalledWith('cn:page:refresh', {})
		expect(ctx.busy).toBe(false)
	})

	it('opens the new booking a reschedule minted, and posts the cancel reason', async () => {
		answer(true, { bookingId: 'b2' })
		const ctx = booking({ objectId: '', booking: { id: 'b1' } })
		ctx.onReschedule('2026-12-01T10:00:00Z')
		await vi.waitFor(() => expect(ctx.$router.push).toHaveBeenCalled())
		expect(fetch).toHaveBeenCalledWith('/index.php/apps/pipelinq/api/bookings/b1/reschedule', init('{"newStartAt":"2026-12-01T10:00:00Z"}'))
		expect(ctx.$router.push).toHaveBeenCalledWith({ name: 'BookingDetail', params: { id: 'b2' } })
		expect(bus.emit).not.toHaveBeenCalled()
		expect(ctx.showReschedule).toBe(false)
		answer(true, { bookingId: 'b1' })
		const cancel = booking()
		cancel.onCancel(undefined)
		await vi.waitFor(() => expect(bus.emit).toHaveBeenCalled())
		expect(fetch).toHaveBeenCalledWith('/index.php/apps/pipelinq/api/bookings/b1/cancel', init('{"reason":""}'))
		expect(dialogs.showSuccess).toHaveBeenLastCalledWith('Booking cancelled.')
		expect(cancel.showCancel).toBe(false)
	})

	it('says why an action failed, and posts nothing without a booking', async () => {
		const cases = [[false, { error: 'Too late' }, 'Too late'], [false, new Error('no json'), 'Action failed.'], [new Error('down'), null, 'Action failed.']]
		for (const [ok, body, message] of cases) {
			answer(ok, body)
			const ctx = booking()
			await ctx.run('no-show', null, 'x')
			expect(dialogs.showError).toHaveBeenLastCalledWith(message)
			expect(dialogs.showSuccess).not.toHaveBeenCalled()
			expect(ctx.busy).toBe(false)
		}
		vi.stubGlobal('fetch', vi.fn())
		await booking({ objectId: '', booking: {} }).run('complete', {}, 'x')
		expect(fetch).not.toHaveBeenCalled()
		answer(true, {})
		bus.emit.mockImplementationOnce(() => { throw new Error('bus') })
		await booking().run('complete', {}, 'Done')
		expect(dialogs.showError).toHaveBeenLastCalledWith('Action failed.')
	})
})

describe('postShiftAction', () => {
	it('posts the shift action, says so, refreshes and answers true', async () => {
		answer(true, {})
		expect(await postShiftAction('s1', 'drop', { amount: 5 }, 'Drop recorded.')).toBe(true)
		expect(fetch).toHaveBeenCalledWith('/index.php/apps/pipelinq/api/pos-shifts/s1/drop', init('{"amount":5}'))
		expect(dialogs.showSuccess).toHaveBeenCalledWith('Drop recorded.')
		expect(bus.emit).toHaveBeenCalledWith('cn:page:refresh', {})
	})

	it('answers false without a shift, on a refusal, a failure or a failed refresh', async () => {
		vi.stubGlobal('fetch', vi.fn())
		expect(await postShiftAction('', 'drop', {}, 'x')).toBe(false)
		expect(fetch).not.toHaveBeenCalled()
		answer(false, { error: 'Closed' })
		expect(await postShiftAction('s1', 'drop', null, 'x')).toBe(false)
		expect(JSON.parse(fetch.mock.calls[0][1].body)).toEqual({})
		expect(dialogs.showError).toHaveBeenLastCalledWith('Closed')
		answer(false, new Error('no json'))
		expect(await postShiftAction('s1', 'drop', {}, 'x')).toBe(false)
		expect(dialogs.showError).toHaveBeenLastCalledWith('Action failed.')
		answer(new Error('down'))
		expect(await postShiftAction('s1', 'drop', {}, 'x')).toBe(false)
		answer(true, {})
		bus.emit.mockImplementationOnce(() => { throw new Error('bus') })
		expect(await postShiftAction('s1', 'drop', {}, 'x')).toBe(false)
		expect(dialogs.showError).toHaveBeenLastCalledWith('Action failed.')
	})
})
