/*
 * SPDX-FileCopyrightText: 2026 Pipelinq Contributors
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Gate-26 (visual-coverage) e2e proof for the two anonymous appointment-booking
 * pages, which live in the STANDALONE CUSTOMER PORTAL bundle rather than in the
 * Nextcloud-authenticated app.
 *
 *   src/views/portal/BookingPortal.vue            #/book/:serviceSlug
 *   src/views/portal/BookingConfirmationPage.vue  #/booking-confirmation/:bookingId
 *
 * Both are declared in `src/portal/portalRoutes.js` with `meta: { public: true }`
 * and served by `portalPage#subpath` (`/apps/pipelinq/portal/{path}`), whose
 * controller is a `#[PublicPage]`. `src/portal.js` builds
 * `createWebHashHistory(generateUrl('/apps/pipelinq/portal'))`, so — as in the
 * main app — the route lives in the hash. `tests/e2e/portal-accessibility.spec.ts`
 * already exercises this same entry point in CI, which is what makes the portal
 * bundle a known-good target rather than an assumption.
 *
 * THE API CLIENT USED TO BE POINTED AT THE WRONG PATH
 * ---------------------------------------------------
 * `src/services/bookingPortalApi.js` set `const base = '/apps/pipelinq/portal'`
 * and appended `/services` and `/booking/<id>`, but the routes registered in
 * `appinfo/routes.php` are `/portal/api/booking/services` and
 * `/portal/api/booking/{bookingId}`. With the `/api/booking` segment missing,
 * every call matched the SPA catch-all `portalPage#subpath` (`/portal/{path}`,
 * requirement `^(?!api/).*`) and Nextcloud answered the portal shell as HTTP
 * 200 text/html rather than 404 — so nothing threw. Observed in run
 * 31472541017:
 *
 *   * BookingPortal — `fetchServices()` resolved with an HTML string,
 *     `Array.isArray(html)` was false and `html.services` undefined, so the
 *     list was `[]` and `fetchServiceBySlug()` returned null for EVERY slug.
 *     The public booking portal could not display a service at all.
 *   * BookingConfirmationPage — `fetchBooking()` resolved instead of throwing,
 *     `booking` became a truthy HTML string, and the page rendered "Your
 *     booking is confirmed" with every field blank and the `{email}`
 *     placeholder unsubstituted, for a booking id that does not exist.
 *
 * The base now carries `/api/booking`, verified against the dev instance:
 * `/portal/api/booking/services` answers 200 application/json,
 * `/portal/api/booking/<absent-id>` answers 404 {"error":"Booking not found"}.
 * The confirmation test below therefore asserts the error branch that was
 * unreachable while the bug stood — a fabricated confirmation is a regression
 * that fails by showing MORE, not less, so it needs an assertion that a blank
 * success card cannot satisfy.
 */

import type { Page } from '@playwright/test'

import { expect, test } from '@playwright/test'
import { nextcloudErrorPage } from '../helpers/pipelinq.ts'

/** The public portal SPA shell — see `appinfo/routes.php` `portalPage#subpath`. */
const PORTAL_BASE = '/apps/pipelinq/portal/'

/** A service slug that deliberately matches no seeded appointment service. */
const ABSENT_SERVICE_SLUG = 'e2e-gate26-no-such-service'

/** A booking id that deliberately matches no seeded booking. */
const ABSENT_BOOKING_ID = 'e2e-gate26-no-such-booking'

/**
 * Open a portal hash route and prove the portal shell — not Nextcloud's error
 * chrome, and not the login redirect — was served.
 *
 * @param page The Playwright page.
 * @param hash The portal route, e.g. `/book/some-slug`.
 */
async function openPortalRoute(page: Page, hash: string): Promise<void> {
	const response = await page.goto(`${PORTAL_BASE}#${hash}`)
	expect(response, 'navigation produced no response').not.toBeNull()
	expect(response?.status(), 'the portal shell must be served').toBe(200)
	await expect(nextcloudErrorPage(page)).toHaveCount(0)

	// Positive mount signal: PortalApp's root and its <main> landmark.
	await expect(page.locator('.portal-app')).toHaveCount(1, { timeout: 20000 })
	await expect(page.locator('main#portal-main-content')).toBeVisible({
		timeout: 15000,
	})

	// A SURVIVING HASH IS THE ACCESS PROOF. `installPortalGuard()` rewrites the
	// route to `/login` for anything without `meta.public`, so a hash that is
	// still the requested one is evidence both that the route matched AND that
	// it was correctly classified public — the whole point of anonymous
	// self-booking (ADR-005).
	await expect(page).toHaveURL(
		new RegExp(`#${hash.replace(/[.*+?^${}()|[\]\\]/g, '\\$&')}$`),
		{ timeout: 10000 },
	)
}

// ── src/views/portal/BookingPortal.vue — route `/book/:serviceSlug` ───────────
test('BookingPortal: /book/:serviceSlug mounts src/views/portal/BookingPortal.vue', async ({
	page,
}) => {
	await openPortalRoute(page, `/book/${ABSENT_SERVICE_SLUG}`)

	const main = page.locator('main#portal-main-content')
	// The router-view resolved to BookingPortal and not to PortalLogin.
	await expect(main.locator('.booking-portal')).toHaveCount(1, { timeout: 15000 })
	// The skip link is the one element BookingPortal renders unconditionally,
	// above every v-if (WCAG 2.4.1 Bypass Blocks). It is asserted with
	// `toHaveText` because a skip link is positioned off-screen until focused,
	// so `toBeVisible` would be testing the CSS, not the render.
	await expect(main.locator('.booking-portal .booking-skip-link')).toHaveText(
		'Skip to booking form',
	)
	// The login form must NOT be what we are looking at.
	await expect(page.locator('#portal-email')).toHaveCount(0)
})

// ── src/views/portal/BookingConfirmationPage.vue — `/booking-confirmation/:id` ─
test('BookingConfirmationPage: /booking-confirmation/:bookingId mounts src/views/portal/BookingConfirmationPage.vue', async ({
	page,
}) => {
	await openPortalRoute(page, `/booking-confirmation/${ABSENT_BOOKING_ID}`)

	const main = page.locator('main#portal-main-content')
	await expect(main.locator('.booking-confirmation')).toBeVisible({
		timeout: 15000,
	})
	// Not the login page — proves the public route resolved to this component.
	await expect(page.locator('#portal-email')).toHaveCount(0)

	// The 404 branch, which used to be UNREACHABLE. `bookingPortalApi.js` had
	// `const base = '/apps/pipelinq/portal'`, so fetchBooking() GET'd
	// /apps/pipelinq/portal/booking/<id> while the route is registered at
	// /portal/api/booking/{bookingId}. Missing the `/api/booking` segment, the
	// request matched `portalPage#subpath` (`/portal/{path}`, requirement
	// `^(?!api/).*`) instead and Nextcloud answered the portal SPA shell as
	// HTTP 200 text/html. axios RESOLVED, `booking` became a truthy HTML
	// string, and the page rendered a FABRICATED confirmation -- "Your booking
	// is confirmed" for an id that does not exist, every field blank and the
	// {email} placeholder unsubstituted.
	//
	// Now that the base carries /api/booking, an unknown id gets a real 404
	// with {"error":"Booking not found"}, so the error branch renders. This
	// asserts it, because the fabricated-confirmation regression is silent by
	// nature: it fails by showing MORE, not less.
	await expect(main.locator('.booking-error')).toBeVisible({ timeout: 15000 })
	await expect(main.locator('.booking-confirmation-card')).toHaveCount(0)
})
