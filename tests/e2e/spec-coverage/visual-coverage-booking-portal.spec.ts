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
 * WHY THE ASSERTIONS STOP AT "THE COMPONENT MOUNTED"
 * ---------------------------------------------------
 * ⚠️ THE BOOKING PORTAL'S API CLIENT IS POINTED AT THE WRONG PATH, so neither
 * page can reach any of its data-bearing states. `src/services/bookingPortalApi.js`
 * sets `const base = '/apps/pipelinq/portal'` and appends `/services` and
 * `/booking/<id>`, but the routes registered in `appinfo/routes.php` are
 * `/portal/api/booking/services` and `/portal/api/booking/{bookingId}`. The
 * `/api/booking` segment is missing from every call, so each request instead
 * matches the SPA catch-all `portalPage#subpath` (`/portal/{path}`, requirement
 * `^(?!api/).*`) and Nextcloud answers with the portal shell as HTTP 200
 * text/html rather than 404. Consequences, both observed in run 31472541017:
 *
 *   * BookingPortal — `fetchServices()` resolves with an HTML string,
 *     `Array.isArray(html)` is false and `html.services` is undefined, so the
 *     list is `[]` and `fetchServiceBySlug()` returns null for EVERY slug. With
 *     `service`, `loadError` and `loadingService` all falsy, none of the three
 *     v-if branches render — only the unconditional root and skip link. The
 *     public booking portal therefore cannot display a service at all.
 *   * BookingConfirmationPage — `fetchBooking()` resolves instead of throwing,
 *     `booking` becomes a truthy HTML string, and the page renders
 *     "Your booking is confirmed" with every field blank and the `{email}`
 *     placeholder unsubstituted, for a booking id that does not exist.
 *
 * Both are reported as product defects. The tests below therefore assert only
 * what is true both BEFORE and AFTER that fix — the route resolved, the right
 * component mounted, and it is not the login page — so they neither freeze the
 * bug into the suite nor leave CI red for a defect this change may not fix.
 */

import { test, expect, type Page } from '@playwright/test'

import { nextcloudErrorPage } from '../helpers/pipelinq'

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
	await expect(page.locator('main#portal-main-content')).toBeVisible({ timeout: 15000 })

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
test('BookingPortal: /book/:serviceSlug mounts src/views/portal/BookingPortal.vue', async ({ page }) => {
	await openPortalRoute(page, `/book/${ABSENT_SERVICE_SLUG}`)

	const main = page.locator('main#portal-main-content')
	// The router-view resolved to BookingPortal and not to PortalLogin.
	await expect(main.locator('.booking-portal')).toHaveCount(1, { timeout: 15000 })
	// The skip link is the one element BookingPortal renders unconditionally,
	// above every v-if (WCAG 2.4.1 Bypass Blocks) — and, until the API base path
	// above is fixed, the only element it renders at all. It is asserted with
	// `toHaveText` because a skip link is positioned off-screen until focused,
	// so `toBeVisible` would be testing the CSS, not the render.
	await expect(main.locator('.booking-portal .booking-skip-link'))
		.toHaveText('Skip to booking form')
	// The login form must NOT be what we are looking at.
	await expect(page.locator('#portal-email')).toHaveCount(0)
})

// ── src/views/portal/BookingConfirmationPage.vue — `/booking-confirmation/:id` ─
test('BookingConfirmationPage: /booking-confirmation/:bookingId mounts src/views/portal/BookingConfirmationPage.vue', async ({ page }) => {
	await openPortalRoute(page, `/booking-confirmation/${ABSENT_BOOKING_ID}`)

	const main = page.locator('main#portal-main-content')
	await expect(main.locator('.booking-confirmation')).toBeVisible({ timeout: 15000 })
	// Not the login page — proves the public route resolved to this component.
	await expect(page.locator('#portal-email')).toHaveCount(0)

	// ⚠️ THE 404 BRANCH IS UNREACHABLE, so it is not asserted. The first version
	// of this file asserted `.booking-error` with "This booking could not be
	// found." and CI answered `element(s) not found`. That is not a selector
	// typo — the captured DOM shows the SUCCESS branch rendering for an id that
	// does not exist:
	//
	//     - main:
	//       - heading "Your booking is confirmed" [level=1]
	//       - status: "A confirmation email has been sent to {email}."
	//       - term: Name        / definition        (empty)
	//       - term: Service     / definition        (empty)
	//       - term: Date and time / definition      (empty)
	//
	// Cause (src/services/bookingPortalApi.js): `const base =
	// '/apps/pipelinq/portal'`, so `fetchBooking()` GETs
	// `/apps/pipelinq/portal/booking/<id>` — but the route registered in
	// appinfo/routes.php is `/portal/api/booking/{bookingId}`. The `/api/booking`
	// segment is missing, so the request instead matches `portalPage#subpath`
	// (`/portal/{path}`, requirement `^(?!api/).*`) and Nextcloud answers with
	// the portal SPA shell as HTTP 200 text/html. axios RESOLVES, `booking`
	// becomes a truthy HTML string, no error is ever thrown, and the component
	// renders a fabricated confirmation with every field blank and the `{email}`
	// placeholder unsubstituted.
	//
	// This is reported as a product defect. It is deliberately NOT asserted
	// here in either direction: asserting the success branch would freeze the
	// bug into the suite, and asserting the 404 branch would leave CI red for a
	// defect this change is not authorised to fix. The assertions above hold
	// both before and after the fix, so this test keeps its value either way.
})
