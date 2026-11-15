// @vitest-environment jsdom
/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 * SPDX-License-Identifier: EUPL-1.2
 *
 * The booking detail section's own logic: the customer card (initials, page,
 * kind), the reminder rule and its request, the deposit line, the "Now" marker
 * in the timeline, and the names it falls back to.
 *
 * @spec openspec/specs/appointment-booking/spec.md
 */
import { afterEach, beforeAll, beforeEach, describe, expect, it, vi } from 'vitest'

const dialogs = vi.hoisted(() => ({ showError: vi.fn(), showSuccess: vi.fn() }))
const bus = vi.hoisted(() => ({ emit: vi.fn(), subscribe: vi.fn(), unsubscribe: vi.fn() }))

vi.mock('@nextcloud/dialogs', () => dialogs)
vi.mock('@nextcloud/event-bus', () => bus)
vi.mock('@nextcloud/router', () => ({ generateUrl: (path) => '/index.php' + path }))
vi.mock('@nextcloud/initial-state', () => ({ loadState: () => ({ currency: 'EUR' }) }))
vi.mock('@nextcloud/vue', () => ({ NcButton: {}, NcLoadingIcon: {} }))
vi.mock('../../src/store/modules/object.js', () => ({ useObjectStore: () => ({}) }))

let section

beforeAll(async () => {
	globalThis.t = (app, text, vars = {}) => text.replace(/\{(\w+)\}/g, (m, k) => vars[k] ?? m)
	globalThis.OC = { requestToken: 'tok' }
	section = (await import('../../src/components/bookings/BookingDetailSection.vue')).default
}, 60000)

beforeEach(() => {
	dialogs.showError.mockClear()
	dialogs.showSuccess.mockClear()
	bus.emit.mockClear()
})

afterEach(() => {
	vi.useRealTimers()
	vi.unstubAllGlobals()
})

/**
 * @param {object} state The section state; computed values may be given as plain values.
 * @return {object} A section context.
 */
