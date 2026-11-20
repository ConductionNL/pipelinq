// @vitest-environment jsdom
/**
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * PqAfspraak's side cards as pl2 measured them: the customer card is titled by
 * what the customer is ("Inwoner") and its sub line names it again before the
 * address; the when-and-where card has its title.
 *
 * @spec openspec/specs/appointment-booking/spec.md
 */
import { flushPromises, mount } from '@vue/test-utils'
import { describe, expect, it, vi } from 'vitest'

const records = {
	appointmentBooking: { id: 'b1', customerId: 'c1', startAt: '2026-10-28T13:00:00Z', endAt: '2026-10-28T14:00:00Z', location: 'Parkstraat 24' },
	client: { id: 'c1', name: 'Sanne de Vries', type: 'person', address: 'Lindelaan 14' },
}
vi.mock('../../src/store/modules/object.js', () => ({
	useObjectStore: () => ({ fetchObject: vi.fn(async (schema) => (schema === 'contact' ? null : records[schema] || null)) }),
}))
vi.mock('@nextcloud/vue', () => ({ NcButton: { template: '<button><slot /></button>' }, NcLoadingIcon: { template: '<i />' } }))

const dutch = { Resident: 'Inwoner', 'When and where': 'Wanneer en waar' }
globalThis.t = (_app, text) => dutch[text] || text
const { default: BookingDetailSection } = await import('../../src/components/bookings/BookingDetailSection.vue')

/**
 * @param {string} part The section part.
 * @return {Promise<object>} The mounted section.
 */
async function mountPart(part) {
	const wrapper = mount(BookingDetailSection, {
		props: { bookingId: 'b1', part },
		global: { mocks: { t: globalThis.t }, stubs: { teleport: true } },
	})
	await flushPromises()
	return wrapper
}

describe('the booking side cards', () => {
	it('titles the customer card by what the customer is, and repeats it before the address', async () => {
		const wrapper = await mountPart('customer')
		expect(wrapper.find('.booking-section__title').text()).toBe('Inwoner')
		expect(wrapper.find('.booking-person__sub').text()).toBe('Inwoner · Lindelaan 14')
	})

	it('titles the when-and-where card', async () => {
		const wrapper = await mountPart('when')
		expect(wrapper.find('.booking-section__title').text()).toBe('Wanneer en waar')
	})
})
