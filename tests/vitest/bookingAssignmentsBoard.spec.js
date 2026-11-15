// @vitest-environment jsdom
/**
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * PqAfspraak's Toegewezen resources as the board draws it: a titled card with
 * Resource, Type, Van, Aan and Status, the times as "14:00", and a status pill
 * (a person is assigned, a room or equipment is reserved). The booking's
 * status history reads in words.
 *
 * @spec openspec/specs/appointment-booking/spec.md
 */
import { flushPromises, mount } from '@vue/test-utils'
import { describe, expect, it, vi } from 'vitest'
import fragment from '../../src/manifest.d/80-appointment-booking-admin.json'

const at = (h, m) => new Date(2026, 9, 8, h, m).toISOString()
const records = {
	appointmentBooking: {
		id: 'b1',
		startAt: at(14, 0),
		resourceAssignments: [
			{ stepIndex: 0, resourceId: 'r1', startAt: at(14, 0), endAt: at(15, 0) },
			{ stepIndex: 0, resourceId: 'r2', startAt: at(13, 40), endAt: at(15, 20) },
		],
	},
	r1: { id: 'r1', name: 'Joris Bakker', type: 'staff' },
	r2: { id: 'r2', name: 'Dienstauto 2', type: 'equipment' },
}
vi.mock('../../src/store/modules/object.js', () => ({
	useObjectStore: () => ({ fetchObject: vi.fn(async (schema, id) => (schema === 'appointmentBooking' ? records.appointmentBooking : records[id] || null)) }),
}))
vi.mock('@nextcloud/vue', () => ({ NcLoadingIcon: { template: '<i />' } }))
vi.mock('@nextcloud/dialogs', () => ({ showError: vi.fn() }))
vi.mock('@conduction/nextcloud-vue', () => ({
	CnStatusBadge: { props: ['label', 'variant'], template: '<span class="badge" :data-variant="variant">{{ label }}</span>' },
}))

globalThis.t = (_app, text) => text
const { default: BookingAssignments } = await import('../../src/components/bookings/BookingAssignments.vue')

const config = fragment.pages.find((p) => p.id === 'BookingDetail').config

describe('the booking\'s assigned resources', () => {
	it('lists resource, type, from, to and status, with times as 14:00', async () => {
		const wrapper = mount(BookingAssignments, { props: { bookingId: 'b1' }, global: { mocks: { t: globalThis.t } } })
		await flushPromises()
		expect(wrapper.findAll('th').map((th) => th.text())).toEqual(['Resource', 'Type', 'From', 'To', 'Status'])
		const rows = wrapper.findAll('tbody tr').map((tr) => tr.findAll('td').map((td) => td.text()))
		expect(rows).toEqual([
			['Joris Bakker', 'Staff', '14:00', '15:00', 'Assigned'],
			['Dienstauto 2', 'Equipment', '13:40', '15:20', 'Reserved'],
		])
		expect(wrapper.findAll('.badge').map((b) => b.attributes('data-variant'))).toEqual(['success', 'info'])
	})

	it('names the step only when the booking has more than one', async () => {
		const wrapper = mount(BookingAssignments, { props: { bookingId: 'b1' }, global: { mocks: { t: globalThis.t } } })
		await flushPromises()
		expect(wrapper.text()).not.toContain('Step')
	})

	it('is a titled card on the page, with the board\'s sub line', () => {
		const widget = config.widgets.find((w) => w.id === 'booking-assignments')
		expect(widget.content.title).toBe('Assigned resources')
		expect(widget.content.subtitle).toBe('Who and what is booked, from the Resources tab')
	})

	it('words the status history instead of printing the stored value', () => {
		const timeline = config.widgets.find((w) => w.id === 'booking-timeline').content
		const history = timeline.lists.find((l) => l.field === 'statusHistory')
		const statuses = ['pending-deposit', 'confirmed', 'completed', 'no-show', 'cancelled-by-customer', 'cancelled-by-business', 'rescheduled']
		expect(Object.keys(history.valueLabels).sort()).toEqual([...statuses].sort())
		expect(history.valueLabels.confirmed).toBe('Status: Confirmed')
	})
})
