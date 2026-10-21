/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 * SPDX-License-Identifier: EUPL-1.2
 *
 * The booking page's six admin actions are declared header actions, gated by
 * status and time, one primary per state, instead of BookingHeaderActions.
 *
 * @spec openspec/changes/pl2-booking-header-actions/specs/appointment-booking/spec.md#requirement-the-booking-header-declares-its-time-gated-actions
 */
import { existsSync, readFileSync } from 'node:fs'
import { resolve } from 'node:path'
import { describe, expect, it } from 'vitest'

const root = resolve(__dirname, '../..')
const fragment = JSON.parse(readFileSync(resolve(root, 'src/manifest.d/80-appointment-booking-admin.json'), 'utf8'))
const page = fragment.pages.find((p) => p.id === 'BookingDetail')
const action = (id) => page.config.headerActions.find((a) => a.id === id)

describe('the booking header', () => {
	it('has one primary action per state and no component of its own', () => {
		expect(page.actionsComponent).toBeUndefined()
		expect(page.config.primaryActionByStage).toEqual({ 'pending-deposit': 'booking-confirm-deposit', confirmed: 'booking-complete' })
	})

	it('completes or marks a no-show only once a confirmed booking has ended', () => {
		for (const id of ['booking-complete', 'booking-no-show']) {
			expect(action(id).visibleWhen.all).toEqual([{ field: 'status', value: 'confirmed' }, { field: 'endAt', op: 'lte', value: '@now' }])
		}
	})

	it('sends a reminder only while the booking starts more than an hour from now', () => {
		expect(action('booking-send-reminder').visibleWhen.all[1]).toEqual({ field: 'startAt', op: 'gt', value: '@now+1h' })
	})

	it('reschedules to a required new start and opens the new booking', () => {
		const reschedule = action('booking-reschedule')
		expect(reschedule.endpoint).toEqual({ url: '/apps/pipelinq/api/bookings/@objectId/reschedule', method: 'POST' })
		expect(reschedule.formProperties.newStartAt).toMatchObject({ format: 'date-time', required: true })
		expect(reschedule.onSuccessRoute).toEqual({ name: 'BookingDetail', idPath: 'bookingId' })
	})

	it.each([
		'src/components/bookings/BookingHeaderActions.vue',
		'src/dialogs/RescheduleBookingDialog.vue',
		'src/dialogs/CancelBookingDialog.vue',
	])('%s is deleted', (file) => {
		expect(existsSync(resolve(root, file))).toBe(false)
	})
})
