// @vitest-environment jsdom
/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 * SPDX-License-Identifier: EUPL-1.2
 *
 * The public booking portal mounted and walked through: the service header,
 * choosing a date and a time, the form's validation and the booking request,
 * and the states for a missing service or a failed load.
 *
 * @spec openspec/specs/appointment-booking/spec.md
 */

import { flushPromises, mount } from '@vue/test-utils'
import { beforeEach, describe, expect, it, vi } from 'vitest'

const http = vi.hoisted(() => ({ get: vi.fn(), post: vi.fn() }))

vi.hoisted(() => {
	globalThis.t = (app, text, vars = {}) => String(text).replace(/\{(\w+)\}/g, (m, k) => (k in vars ? String(vars[k]) : m))
	globalThis.n = (app, one, many, count) => (count === 1 ? one : many).replace('%n', String(count))
})

vi.mock('@nextcloud/axios', () => ({ default: http }))
vi.mock('@nextcloud/router', () => ({ generateUrl: (path) => '/index.php' + path }))

const { default: BookingPortal } = await import('../../src/views/portal/BookingPortal.vue')

const SERVICE = { id: 's1', slug: 'intake', name: 'Intake', description: 'First talk', durationMinutes: 30, price: 0 }
const BASE = '/index.php/apps/pipelinq/portal/api/booking'

/**
 * Mount the portal for a service slug.
 *
 * @param {Array<object>} services What the services endpoint answers.
 * @return {Promise<object>} The wrapper and the router double.
 */
async function open(services = [SERVICE]) {
	http.get.mockImplementation(async (url, config) => {
		if (url === BASE + '/services') {
			return { data: services }
		}
		if (config.params.date === '2026-11-21') {
			return { data: [] }
		}
		return { data: { slots: [{ startAt: '2026-11-20T09:00:00Z' }, { startAt: '2026-11-20T09:30:00Z' }] } }
	})
	const router = { push: vi.fn() }
	const wrapper = mount(BookingPortal, {
		global: { mocks: { t: globalThis.t, n: globalThis.n, $route: { params: { serviceSlug: 'intake' } }, $router: router } },
	})
	await flushPromises()
	return { wrapper, router }
}

/**
 * Choose a date and the first time.
 *
 * @param {object} wrapper The portal.
 */
async function chooseFirstSlot(wrapper) {
	await wrapper.find('#booking-date').setValue('2026-11-20')
	await wrapper.find('#booking-date').trigger('change')
	await flushPromises()
	await wrapper.find('.booking-slot').trigger('click')
}

beforeEach(() => {
	vi.resetAllMocks()
})

describe('BookingPortal', () => {
	it('shows the service with its duration and no price when free', async () => {
		const { wrapper } = await open()
		expect(wrapper.find('h1').text()).toBe('Intake')
		expect(wrapper.find('.booking-description').text()).toBe('First talk')
		expect(wrapper.findAll('.booking-meta-item').map((i) => i.find('dd').text())).toEqual(['30 minutes'])
		expect(wrapper.find('.booking-hint').text()).toBe('Pick a date to see available times.')
	})

	it('shows a price for a paid service', async () => {
		const { wrapper } = await open([{ ...SERVICE, price: '12.5', currency: 'EUR' }])
		expect(wrapper.findAll('.booking-meta-item')).toHaveLength(2)
		expect(wrapper.findAll('.booking-meta-item')[1].find('dd').text()).toMatch(/12[.,]50/)
	})

	it('says the service could not be found for an unknown slug', async () => {
		const { wrapper } = await open([])
		expect(wrapper.find('[role="alert"]').text()).toBe('This service could not be found.')
	})

	it('shows the server message when the service cannot load', async () => {
		http.get.mockRejectedValue({ response: { status: 500, data: { message: 'Portal closed' } } })
		const wrapper = mount(BookingPortal, { global: { mocks: { t: globalThis.t, n: globalThis.n, $route: { params: { serviceSlug: 'x' } } } } })
		await flushPromises()
		expect(wrapper.find('.booking-error').text()).toBe('Portal closed')
	})

	it('asks the times of a chosen date and lists them', async () => {
		const { wrapper } = await open()
		await wrapper.find('#booking-date').setValue('2026-11-20')
		await wrapper.find('#booking-date').trigger('change')
		await flushPromises()
		expect(http.get).toHaveBeenLastCalledWith(BASE + '/availability', { params: { serviceId: 's1', date: '2026-11-20' } })
		expect(wrapper.findAll('.booking-slot')).toHaveLength(2)
		expect(wrapper.vm.availableDates).toEqual(['2026-11-20'])
		expect(wrapper.find('.booking-hint').text()).toBe('Dates without available times cannot be booked.')
		const slot = wrapper.find('.booking-slot')
		await slot.trigger('click')
		expect(slot.attributes('aria-pressed')).toBe('true')
		expect(wrapper.find('#booking-form').exists()).toBe(true)
	})

	it('says when a date has no times', async () => {
		const { wrapper } = await open()
		await wrapper.find('#booking-date').setValue('2026-11-21')
		await wrapper.find('#booking-date').trigger('change')
		await flushPromises()
		expect(wrapper.find('[role="status"]').text()).toBe('No available times on this date. Please choose another date.')
		expect(wrapper.vm.availableDates).toEqual([])
	})

	it('refuses a form without a name or a valid email, sending nothing', async () => {
		const { wrapper } = await open()
		await chooseFirstSlot(wrapper)
		await wrapper.find('form').trigger('submit')
		expect(wrapper.find('#booking-name-error').text()).toBe('Please enter your name.')
		expect(wrapper.find('#booking-email-error').text()).toBe('Please enter your email address.')
		await wrapper.find('#booking-name').setValue('Jan')
		await wrapper.find('#booking-email').setValue('jan@')
		await wrapper.find('form').trigger('submit')
		expect(wrapper.find('#booking-name-error').exists()).toBe(false)
		expect(wrapper.find('#booking-email-error').text()).toBe('Please enter a valid email address.')
		expect(wrapper.find('#booking-email').attributes('aria-invalid')).toBe('true')
		expect(http.post).not.toHaveBeenCalled()
	})

	it('books the chosen time and opens the confirmation', async () => {
		const { wrapper, router } = await open()
		await chooseFirstSlot(wrapper)
		await wrapper.find('#booking-name').setValue(' Jan ')
		await wrapper.find('#booking-email').setValue('jan@example.org')
		http.post.mockResolvedValueOnce({ data: { bookingId: 'b 1' } })
		await wrapper.find('form').trigger('submit')
		await flushPromises()
		expect(http.post).toHaveBeenCalledWith(BASE + '/book', {
			serviceId: 's1',
			startAt: '2026-11-20T09:00:00Z',
			name: 'Jan',
			email: 'jan@example.org',
			phone: null,
			notes: null,
		})
		expect(router.push).toHaveBeenCalledWith('/booking-confirmation/b%201')
	})

	it('says a taken time was just taken', async () => {
		const { wrapper, router } = await open()
		await chooseFirstSlot(wrapper)
		await wrapper.find('#booking-name').setValue('Jan')
		await wrapper.find('#booking-email').setValue('jan@example.org')
		http.post.mockRejectedValueOnce({ response: { status: 409 } })
		await wrapper.find('form').trigger('submit')
		await flushPromises()
		expect(wrapper.find('form .booking-error').text()).toBe('That time was just taken. Please choose another slot.')
		expect(router.push).not.toHaveBeenCalled()
		expect(wrapper.find('.booking-button-primary').attributes('disabled')).toBeUndefined()
	})
})
