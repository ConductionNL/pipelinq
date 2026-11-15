// @vitest-environment jsdom
/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 * SPDX-License-Identifier: EUPL-1.2
 *
 * The booking detail section's data: the timeline of a booking, and the
 * linked service, customer and resource names it reads once each.
 *
 * @spec openspec/specs/appointment-booking/spec.md
 */
import { beforeAll, describe, expect, it, vi } from 'vitest'

vi.mock('@nextcloud/dialogs', () => ({ showError: vi.fn(), showSuccess: vi.fn() }))
vi.mock('@nextcloud/vue', () => ({ NcButton: {}, NcLoadingIcon: {} }))
vi.mock('../../src/store/modules/object.js', () => ({ useObjectStore: () => ({}) }))

let section

beforeAll(async () => {
	globalThis.t = (app, text, vars = {}) => text.replace(/\{(\w+)\}/g, (m, k) => vars[k] ?? m)
	section = (await import('../../src/components/bookings/BookingDetailSection.vue')).default
}, 60000)

/**
 * @param {object} state The section state; computed values may be given as plain values.
 * @return {object} A section context.
 */
function bind(state) {
	const ctx = { resourceLookup: {}, service: null, customer: null, customerIsContact: false, loadedCustomerId: null, contextLoading: false, ...state }
	for (const [name, fn] of Object.entries(section.computed)) {
		if (!(name in ctx)) {
			Object.defineProperty(ctx, name, { get: () => fn.call(ctx), configurable: true })
		}
	}
	for (const [name, fn] of Object.entries(section.methods)) {
		if (!(name in ctx)) {
			ctx[name] = fn.bind(ctx)
		}
	}
	return ctx
}

describe('the booking timeline', () => {
	it('merges the timestamps and the status changes, earliest first', () => {
		const ctx = bind({
			booking: {
				startAt: '2026-11-10T10:00:00Z',
				endAt: '2026-11-10T11:00:00Z',
				confirmationSentAt: '2026-11-01T09:00:00Z',
				reminderSentAt: '2026-11-09T09:00:00Z',
				depositPaidAt: '2026-11-02T09:00:00Z',
				noShowFeeChargedAt: '2026-11-10T12:00:00Z',
				cancelledAt: '2026-11-03T09:00:00Z',
			},
			statusHistory: [{ changedAt: '2026-11-01T08:00:00Z', status: 'confirmed' }, { changedAt: '2026-11-10T11:30:00Z', status: 'odd' }],
		})
		expect(ctx.timeline).toEqual([
			{ at: '2026-11-01T08:00:00Z', kind: 'status', text: 'Status: Confirmed' },
			{ at: '2026-11-01T09:00:00Z', kind: 'email', text: 'Confirmation email sent' },
			{ at: '2026-11-02T09:00:00Z', kind: 'deposit', text: 'Deposit cleared' },
			{ at: '2026-11-03T09:00:00Z', kind: 'cancel', text: 'Cancelled' },
			{ at: '2026-11-09T09:00:00Z', kind: 'email', text: 'Reminder email sent' },
			{ at: '2026-11-10T10:00:00Z', kind: 'start', text: 'Booking starts' },
			{ at: '2026-11-10T11:00:00Z', kind: 'end', text: 'Booking ends' },
			{ at: '2026-11-10T11:30:00Z', kind: 'status', text: 'Status: odd' },
			{ at: '2026-11-10T12:00:00Z', kind: 'fee', text: 'No-show fee charged' },
		])
	})

	it('lists only what the booking has', () => {
		expect(bind({ booking: { startAt: '2026-11-10T10:00:00Z' }, statusHistory: [] }).timeline).toEqual([{ at: '2026-11-10T10:00:00Z', kind: 'start', text: 'Booking starts' }])
		expect(bind({ booking: {}, statusHistory: [] }).timeline).toEqual([])
	})
})

describe('loadContext', () => {
	it('reads the service, the customer as a contact, and each resource once', async () => {
		const fetchObject = vi.fn(async (type, id) => ({ appointmentService: { name: 'Haircut' }, contact: { name: 'Anna' }, appointmentResource: id === 'r2' ? {} : { name: 'Chair ' + id } }[type]))
		const ctx = bind({ objectStore: { fetchObject }, booking: { serviceId: 's1', customerId: 'c1' }, assignments: [{ resourceId: 'r1' }, { resourceId: 'r1' }, null, { resourceId: 'r2' }, { resourceId: 'r3' }], resourceLookup: { r3: 'Known' } })
		await ctx.loadContext()
		expect(fetchObject.mock.calls).toEqual([['appointmentService', 's1'], ['contact', 'c1'], ['appointmentResource', 'r1'], ['appointmentResource', 'r2']])
		expect([ctx.service, ctx.customer, ctx.customerIsContact, ctx.loadedCustomerId]).toEqual([{ name: 'Haircut' }, { name: 'Anna' }, true, 'c1'])
		expect(ctx.resourceLookup).toEqual({ r3: 'Known', r1: 'Chair r1' })
		expect(ctx.contextLoading).toBe(false)
		fetchObject.mockClear()
		await ctx.loadContext()
		expect(fetchObject.mock.calls).toEqual([['appointmentResource', 'r2']])
	})

	it('falls back to the client, reads a missing customer once, and does nothing while loading', async () => {
		const fetchObject = vi.fn(async (type) => (type === 'client' ? { name: 'Bakkerij' } : null))
		const ctx = bind({ objectStore: { fetchObject }, booking: { customerId: 'c2' }, assignments: [] })
		await ctx.loadContext()
		expect(fetchObject.mock.calls).toEqual([['contact', 'c2'], ['client', 'c2']])
		expect([ctx.customer, ctx.customerIsContact]).toEqual([{ name: 'Bakkerij' }, false])
		fetchObject.mockClear()
		await ctx.loadContext()
		expect(fetchObject).not.toHaveBeenCalled()
		const busy = bind({ objectStore: { fetchObject }, booking: { serviceId: 's1' }, assignments: [], contextLoading: true })
		await busy.loadContext()
		expect(fetchObject).not.toHaveBeenCalled()
		const failing = bind({ objectStore: { fetchObject: vi.fn().mockRejectedValue(new Error('x')) }, booking: { serviceId: 's1' }, assignments: [] })
		await expect(failing.loadContext()).rejects.toThrow('x')
		expect(failing.contextLoading).toBe(false)
	})
})
