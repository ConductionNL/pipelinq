/**
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * Public booking-portal API client.
 *
 * Thin axios wrapper around the public appointment-booking portal endpoints
 * exposed by the member-05 PortalBookingController. These endpoints are public
 * (no Nextcloud login, no portal bearer token): customers self-book without an
 * account. All availability, slot validity and pricing are server-authoritative;
 * this module only transports requests/responses (ADR-022). No secrets, no
 * special-category data is handled client-side.
 */

import axios from '@nextcloud/axios'
import { generateUrl } from '@nextcloud/router'

// ⚠️ This MUST include `/api/booking`. The routes are registered at
// /portal/api/booking/* (appinfo/routes.php, `portal#services` and friends),
// and `portalPage#subpath` catches every other /portal/* path with the SPA
// shell. Pointing this at /apps/pipelinq/portal therefore did not 404 -- it
// answered HTTP 200 with HTML, `Array.isArray(html)` was false and
// `html.services` undefined, so fetchServices() returned [] and the public
// booking portal silently listed no bookable services at all.
const base = '/apps/pipelinq/portal/api/booking'

/**
 * Fetch the list of bookable services.
 *
 * @return {Promise<Array<object>>} The services array.
 */
export async function fetchServices() {
	const response = await axios.get(generateUrl(base + '/services'))
	return Array.isArray(response.data)
		? response.data
		: response.data.services || []
}

/**
 * Fetch a single bookable service by its slug.
 *
 * @param {string} slug The service slug.
 * @return {Promise<object|null>} The matching service, or null.
 */
export async function fetchServiceBySlug(slug) {
	const services = await fetchServices()
	return services.find((s) => s.slug === slug || s.id === slug) || null
}

/**
 * Fetch available 15-minute slots for a service on a given date.
 *
 * @param {string} serviceId The service id.
 * @param {string} date The ISO date (YYYY-MM-DD).
 * @return {Promise<Array<object>>} Available slots (each with startAt/endAt).
 */
export async function fetchAvailability(serviceId, date) {
	const response = await axios.get(generateUrl(base + '/availability'), {
		params: { serviceId, date },
	})
	return Array.isArray(response.data) ? response.data : response.data.slots || []
}

/**
 * Submit a booking.
 *
 * @param {object} payload Booking payload (serviceId, startAt, name, email, phone, notes).
 * @return {Promise<object>} The created booking (id, status, depositRequired, paymentUrl, ...).
 */
export async function submitBooking(payload) {
	const response = await axios.post(generateUrl(base + '/book'), payload)
	return response.data
}

/**
 * Fetch a booking summary by id (public, signed confirmation view).
 *
 * @param {string} bookingId The booking id.
 * @return {Promise<object>} The booking summary.
 * @spec openspec/specs/appointment-booking/spec.md
 */
export async function fetchBooking(bookingId) {
	const response = await axios.get(
		// `portal#getBooking` is /portal/api/booking/{bookingId}: the id hangs
		// directly off the base, with no second `/booking` segment.
		generateUrl(base + '/' + encodeURIComponent(bookingId)),
	)
	return response.data
}
