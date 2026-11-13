// @vitest-environment jsdom
/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Which booking-admin actions the booking header enables, by status and by
 * whether the booking has ended: Confirm deposit for a booking awaiting its
 * deposit, Mark completed / no-show for a confirmed booking that has ended,
 * Reschedule / Cancel for an open booking still ahead. Times are relative to
 * now, so the outcome does not depend on the date the suite runs. Clicking
 * an action posts it for this booking; the reschedule and cancel dialogs post
 * what they collect.
 *
 * @spec openspec/specs/appointment-booking/spec.md
 */

import { flushPromises, mount, shallowMount } from '@vue/test-utils'
import { beforeEach, describe, expect, it, vi } from 'vitest'

vi.mock('@nextcloud/event-bus', () => ({ emit: vi.fn(), subscribe: vi.fn(), unsubscribe: vi.fn() }))
const posted = vi.hoisted(() => ({ postAction: vi.fn() }))
vi.mock('../../src/services/postedAction.js', () => posted)
vi.mock('@nextcloud/router', async (importOriginal) => ({
	...(await importOriginal()),
	generateUrl: (path) => '/index.php' + path,
}))

globalThis.t = globalThis.t || ((app, text) => text)

const { default: BookingHeaderActions } =
	await import('../../src/components/bookings/BookingHeaderActions.vue')

const HOUR = 3600 * 1000
const ACTIONS = ['confirm-deposit', 'complete', 'no-show', 'reschedule', 'cancel']

/**
 * The enabled actions for a booking.
 *
 * @param {object} booking The booking object.
 * @return {Array<string>} The enabled action ids, in button order.
 */
function enabled(booking) {
	const wrapper = shallowMount(BookingHeaderActions, {
		props: { object: { id: 'b1', ...booking } },
		global: { mocks: { t: globalThis.t } },
	})
	return ACTIONS.filter((action) => {
		const button = wrapper.find(`[data-testid="booking-action-${action}"]`)
		return button.attributes('disabled') === undefined || button.attributes('disabled') === 'false'
	})
}

const past = () => new Date(Date.now() - HOUR).toISOString()
const future = () => new Date(Date.now() + HOUR).toISOString()

describe('BookingHeaderActions availability', () => {
	it('lets a confirmed booking still ahead be rescheduled or cancelled', () => {
		expect(enabled({ status: 'confirmed', endAt: future() })).toEqual(['reschedule', 'cancel'])
	})

	it('lets a confirmed booking that has ended be completed or marked no-show', () => {
		expect(enabled({ status: 'confirmed', endAt: past() })).toEqual(['complete', 'no-show'])
	})

	it('lets a booking awaiting its deposit be confirmed, and still moved while ahead', () => {
		expect(enabled({ status: 'pending-deposit', endAt: future() }))
			.toEqual(['confirm-deposit', 'reschedule', 'cancel'])
		expect(enabled({ status: 'pending-deposit', endAt: past() })).toEqual(['confirm-deposit'])
	})

	it('enables nothing for a closed booking', () => {
		expect(enabled({ status: 'cancelled', endAt: future() })).toEqual([])
		expect(enabled({ status: 'completed', endAt: past() })).toEqual([])
	})

	it('treats a booking without an end as neither ahead nor ended', () => {
		expect(enabled({ status: 'confirmed' })).toEqual([])
		const ctx = { booking: {} }
		expect(BookingHeaderActions.computed.isFuture.call(ctx)).toBe(false)
		expect(BookingHeaderActions.computed.isPast.call(ctx)).toBe(false)
	})

	it('counts a booking ending right now as ended', () => {
		const now = Date.now()
		vi.spyOn(Date, 'now').mockReturnValue(now)
		const ctx = { booking: { endAt: new Date(now).toISOString() } }

		expect(BookingHeaderActions.computed.isPast.call(ctx)).toBe(true)
		expect(BookingHeaderActions.computed.isFuture.call(ctx)).toBe(false)
		vi.restoreAllMocks()
	})
})

const stubs = {
	NcButton: {
		props: ['disabled'],
		emits: ['click'],
		template: '<button :disabled="disabled" @click="$emit(\'click\')"><slot name="icon" /><slot /></button>',
	},
	RescheduleBookingDialog: {
		name: 'RescheduleBookingDialog',
		props: ['currentStartAt', 'currentEndAt', 'subject'],
		emits: ['confirm', 'cancel'],
		template: '<div class="reschedule-stub" />',
	},
	CancelBookingDialog: {
		name: 'CancelBookingDialog',
		props: ['booking'],
		emits: ['confirm', 'cancel'],
		template: '<div class="cancel-stub" />',
	},
}

/**
 * Mount the header with rendering stubs for a booking.
 *
 * @param {object} booking The booking object.
 * @return {import('@vue/test-utils').VueWrapper}
 */
