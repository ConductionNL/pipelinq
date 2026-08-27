/*
 * SPDX-FileCopyrightText: 2026 Pipelinq Contributors
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Sidebar navigation — the manifest-driven app shell (CnAppRoot).
 *
 * REWRITTEN for #392. The previous version of this file was
 * `test.describe.skip(...)` with `// TODO(#392): rewrite for manifest-driven
 * app shell` on line 5, so its four tests had not run in any pipeline. It was
 * written against the pre-manifest flat IA and asserted three things that are
 * all false on this shell:
 *
 *   1. ENGLISH LABELS via `nav.getByText('Products', { exact: true })`. This
 *      instance renders `nl`, where that entry reads "Producten". A spec keyed
 *      on visible wording passes or fails on the runner's locale rather than on
 *      the navigation, so every assertion here goes through the manifest page
 *      id instead — `data-testid="cn-nav-entry-<pageId>"`, untranslated by
 *      construction.
 *   2. PATH ROUTES — `href="/apps/pipelinq/clients"`. The shell routes on the
 *      HASH (`#/clients`), and helpers/pipelinq.ts documents why a path
 *      deep-link is worse than wrong: it resets the SPA to the Dashboard.
 *   3. FLAT VISIBILITY. Since the 2026-07 IA revision most leaves sit inside a
 *      collapsed group. Measured on this build: 37 entries in the DOM, 11
 *      visible at load. `toBeVisible()` on a nested leaf asserts the old flat
 *      IA and fails against correct navigation, so presence is asserted as
 *      REACHABILITY (attached, then revealed) and visibility only where the
 *      manifest actually puts an entry at top level.
 *
 * The required set is a SUBSET, never an exact total. An exact count is how
 * decidiq's registry spec came to skip itself: it asserts 29 providers, found
 * 27, and stood down rather than reporting the difference. A manifest that
 * grows must not turn this file red, while a manifest that drops one of these
 * pages must.
 */
import { test, expect } from '@playwright/test'
import { openApp, revealNavEntryByTestId } from './helpers/pipelinq'

/**
 * Manifest page id → hash route. Verified live against the running app on
 * 2026-08-24 by reading every `[data-testid^="cn-nav-entry-"]` and its href.
 */
const REQUIRED_ENTRIES: Record<string, string> = {
	Dashboard: '#/',
	Clients: '#/clients',
	Contacts: '#/contacts',
	Leads: '#/leads',
	Tickets: '#/tickets',
	Tasks: '#/tasks',
	Products: '#/products',
	Pipeline: '#/pipeline',
	Queues: '#/queues',
	Contracts: '#/contracts',
	MyWork: '#/my-work',
	Prospects: '#/prospects',
	Forecast: '#/forecast',
	Services: '#/services',
	Resources: '#/resources',
	Bookings: '#/bookings',
}

/**
 * Entries the manifest places at TOP LEVEL, i.e. painted without opening a
 * group first. Deliberately short: it is the one claim in this file that is
 * about layout rather than about routing, and it is the claim most likely to
 * move when the IA is revised again.
 */
const TOP_LEVEL = ['Dashboard', 'OperationalDashboard', 'Products'] as const

test.describe('Sidebar navigation (manifest-driven shell)', () => {
	test.beforeEach(async ({ page }) => {
		await openApp(page)
	})

	test('the shell mounts with its navigation', async ({ page }) => {
		await expect(
			page.locator('[data-testid="cn-app-root"]'),
			'CnAppRoot must mount — #392 was filed because it did not',
		).toBeVisible({ timeout: 15000 })
		await expect(page.locator('#app-navigation-vue')).toBeVisible()
	})

	test('every required page is reachable in the sidebar', async ({ page }) => {
		const missing: string[] = []
		for (const pageId of Object.keys(REQUIRED_ENTRIES)) {
			const entry = page
				.locator(
					`#app-navigation-vue [data-testid="cn-nav-entry-${pageId}"]`,
				)
				.first()
			// `attached`, not `visible`: a leaf inside a collapsed group is
			// present and reachable, and that is what "in the sidebar" means on
			// this IA.
			if ((await entry.count()) === 0) missing.push(pageId)
		}
		expect(missing, 'these manifest pages have no sidebar entry at all').toEqual(
			[],
		)
	})

	test('each sidebar entry points at its hash route', async ({ page }) => {
		const wrong: string[] = []
		for (const [pageId, href] of Object.entries(REQUIRED_ENTRIES)) {
			const link = page
				.locator(
					`#app-navigation-vue [data-testid="cn-nav-entry-${pageId}"]`,
				)
				.locator('xpath=descendant-or-self::a[1]')
				.first()
			const actual = await link.getAttribute('href').catch(() => null)
			if (actual !== href)
				wrong.push(`${pageId}: expected ${href}, got ${actual}`)
		}
		expect(
			wrong,
			'the shell routes on the hash; a path href resets the SPA to the Dashboard',
		).toEqual([])
	})

	test('the top-level entries are painted without opening a group', async ({
		page,
	}) => {
		for (const pageId of TOP_LEVEL) {
			await expect(
				page
					.locator(
						`#app-navigation-vue [data-testid="cn-nav-entry-${pageId}"]`,
					)
					.first(),
				`${pageId} is a top-level entry and must be visible at load`,
			).toBeVisible()
		}
	})

	test('revealing and clicking an entry navigates to its route', async ({
		page,
	}) => {
		// Clients sits inside a collapsed group on this IA, which makes it the
		// honest case: the assertion is that a user can REACH it, not that it
		// happens to be painted.
		const link = await revealNavEntryByTestId(page, 'Clients')
		await expect(link).toBeVisible({ timeout: 10000 })
		await link.click()
		await expect(page).toHaveURL(/#\/clients/, { timeout: 10000 })
		await expect(
			page.locator('[data-testid="cn-index-page"]'),
			'the Clients route must render its index page, not an empty shell',
		).toBeVisible({ timeout: 15000 })
	})
})
