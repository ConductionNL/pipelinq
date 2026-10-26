/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 * SPDX-License-Identifier: EUPL-1.2
 *
 * The cash drawer list and the refund totals are manifest configuration.
 *
 * @spec openspec/changes/pl2-pos-sections/specs/pos-cash-management/spec.md#requirement-a-shift-is-opened-from-the-cash-drawer-list
 */
import { existsSync, readFileSync } from 'node:fs'
import { resolve } from 'node:path'
import { describe, expect, it } from 'vitest'

const root = resolve(__dirname, '../..')
const manifest = JSON.parse(readFileSync(resolve(root, 'src/manifest.json'), 'utf8'))
const page = (id) => manifest.pages.find((p) => p.id === id)

describe('the cash drawer list', () => {
	const shifts = page('CashShifts')

	it('is an index page that opens a shift through the server', () => {
		expect(shifts.type).toBe('index')
		expect(shifts.component).toBeUndefined()
		expect(shifts.config.rowRoute).toBe('CashShiftDetail')
		const open = shifts.config.headerActions.find((a) => a.id === 'open-shift')
		expect(open.endpoint).toEqual({ url: '/apps/pipelinq/api/pos-shifts', method: 'POST' })
		expect(open.formProperties.drawer.required).toBe(true)
		expect(open.formProperties.floatAmount.required).toBe(true)
		expect(open.successMessage).toBe('Shift opened.')
	})
})

describe('the refund totals', () => {
	// @spec openspec/changes/pl2-pos-sections/specs/pos-refund-return/spec.md#requirement-refund-totals-show-what-the-server-booked
	it('read the amounts the server stored', () => {
		const totals = page('PosRefundDetail').config.widgets.find((w) => w.id === 'pos-refund-totals')
		expect(totals.type).toBe('facts')
		expect(totals.content.fields.map((f) => [f.path, f.format])).toEqual([['refundAmount', 'currency'], ['totalTax', 'currency'], ['paymentMethod', undefined]])
	})
})

describe('the booking context', () => {
	// @spec openspec/changes/pl2-pos-sections/specs/appointment-booking/spec.md#requirement-the-booking-context-names-the-service-and-the-customer
	it('is a facts card that names the service and the customer', () => {
		const fragment = JSON.parse(readFileSync(resolve(root, 'src/manifest.d/80-appointment-booking-admin.json'), 'utf8'))
		const booking = fragment.pages.find((p) => p.id === 'BookingDetail')
		const context = booking.config.widgets.find((w) => w.id === 'booking-context')
		expect(context.type).toBe('facts')
		expect(context.content.fields.map((f) => f.resolve?.schema).filter(Boolean)).toEqual(['appointmentService', 'contact', 'client'])
		expect(context.content.link.route).toEqual({ name: 'BookingDetail', params: { id: '@source.previousBookingId' } })
	})
})

describe('the replaced code', () => {
	it.each([
		'src/views/pos/CashShiftList.vue',
		'src/modals/CashShiftOpenDialog.vue',
		'src/components/pos/PosRefundTotalsWidget.vue',
		'src/components/bookings/BookingDetailSection.vue',
	])('%s is deleted', (file) => {
		expect(existsSync(resolve(root, file))).toBe(false)
	})
})
