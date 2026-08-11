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
 * WHY THE ASSERTIONS STOP WHERE THEY DO. Neither route's record is seeded by
 * `ci-seed.sh`, and the two components fail differently, which the tests reflect
 * rather than paper over:
 *
 *   * `fetchServiceBySlug()` RETURNS NULL for an unknown slug (it filters a list
 *     client-side; it does not throw). So `service`, `loadError` and
 *     `loadingService` are all falsy and NONE of BookingPortal's three v-if
 *     branches render — only the component's unconditional root and skip link.
 *     Asserting a heading or an error there would be asserting something the
 *     code does not do.
 *   * `fetchBooking()` is a plain axios GET, and the controller answers an
 *     unknown id with HTTP 404, so axios throws and the component's own 404
 *     branch renders a `role="alert"` with a specific message. That IS
 *     assertable, and is asserted.
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
	// above every v-if (WCAG 2.4.1 Bypass Blocks). It is asserted with
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
	// `portal#getBooking` answers an unknown id with HTTP 404, axios throws, and
	// the component maps status 404 onto its own message in a live region.
	const alert = main.locator('.booking-confirmation .booking-error')
	await expect(alert).toBeVisible({ timeout: 15000 })
	await expect(alert).toHaveAttribute('role', 'alert')
	await expect(alert).toHaveText('This booking could not be found.')
	await expect(page.locator('#portal-email')).toHaveCount(0)
})
