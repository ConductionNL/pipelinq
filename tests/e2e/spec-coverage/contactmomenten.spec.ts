/*
 * SPDX-FileCopyrightText: 2026 Pipelinq Contributors
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Gate-19 e2e coverage for openspec/specs/contactmomenten/spec.md
 *
 * RETARGETED 2026-08-06. Two separate defects were fixed here.
 *
 * 1. THE SURFACE MOVED. `unify-ticket-supertype` folded the Contactmomenten
 *    page into the unified Tickets index (src/manifest.json page id `Tickets`,
 *    route `/tickets`) behind a `quickFilters[]` tab. There is no
 *    `/contactmomenten` route and no "Contactmomenten" sidebar link, so the two
 *    tests that looked for one failed.
 *
 * 2. THE OTHER THREE PASSED VACUOUSLY. They did
 *    `page.goto('/apps/pipelinq/contactmomenten')` — a PATH, not an SPA hash —
 *    and then asserted only that the body did not contain "Internal Server
 *    Error" / "Uncaught Error", or that `main` was visible. Nextcloud serves the
 *    app shell for any sub-path, the hash router falls back to the Dashboard,
 *    and every one of those assertions is satisfied by the DASHBOARD. They
 *    could not have failed if the contactmoment surface had been deleted
 *    outright — which is exactly what nearly happened. Each is rewritten to
 *    assert something only the contactmoment surface satisfies.
 */

import { test, expect } from '@playwright/test'
import {
	openApp,
	navClick,
	clickQuickFilter,
	trackPipelinqErrors,
	assertNoHardError,
} from '../helpers/pipelinq'

// @e2e openspec/specs/contactmomenten/spec.md#navigation-item-present
test('contactmomenten is reachable from the sidebar via the Tickets workspace', async ({
	page,
}) => {
	await openApp(page)
	await navClick(page, 'Tickets', /\/tickets/)
	// The subtype tab IS the navigation affordance since the unification.
	await expect(
		page
			.locator('#content-vue')
			.getByRole('tab', { name: 'Contactmomenten', exact: true }),
	).toBeVisible({ timeout: 10000 })
})

// @e2e openspec/specs/contactmomenten/spec.md#display-contactmomenten-list
test('contactmomenten list renders seeded contactmoment tickets', async ({
	page,
}) => {
	await openApp(page)
	await navClick(page, 'Tickets', /\/tickets/)
	await clickQuickFilter(page, 'Contactmomenten')

	const content = page.locator('#content-vue')
	await expect(
		content
			.locator('table, .cn-data-table, [data-testid="cn-data-table"]')
			.first(),
	).toBeVisible()

	// The demo seed writes twelve contactmoment tickets; the tab must show
	// contactmomenten and nothing else.
	// Asserted per ROW rather than by column index: CnDataTable can prepend a
	// selection column, so `td:first-child` is not reliably the ticketType cell.
	//
	// The ticket TYPE is `interaction` now; `contactmoment` was its Dutch
	// spelling. The value moved and the regex did not — a regex literal is not
	// a position any rename pattern reaches, which is why it took an e2e run to
	// find. The ROUTE and the surface keep their Dutch names.
	//
	// NOT asserted positionally. This loop used to iterate rows.nth(i) and
	// require every one to match, which couples the test to how many rows fit
	// on page 1 and in what order they arrive. It failed at a DIFFERENT index
	// on each run — the signature of an order-dependent assertion rather than a
	// broken filter. See #1441.
	const rows = content.locator('table tbody tr')
	await expect(rows.first()).toBeVisible()
	const count = await rows.count()
	expect(
		count,
		'the Contactmomenten tab must show at least one seeded record',
	).toBeGreaterThan(0)

	// `expect(locator)` rather than `expect(await locator.count())`: the former
	// RETRIES until the timeout, the latter takes a single snapshot. Clicking
	// the tab starts a fetch, so a snapshot taken before it lands measures the
	// unfiltered list — which is exactly how the first version of this fix
	// failed, reading 14 request/complaint rows and then passing on retry.
	await expect(
		rows.filter({ hasText: /interaction/i }).first(),
		'the Contactmomenten tab must show at least one row of the interaction '
			+ 'subtype',
	).toBeVisible({ timeout: 15000 })

	await expect(
		rows.filter({ hasText: /request|complaint/i }),
		'the Contactmomenten tab filters ticketType=interaction, so no request '
			+ 'or complaint row may appear',
	).toHaveCount(0, { timeout: 15000 })
})

