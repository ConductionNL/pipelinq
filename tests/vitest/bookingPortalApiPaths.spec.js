// SPDX-License-Identifier: EUPL-1.2
// Copyright (C) 2026 Conduction B.V.

/**
 * Unit tests for src/services/bookingPortalApi.js — the URL each call builds.
 *
 * ⚠️ WHY A TEST ABOUT STRINGS. These four calls were pointed at
 * `/apps/pipelinq/portal/...` while the routes live at
 * `/portal/api/booking/...`. A wrong path here does NOT 404: the portal SPA
 * catch-all `portalPage#subpath` (`/portal/{path}`, requirement `^(?!api/).*`)
 * answers every non-api path with the shell as HTTP 200 text/html, so axios
 * RESOLVES and the caller gets an HTML string where it expected JSON.
 * fetchServices() returned [] and the public booking portal listed nothing;
 * fetchBooking() returned a truthy string and the page rendered a fabricated
 * "Your booking is confirmed" for a booking that does not exist.
 *
 * Nothing threw in either case, which is why the URLs are pinned here rather
 * than left to an integration test to notice.
 */

import { beforeEach, describe, expect, it, vi } from 'vitest'

const get = vi.fn()
const post = vi.fn()

vi.mock('@nextcloud/axios', () => ({
	default: {
		get: (...args) => get(...args),
		post: (...args) => post(...args),
	},
}))

// generateUrl prefixes the instance base; identity keeps the assertions about
// the path this module builds rather than about @nextcloud/router.
vi.mock('@nextcloud/router', () => ({
	generateUrl: (url) => url,
}))

const { fetchAvailability, fetchBooking, fetchServices, submitBooking } =
	await import('../../src/services/bookingPortalApi.js')

describe('bookingPortalApi URL contract', () => {
	beforeEach(() => {
		get.mockReset()
		post.mockReset()
		get.mockResolvedValue({ data: { services: [] } })
		post.mockResolvedValue({ data: {} })
	})

	it('every path sits under /portal/api/booking, never bare /portal', async () => {
		await fetchServices()
		expect(get.mock.calls[0][0]).toBe(
			'/apps/pipelinq/portal/api/booking/services',
		)
	})

	it('availability is requested from the registered route', async () => {
		get.mockResolvedValue({ data: { slots: [] } })
		await fetchAvailability('svc-1', '2026-09-01')
		expect(get.mock.calls[0][0]).toBe(
			'/apps/pipelinq/portal/api/booking/availability',
		)
	})

	it('booking creation posts to the registered route', async () => {
		await submitBooking({ serviceId: 'svc-1' })
		expect(post.mock.calls[0][0]).toBe('/apps/pipelinq/portal/api/booking/book')
	})

	it('a single booking hangs the id straight off the base, with no second /booking segment', async () => {
		get.mockResolvedValue({ data: { id: 'abc' } })
		await fetchBooking('abc 123/x')

		const url = get.mock.calls[0][0]
		expect(url).toBe(
			'/apps/pipelinq/portal/api/booking/' + encodeURIComponent('abc 123/x'),
		)
		// The route is /portal/api/booking/{bookingId}: `base + '/booking/' + id`
		// would have produced .../api/booking/booking/<id>.
		expect(url).not.toContain('/booking/booking/')
	})
})