function bind(state) {
	const ctx = { part: 'all', resourceLookup: {}, service: null, customer: null, customerIsContact: false, sending: false, booking: {}, ...state }
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

/**
 * @param {boolean} ok Whether the response is a success.
 * @param {Function} json What reading the body gives.
 * @return {object} A fetch response.
 */
function response(ok, json) {
	return { ok, json }
}

describe('the customer card', () => {
	it('takes the initials of the first and the last word, one for one word, none for a blank name', () => {
		expect(bind({ customer: { name: 'sanne  de vries' } }).customerInitials).toBe('SV')
		expect(bind({ customer: { fullName: 'Bakkerij' } }).customerInitials).toBe('B')
		expect(bind({ customer: { name: '   ' }, booking: {} }).customerInitials).toBe('')
		expect(bind({ customerLabel: '' }).customerInitials).toBe('')
	})

	it('names the customer, else the id, else a dash', () => {
		expect(bind({ customer: { name: 'Anna', fullName: 'Anna B' } }).customerLabel).toBe('Anna')
		expect(bind({ customer: { fullName: 'Anna B' } }).customerLabel).toBe('Anna B')
		expect(bind({ customer: {}, booking: { customerId: 'c9' } }).customerLabel).toBe('c9')
		expect(bind({}).customerLabel).toBe('-')
	})

	it('links a contact to its contact page and a client to its client page', () => {
		expect(bind({ customer: { name: 'A' }, customerIsContact: true, booking: { customerId: 'c1' } }).customerRoute)
			.toEqual({ name: 'ContactDetail', params: { id: 'c1' } })
		expect(bind({ customer: { name: 'A' }, booking: { customerId: 'c1' } }).customerRoute)
			.toEqual({ name: 'ClientDetail', params: { id: 'c1' } })
		expect(bind({ customer: null, booking: { customerId: 'c1' } }).customerRoute).toBeNull()
		expect(bind({ customer: { name: 'A' }, booking: {} }).customerRoute).toBeNull()
	})

	it('calls an organisation an organisation and anyone else a resident', () => {
		expect(bind({ customer: { type: 'organization' } }).customerKindLabel).toBe('Organisation')
		expect(bind({ customer: { type: 'person' } }).customerKindLabel).toBe('Resident')
		expect(bind({}).customerKindLabel).toBe('Resident')
	})
})

describe('the names it falls back to', () => {
	it('names the service, else its id, else a dash', () => {
		expect(bind({ service: { name: 'Knippen' }, booking: { serviceId: 's1' } }).serviceName).toBe('Knippen')
		expect(bind({ booking: { serviceId: 's1' } }).serviceName).toBe('s1')
		expect(bind({}).serviceName).toBe('-')
	})

	it('names a resource from the lookup, else its id, else a dash', () => {
		const ctx = bind({ resourceLookup: { r1: 'Stoel 1' } })
		expect(ctx.resourceName('r1')).toBe('Stoel 1')
		expect(ctx.resourceName('r2')).toBe('r2')
		expect(ctx.resourceName('')).toBe('-')
	})

	it('names a status by its label, else the status itself, else a dash', () => {
		const ctx = bind({})
		expect(ctx.statusLabel('pending-deposit')).toBe('Awaiting deposit')
		expect(ctx.statusLabel('archived')).toBe('archived')
		expect(ctx.statusLabel(undefined)).toBe('-')
	})

	it('lists only an array of assignments', () => {
		expect(bind({ booking: { resourceAssignments: [{ resourceId: 'r1' }] } }).assignments).toEqual([{ resourceId: 'r1' }])
		expect(bind({ booking: { resourceAssignments: 'r1' } }).assignments).toEqual([])
	})

	it('shows every block for all, and only the asked block otherwise', () => {
		expect(bind({ part: 'all' }).shows('timeline')).toBe(true)
		expect(bind({ part: 'timeline' }).shows('timeline')).toBe(true)
		expect(bind({ part: 'context' }).shows('timeline')).toBe(false)
	})
})

describe('the reminder', () => {
	it('can go out for a confirmed booking more than an hour away, and only then', () => {
		vi.useFakeTimers()
		vi.setSystemTime(new Date('2026-05-01T10:00:00Z'))
		expect(bind({ booking: { status: 'confirmed', startAt: '2026-05-01T11:00:01Z' } }).canSendReminder).toBe(true)
		expect(bind({ booking: { status: 'confirmed', startAt: '2026-05-01T11:00:00Z' } }).canSendReminder).toBe(false)
		expect(bind({ booking: { status: 'pending-deposit', startAt: '2026-05-02T10:00:00Z' } }).canSendReminder).toBe(false)
		expect(bind({ booking: { status: 'confirmed', startAt: 'not a date' } }).canSendReminder).toBe(false)
		expect(bind({ booking: { status: 'confirmed' } }).canSendReminder).toBe(false)
	})

	it('posts to the booking endpoint, says so and refreshes the page', async () => {
		const fetch = vi.fn().mockResolvedValue(response(true, async () => ({})))
		vi.stubGlobal('fetch', fetch)
		const ctx = bind({ resolvedId: 'b1' })
		const pending = ctx.sendReminder()
		expect(ctx.sending).toBe(true)
		await pending
		expect(fetch).toHaveBeenCalledWith('/index.php/apps/pipelinq/api/bookings/b1/send-reminder', {
			method: 'POST',
			headers: { 'Content-Type': 'application/json', requesttoken: 'tok', 'OCS-APIREQUEST': 'true' },
			body: '{}',
		})
		expect(dialogs.showSuccess).toHaveBeenCalledWith('Reminder dispatched.')
		expect(bus.emit).toHaveBeenCalledWith('cn:page:refresh', {})
		expect(ctx.sending).toBe(false)
	})

	it('shows the server error on a refusal, and its own words without one', async () => {
		vi.stubGlobal('fetch', vi.fn()
			.mockResolvedValueOnce(response(false, async () => ({ error: 'Too late for a reminder' })))
			.mockResolvedValueOnce(response(false, async () => { throw new Error('no json') })))
		const ctx = bind({ resolvedId: 'b1' })
		await ctx.sendReminder()
		await ctx.sendReminder()
		expect(dialogs.showError.mock.calls).toEqual([['Too late for a reminder'], ['Action failed.']])
		expect(dialogs.showSuccess).not.toHaveBeenCalled()
		expect(bus.emit).not.toHaveBeenCalled()
		expect(ctx.sending).toBe(false)
	})

	it('says it failed when the request does not get through', async () => {
		vi.stubGlobal('fetch', vi.fn().mockRejectedValue(new Error('offline')))
		const ctx = bind({ resolvedId: 'b1' })
		await ctx.sendReminder()
		expect(dialogs.showError).toHaveBeenCalledWith('Action failed.')
		expect(ctx.sending).toBe(false)
	})

	it('sends nothing without a booking id', async () => {
		const fetch = vi.fn()
		vi.stubGlobal('fetch', fetch)
		const ctx = bind({ resolvedId: '' })
		await ctx.sendReminder()
		expect(fetch).not.toHaveBeenCalled()
		expect(ctx.sending).toBe(false)
	})
})

describe('the deposit', () => {
	it('is none without an amount', () => {
		expect(bind({ booking: { depositAmount: 0 } }).depositLabel).toBe('None')
		expect(bind({ booking: {} }).depositLabel).toBe('None')
	})

	it('is pending in the service currency until it is paid', () => {
		const label = bind({ service: { currency: 'usd' }, booking: { depositAmount: '12.5' } }).depositLabel
		expect(label).toBe(new Intl.NumberFormat('nl-NL', { style: 'currency', currency: 'USD', maximumFractionDigits: 2 }).format(12.5) + ' (pending)')
	})

	it('says when it was paid, in the reporting currency without a service currency', () => {
		const paidAt = '2026-05-01T09:30:00Z'
		const label = bind({ booking: { depositAmount: 20, depositPaidAt: paidAt } }).depositLabel
		const amount = new Intl.NumberFormat('nl-NL', { style: 'currency', currency: 'EUR', maximumFractionDigits: 2 }).format(20)
		expect(label).toBe(`${amount} (paid ${new Date(paidAt).toLocaleString('nl-NL')})`)
	})
})

describe('the timeline as rendered', () => {
	it('puts Now between the past and the upcoming events, with each event its icon', () => {
		vi.useFakeTimers()
		vi.setSystemTime(new Date('2026-05-01T12:00:00Z'))
		const rows = bind({ booking: {
			confirmationSentAt: '2026-04-30T08:00:00Z',
			startAt: '2026-05-01T14:00:00Z',
			endAt: '2026-05-01T15:00:00Z',
			statusHistory: [{ status: 'confirmed', changedAt: '2026-04-30T09:00:00Z' }],
		} }).timelineWithNow
		expect(rows.map((r) => [r.kind, r.text, r.upcoming])).toEqual([
			['email', 'Confirmation email sent', false],
			['status', 'Status: Confirmed', false],
			['now', 'Now', false],
			['start', 'Booking starts', true],
			['end', 'Booking ends', true],
		])
		expect(rows[2].icon).toBeNull()
		expect(rows.filter((r) => r.kind !== 'now').every((r) => r.icon && typeof r.icon === 'object')).toBe(true)
		expect(rows[0].icon).not.toBe(rows[3].icon)
	})

	it('has no Now marker when everything is past or everything is ahead', () => {
		vi.useFakeTimers()
		vi.setSystemTime(new Date('2026-05-01T12:00:00Z'))
		const past = bind({ booking: { startAt: '2026-04-01T10:00:00Z', endAt: '2026-04-01T11:00:00Z' } }).timelineWithNow
		const ahead = bind({ booking: { startAt: '2026-06-01T10:00:00Z', endAt: '2026-06-01T11:00:00Z' } }).timelineWithNow
		expect(past.map((r) => r.kind)).toEqual(['start', 'end'])
		expect(past.every((r) => r.upcoming === false)).toBe(true)
		expect(ahead.map((r) => r.kind)).toEqual(['start', 'end'])
		expect(ahead.every((r) => r.upcoming === true)).toBe(true)
	})
})