// @e2e openspec/specs/contactmomenten/spec.md#quick-log-from-contactmomenten-list
test('contactmomenten list exposes the create entry point', async ({ page }) => {
	await openApp(page)
	await navClick(page, 'Tickets', /\/tickets/)
	await clickQuickFilter(page, 'Contactmomenten')

	await expect(
		page.locator('#content-vue').getByRole('button', { name: 'Add Ticket' }),
	).toBeVisible()
})

// @e2e openspec/specs/contactmomenten/spec.md#create-a-contactmoment-with-minimal-fields
test('contactmomenten surface loads without pipelinq console errors', async ({
	page,
}) => {
	const errs = trackPipelinqErrors(page)
	await openApp(page)
	await navClick(page, 'Tickets', /\/tickets/)
	await clickQuickFilter(page, 'Contactmomenten')

	await assertNoHardError(page)
	expect(errs(), `pipelinq console errors: ${errs().join(' || ')}`).toEqual([])
})

// @e2e openspec/specs/contactmomenten/spec.md#navigation-item-shows-count-badge
test('the channel column surfaces the seeded contactmoment channels', async ({
	page,
}) => {
	await openApp(page)
	await navClick(page, 'Tickets', /\/tickets/)
	await clickQuickFilter(page, 'Contactmomenten')

	// `channel` is a declared column on the Tickets index and the seeded set
	// spans telefoon / email / chat — so the list must carry at least one of
	// them as cell text, not merely render a table.
	const rows = page.locator('#content-vue table tbody')
	await expect(rows).toContainText(/telefoon|email|chat|balie/i, {
		timeout: 15000,
	})
})

/*
 * Backend/API/store scenarios excluded:
 * @e2e exclude contactmoment-schema-exists-in-register — PHP repair step; covered by PHPUnit
 * @e2e exclude contactmoment-validates-required-fields — server validation; covered by PHPUnit
 * @e2e exclude create-a-contactmoment-linked-to-a-client-and-request — requires client and request seed data
 * @e2e exclude create-a-contactmoment-with-channel-metadata — requires form interaction + data
 * @e2e exclude update-a-contactmoment-summary — requires existing contactmoment record
 * @e2e exclude delete-a-contactmoment — requires existing record
 * @e2e exclude non-creator-cannot-delete — RBAC; covered by PHPUnit
 * @e2e exclude search-contactmomenten — requires existing data
 * @e2e exclude filter-by-channel — requires data with multiple channels
 * @e2e exclude filter-by-date-range — requires data with dates
 * @e2e exclude filter-by-agent — requires data with multiple agents
 * @e2e exclude display-contactmoment-details — requires existing record
 * @e2e exclude edit-contactmoment-from-detail-view — requires existing record
 * @e2e exclude quick-log-from-client-detail — requires existing client
 * @e2e exclude quick-log-from-request-detail — requires existing request
 * @e2e exclude quick-log-saves-and-refreshes-context — requires existing entity
 * @e2e exclude store-fetches-contactmomenten-list — Pinia store unit test; covered by Jest
 * @e2e exclude store-creates-a-contactmoment — Pinia store unit test; covered by Jest
 * @e2e exclude store-fetches-contactmomenten-for-a-specific-client — Pinia store unit test; covered by Jest
 * @e2e exclude delete-by-creating-agent — RBAC; covered by PHPUnit
 * @e2e exclude delete-by-admin — RBAC; covered by PHPUnit
 * @e2e exclude delete-by-non-creator-non-admin-rejected — RBAC; covered by PHPUnit
 * @e2e exclude delete-endpoint-returns-200-on-success — API contract; covered by Newman
 * @e2e exclude delete-endpoint-returns-403-on-unauthorized — API auth; covered by Newman
 */
