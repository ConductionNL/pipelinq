// @vitest-environment jsdom
/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 * SPDX-License-Identifier: EUPL-1.2
 *
 * The customer's bookings card names each booking's service and resource,
 * reading every name it does not know yet once, and tolerating a failure.
 *
 * @spec openspec/changes/appointment-booking-11-admin-ui/tasks.md
 */
import { beforeAll, describe, expect, it, vi } from 'vitest'

vi.mock('@conduction/nextcloud-vue', () => ({ CnDetailCard: {}, openRowTarget: vi.fn() }))
vi.mock('@nextcloud/vue', () => ({ NcLoadingIcon: {} }))
vi.mock('../../src/store/modules/object.js', () => ({ useObjectStore: () => ({}) }))

let card

beforeAll(async () => {
	globalThis.t = (app, text) => text
	card = (await import('../../src/components/bookings/BookingsCard.vue')).default
}, 60000)

describe('BookingsCard.primeLabels', () => {
	it('reads each unknown service and resource name once, services first', async () => {
		const fetchObject = vi.fn(async (type, id) => {
			if (id === 'bad') throw new Error('x')
			return { s1: { name: 'Haircut' }, s2: {}, r1: { name: 'Chair' }, r2: { name: 'Room' } }[id] ?? null
		})
		const ctx = {
			objectStore: { fetchObject },
			serviceLookup: { known: 'Known' },
			resourceLookup: {},
			bookings: [
				{ serviceId: 's1', resourceAssignments: [{ resourceId: 'r1' }, null, { resourceId: 'r1' }] },
				{ serviceId: 's1', resourceAssignments: [{ resourceId: 'bad' }] },
				{ serviceId: 'known' },
				{ serviceId: 's2', resourceAssignments: [{ resourceId: 'r2' }] },
				{ serviceId: 'bad' },
			],
		}
		for (const [name, fn] of Object.entries(card.methods)) {
			if (!(name in ctx)) {
				ctx[name] = fn.bind(ctx)
			}
		}
		await ctx.primeLabels()
		expect(fetchObject.mock.calls).toEqual([
			['appointmentService', 's1'],
			['appointmentService', 's2'],
			['appointmentService', 'bad'],
			['appointmentResource', 'r1'],
			['appointmentResource', 'bad'],
			['appointmentResource', 'r2'],
		])
		expect(ctx.serviceLookup).toEqual({ known: 'Known', s1: 'Haircut' })
		expect(ctx.resourceLookup).toEqual({ r1: 'Chair', r2: 'Room' })
	})
})
