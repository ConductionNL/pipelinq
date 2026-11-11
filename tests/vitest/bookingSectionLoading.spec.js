// @vitest-environment jsdom
/**
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * How the two booking body sections find and read their booking: the id from
 * the prop or the page's section context, the spinner, the error toast, and
 * the silent re-read on `cn:page:refresh`.
 *
 * @spec openspec/specs/appointment-booking/spec.md
 */
import { flushPromises, mount } from '@vue/test-utils'
import { beforeEach, describe, expect, it, vi } from 'vitest'

const bus = vi.hoisted(() => ({ subscribe: vi.fn(), unsubscribe: vi.fn() }))
const dialogs = vi.hoisted(() => ({ showError: vi.fn(), showSuccess: vi.fn() }))
const store = vi.hoisted(() => ({ fetchObject: vi.fn() }))

vi.mock('@nextcloud/event-bus', () => bus)
vi.mock('@nextcloud/dialogs', () => dialogs)
vi.mock('../../src/store/modules/object.js', () => ({ useObjectStore: () => store }))
vi.mock('@nextcloud/vue', () => ({ NcButton: { template: '<button><slot /></button>' }, NcLoadingIcon: { template: '<i class="spinner" />' } }))
vi.mock('@conduction/nextcloud-vue', () => ({ CnStatusBadge: { props: ['label'], template: '<span>{{ label }}</span>' } }))

globalThis.t = (_app, text, vars = {}) => text.replace(/\{(\w+)\}/g, (m, k) => vars[k] ?? m)

const sections = {
	BookingAssignments: (await import('../../src/components/bookings/BookingAssignments.vue')).default,
	BookingDetailSection: (await import('../../src/components/bookings/BookingDetailSection.vue')).default,
}

/**
 * @param {object} component The section.
 * @param {object} options Mount props and the provided section context.
 * @return {object} The wrapper.
 */
function mountSection(component, options) {
	return mount(component, {
		props: options.props || {},
		global: {
			mocks: { t: globalThis.t },
			provide: 'context' in options ? { cnSectionContext: options.context } : {},
			stubs: { MapMarkerOutline: true },
		},
	})
}

beforeEach(() => {
	bus.subscribe.mockReset()
	bus.unsubscribe.mockReset()
	dialogs.showError.mockReset()
	store.fetchObject.mockReset()
	store.fetchObject.mockImplementation(async (schema) => (schema === 'appointmentBooking' ? { id: 'b1', resourceAssignments: [] } : null))
})

describe.each(Object.keys(sections))('%s', (name) => {
	const component = sections[name]

	it('reads the booking named by the prop before the section context', async () => {
		mountSection(component, { props: { bookingId: 'b1' }, context: { objectId: 'other' } })
		await flushPromises()
		expect(store.fetchObject).toHaveBeenCalledWith('appointmentBooking', 'b1')
		expect(store.fetchObject).not.toHaveBeenCalledWith('appointmentBooking', 'other')
	})

	it('reads the id from a section context ref, or from a plain one', async () => {
		mountSection(component, { context: { value: { objectId: 'from-ref' } } })
		mountSection(component, { context: { objectId: 'from-plain' } })
		await flushPromises()
		expect(store.fetchObject).toHaveBeenCalledWith('appointmentBooking', 'from-ref')
		expect(store.fetchObject).toHaveBeenCalledWith('appointmentBooking', 'from-plain')
	})

	it('reads nothing without an id', async () => {
		const wrapper = mountSection(component, { context: { value: null } })
		await flushPromises()
		expect(store.fetchObject).not.toHaveBeenCalled()
		expect(wrapper.vm.loading).toBe(false)
	})

	it('shows the spinner while it reads, and the server error when it fails', async () => {
		let release
		store.fetchObject.mockImplementation(() => new Promise((resolve, reject) => { release = reject }))
		const wrapper = mountSection(component, { props: { bookingId: 'b1' } })
		await flushPromises()
		expect(wrapper.vm.loading).toBe(true)
		release({ response: { data: { error: 'Gone' } } })
		await flushPromises()
		expect(wrapper.vm.loading).toBe(false)
		expect(dialogs.showError).toHaveBeenCalledWith('Gone')
	})

	it('falls back to its own words when the server gives no error', async () => {
		store.fetchObject.mockRejectedValue(new Error('offline'))
		mountSection(component, { props: { bookingId: 'b1' } })
		await flushPromises()
		expect(dialogs.showError).toHaveBeenCalledWith('Could not load booking.')
	})

	it('re-reads silently on a page refresh, and stops listening when it goes', async () => {
		const wrapper = mountSection(component, { props: { bookingId: 'b1' } })
		await flushPromises()
		expect(bus.subscribe).toHaveBeenCalledWith('cn:page:refresh', wrapper.vm.onPageRefresh)
		store.fetchObject.mockClear()
		let pending
		store.fetchObject.mockImplementation(() => new Promise((resolve) => { pending = resolve }))
		const waitUntil = vi.fn()
		wrapper.vm.onPageRefresh({ waitUntil })
		expect(wrapper.vm.loading).toBe(false)
		expect(waitUntil).toHaveBeenCalledWith(expect.any(Promise))
		expect(store.fetchObject).toHaveBeenCalledWith('appointmentBooking', 'b1')
		pending({ id: 'b1', resourceAssignments: [] })
		await flushPromises()
		wrapper.vm.onPageRefresh()
		const handler = wrapper.vm.onPageRefresh
		wrapper.unmount()
		expect(bus.unsubscribe).toHaveBeenCalledWith('cn:page:refresh', handler)
	})

	it('keeps an empty booking when the store has none', async () => {
		store.fetchObject.mockResolvedValue(null)
		const wrapper = mountSection(component, { props: { bookingId: 'b1' } })
		await flushPromises()
		expect(wrapper.vm.booking).toEqual({})
	})
})
