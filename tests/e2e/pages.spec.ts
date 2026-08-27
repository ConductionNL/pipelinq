/*
 * SPDX-FileCopyrightText: 2026 Pipelinq Contributors
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Page-shell contract for every manifest route.
 *
 * REWRITTEN for #392. The previous version of this file was thirteen separate
 * `test.describe.skip(...)` blocks — 31 tests, none of which had run in any
 * pipeline. It was not merely stale; a third of it described pages that no
 * longer exist:
 *
 *   - "Requests page", "Complaints page", "Contactmomenten page" (7 tests).
 *     `unify-ticket-supertype` folded all three into ONE `ticket` index behind
 *     a quick-filter strip; see clickQuickFilter() in helpers/pipelinq.ts.
 *     Verified live: `#/requests`, `#/complaints` and `#/contactmomenten` no
 *     longer render an index page at all. Those tests could not be rewritten,
 *     only deleted, and their surface is covered by the Tickets index below
 *     plus the quick-filter helper the workflow specs already drive.
 *   - "Surveys page" (2 tests) — likewise no longer an index route.
 *
 * And the assertions that remained were keyed on things that have all moved:
 * `getByRole('radio', { name: 'Cards' })` for the view toggle (it is a BUTTON
 * now, and on this `nl` instance it reads "Kaarten"), `Add Item` (now
 * `Add Client`), and path routes like `/apps/pipelinq/clients` (the shell
 * routes on the hash).
 *
 * WHAT THIS FILE ASSERTS INSTEAD
 *
 * The manifest-driven shell gives every page a stable, untranslated structural
 * contract — `cn-page`, `cn-index-page`, `cn-page-title`, `cn-cta-primary`,
 * `cn-actions`. That contract is worth more than 31 hand-written label
 * assertions, because it is what actually breaks when a route regresses: the
 * page renders an empty shell, or loses its header, or stops offering its
 * primary action.
 *
 * The three tiers below were MEASURED route by route against the running app on
 * 2026-08-24, not assumed. The distinction matters: `#/services`,
 * `#/resources` and `#/bookings` are index pages that carry NO primary CTA, so
 * a uniform "every index page has a CTA" contract would have been wrong, and
 * asserting it would have produced exactly the kind of finding that gets a
 * suite disabled again.
 */
import { test, expect } from '@playwright/test'
import { dismissSupportDialog, dismissWalkthrough } from './helpers/pipelinq'

/** Index routes that carry a primary create action. */
const INDEX_WITH_CTA: Array<[string, string]> = [
	['Clients', '#/clients'],
	['Contacts', '#/contacts'],
	['Leads', '#/leads'],
	['Tickets', '#/tickets'],
	['Tasks', '#/tasks'],
	['Products', '#/products'],
	['Queues', '#/queues'],
	['Contracts', '#/contracts'],
]

/** Index routes with no primary create action on this build. */
const INDEX_WITHOUT_CTA: Array<[string, string]> = [
	['Services', '#/services'],
	['Resources', '#/resources'],
	['Bookings', '#/bookings'],
]

/**
 * Routes that render a page but are not index pages — dashboards, boards and
 * reporting views. Asserted only as "renders a page", which is the regression
 * that matters for them (a blank shell).
 */
const NON_INDEX: Array<[string, string]> = [
	['Dashboard', '#/'],
	['Pipeline', '#/pipeline'],
	['My Work', '#/my-work'],
	['Prospects', '#/prospects'],
	['Forecast', '#/forecast'],
	['Contact reporting', '#/rapportage/contactmomenten'],
]

/**
 * Open a hash route directly.
 *
 * A PATH deep-link (`/apps/pipelinq/clients`) resets the SPA to the Dashboard —
 * documented in helpers/pipelinq.ts and the reason the old file's `page.goto`
 * calls silently tested the Dashboard over and over. The HASH form is a real
 * navigation, verified live.
 *
 * @param page  The page under test.
 * @param route The hash route, e.g. `#/clients`.
 */
async function openRoute(page: import('@playwright/test').Page, route: string) {
	await page.goto(`/apps/pipelinq/${route}`)
	await expect(
		page.locator('[data-testid="cn-app-root"]'),
		'CnAppRoot must mount — #392 was filed because it did not',
	).toBeVisible({ timeout: 15000 })
	await dismissWalkthrough(page)
	await dismissSupportDialog(page)
}

test.describe('Index pages with a primary action', () => {
	for (const [name, route] of INDEX_WITH_CTA) {
		test(`${name} (${route}) renders its index-page contract`, async ({
			page,
		}) => {
			await openRoute(page, route)
			await expect(
				page.locator('[data-testid="cn-index-page"]'),
				`${route} must render an index page, not an empty shell`,
			).toBeVisible({ timeout: 15000 })
			// The title comes from the manifest, not the i18n catalogue — it
			// renders in English even on this `nl` instance — so a non-empty
			// title is a real assertion rather than a locale coin-flip.
			await expect(
				page.locator('[data-testid="cn-page-title"]'),
			).not.toHaveText('')
			await expect(
				page.locator('[data-testid="cn-cta-primary"]'),
				`${route} must offer its primary create action`,
			).toBeVisible()
			await expect(
				page.locator('[data-testid="cn-actions"]').first(),
			).toBeVisible()
		})
	}
})

test.describe('Index pages without a primary action', () => {
	for (const [name, route] of INDEX_WITHOUT_CTA) {
		test(`${name} (${route}) renders its index-page contract`, async ({
			page,
		}) => {
			await openRoute(page, route)
			await expect(
				page.locator('[data-testid="cn-index-page"]'),
				`${route} must render an index page, not an empty shell`,
			).toBeVisible({ timeout: 15000 })
			await expect(
				page.locator('[data-testid="cn-page-title"]'),
			).not.toHaveText('')
		})
	}
})

test.describe('Non-index routes still render a page', () => {
	for (const [name, route] of NON_INDEX) {
		test(`${name} (${route}) renders a page`, async ({ page }) => {
			await openRoute(page, route)
			await expect(
				page.locator('[data-testid="cn-page"]').first(),
				`${route} must render a page — a blank shell is the regression`,
			).toBeVisible({ timeout: 15000 })
		})
	}
})