function mountHeader(booking) {
	return mount(BookingHeaderActions, {
		props: { object: { id: 'b1', ...booking } },
		global: { stubs, mocks: { t: globalThis.t } },
	})
}

describe('BookingHeaderActions actions', () => {
	beforeEach(() => {
		posted.postAction.mockReset()
		posted.postAction.mockResolvedValue(undefined)
	})

	it.each([
		['confirm-deposit', 'pending-deposit', past, 'Deposit confirmed.'],
		['complete', 'confirmed', past, 'Booking marked completed.'],
		['no-show', 'confirmed', past, 'Booking marked as no-show.'],
	])('posts %s for this booking', async (action, status, end, message) => {
		const wrapper = mountHeader({ status, endAt: end() })

		await wrapper.find(`[data-testid="booking-action-${action}"]`).trigger('click')
		await flushPromises()

		expect(posted.postAction).toHaveBeenCalledTimes(1)
		const [url, options] = posted.postAction.mock.calls[0]
		expect(url).toBe(`/index.php/apps/pipelinq/api/bookings/b1/${action}`)
		expect(options).toMatchObject({ body: {}, successMessage: message })
		expect(wrapper.vm.busy).toBe(false)
	})

	it('opens the reschedule dialog with the booking times and posts the new start', async () => {
		const endAt = future()
		const wrapper = mountHeader({ status: 'confirmed', startAt: '2030-01-01T10:00', endAt, subject: 'Intake' })

		await wrapper.find('[data-testid="booking-action-reschedule"]').trigger('click')
		const dialog = wrapper.findComponent({ name: 'RescheduleBookingDialog' })
		expect(dialog.props()).toEqual({ currentStartAt: '2030-01-01T10:00', currentEndAt: endAt, subject: 'Intake' })

		dialog.vm.$emit('confirm', '2030-01-02T10:00')
		await flushPromises()

		expect(wrapper.findComponent({ name: 'RescheduleBookingDialog' }).exists()).toBe(false)
		const [url, options] = posted.postAction.mock.calls[0]
		expect(url).toBe('/index.php/apps/pipelinq/api/bookings/b1/reschedule')
		expect(options).toMatchObject({ body: { newStartAt: '2030-01-02T10:00' }, successMessage: 'Booking rescheduled.' })
	})

	it('closes the reschedule dialog on cancel without posting', async () => {
		const wrapper = mountHeader({ status: 'confirmed', endAt: future() })

		await wrapper.find('[data-testid="booking-action-reschedule"]').trigger('click')
		wrapper.findComponent({ name: 'RescheduleBookingDialog' }).vm.$emit('cancel')
		await flushPromises()

		expect(wrapper.findComponent({ name: 'RescheduleBookingDialog' }).exists()).toBe(false)
		expect(posted.postAction).not.toHaveBeenCalled()
	})

	it('passes empty times and subject to the reschedule dialog when missing', async () => {
		const wrapper = mountHeader({ status: 'confirmed', endAt: future() })
		await wrapper.setData({ showReschedule: true })

		expect(wrapper.findComponent({ name: 'RescheduleBookingDialog' }).props('currentStartAt')).toBe('')
		expect(wrapper.findComponent({ name: 'RescheduleBookingDialog' }).props('subject')).toBe('')
	})

	it('posts a cancellation with the reason, or an empty one', async () => {
		const wrapper = mountHeader({ status: 'confirmed', endAt: future() })

		await wrapper.find('[data-testid="booking-action-cancel"]').trigger('click')
		const dialog = wrapper.findComponent({ name: 'CancelBookingDialog' })
		expect(dialog.props('booking')).toMatchObject({ id: 'b1', status: 'confirmed' })
		dialog.vm.$emit('confirm', 'Client ill')
		await flushPromises()

		await wrapper.find('[data-testid="booking-action-cancel"]').trigger('click')
		wrapper.findComponent({ name: 'CancelBookingDialog' }).vm.$emit('confirm', undefined)
		await flushPromises()

		expect(posted.postAction.mock.calls.map(([, o]) => o.body)).toEqual([{ reason: 'Client ill' }, { reason: '' }])
		expect(posted.postAction.mock.calls[0][1].successMessage).toBe('Booking cancelled.')
	})

	it('closes the cancel dialog on cancel without posting', async () => {
		const wrapper = mountHeader({ status: 'confirmed', endAt: future() })

		await wrapper.find('[data-testid="booking-action-cancel"]').trigger('click')
		wrapper.findComponent({ name: 'CancelBookingDialog' }).vm.$emit('cancel')
		await flushPromises()

		expect(wrapper.findComponent({ name: 'CancelBookingDialog' }).exists()).toBe(false)
		expect(posted.postAction).not.toHaveBeenCalled()
	})

	it('renders nothing without a booking id', () => {
		const wrapper = mount(BookingHeaderActions, { global: { stubs, mocks: { t: globalThis.t } } })

		expect(wrapper.find('[data-testid="booking-header-actions"]').exists()).toBe(false)
	})
})

