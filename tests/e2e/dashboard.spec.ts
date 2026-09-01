/*
 * SPDX-FileCopyrightText: 2026 Pipelinq Contributors
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Sales dashboard — the manifest-driven dashboard page.
 *
 * REWRITTEN for #392. The previous version was `test.describe.skip(...)` with
 * `// TODO(#392): rewrite for manifest-driven app shell` on line 4, so its
 * three tests had not run in any pipeline. Every assertion in it described a
 * dashboard that no longer exists:
 *
 *   - it waited for `heading "Dashboard", level 2`; the page's h2 is
 *     "Sales overview".
 *   - it asserted `heading "Open Leads" level 4`, "Open Requests",
 *     "Pipeline Value", "Overdue"; the widgets are now `role=group` elements
 *     named by their manifest widget id — pipeline-coverage,
 *     weighted-forecast, revenue, mrr, won-vs-open, won-value, win-rate,
 *     avg-deal-size and the four chart/table widgets below.
 *   - it asserted KPI links carrying `href=/leads?status=open`; the widgets
 *     link at hash routes (`#/pipeline`, `#/leads`, `#/contracts`).
 *
 * The widget ids are the right handle for a second reason: they are
 * untranslated. This instance renders `nl`, and the refresh control's only
 * accessible name there is "Dashboard vernieuwen" — so the old spec's
 * `getByRole('button', { name: 'Refresh dashboard' })` would fail on locale
 * alone. Where a control has no stable id, this file asserts its ROLE and
 * position rather than its wording.
 *
 * Verified live against the running app on 2026-08-24.
 */
import { test, expect } from '@playwright/test'
import { openApp } from './helpers/pipelinq'

/**
 * Manifest widget ids, which CnDashboardPage exposes as the accessible name of
 * each widget's `role=group` wrapper. A required SUBSET — the dashboard is
 * configurable and gains widgets, so an exact total would make every future
 * addition a failure here.
 */
const REQUIRED_WIDGETS = [
	'pipeline-coverage',
	'weighted-forecast',
	'revenue',
	'mrr',
	'won-vs-open',
	'won-value',
	'win-rate',
	'avg-deal-size',
	'revenue-over-time',
	'pipeline-by-stage',
	'revenue-by-category',
	'top-customers',
	'closing-soon',
	'recently-won-lost',
] as const

test.describe('Sales dashboard', () => {
	test.beforeEach(async ({ page }) => {
		await openApp(page)
		await expect(
			page.locator('[data-testid="cn-app-root"]'),
			'CnAppRoot must mount — #392 was filed because it did not',
		).toBeVisible({ timeout: 15000 })
	})

	test('renders the dashboard page for the default route', async ({ page }) => {
		// History routing: the default route is the app root as a plain path.
		// Anchored at the end so it holds under both the `/apps/...` and
		// `/index.php/apps/...` bases Nextcloud serves.
		await expect(page).toHaveURL(/\/apps\/pipelinq\/$/)
		await expect(
			page.locator('[data-testid="cn-page"]'),
			'the default route must render a page, not an empty shell',
		).toBeVisible({ timeout: 15000 })
	})

	test('every required KPI and chart widget is rendered', async ({ page }) => {
		const missing: string[] = []
		for (const id of REQUIRED_WIDGETS) {
			const widget = page.locator(`[role="group"][aria-label="${id}"]`).first()
			if ((await widget.count()) === 0) missing.push(id)
		}
		expect(
			missing,
			'these manifest widgets did not render on the dashboard',
		).toEqual([])
	})

	test('the quick-create actions are offered', async ({ page }) => {
		// These come from the manifest's action list, not from the i18n
		// catalogue — they render in English even on this `nl` instance, which
		// is why they are safe to assert by name. The refresh control beside
		// them is NOT: its accessible name is translated
		// ("Dashboard vernieuwen"), so it is asserted by role and count below.
		for (const name of ['New Lead', 'New Client']) {
			await expect(
				page.getByRole('button', { name, exact: true }).first(),
				`the ${name} quick-create action must be offered`,
			).toBeVisible({ timeout: 15000 })
		}

		// New Request is NOT here. Raising a request is customer-support work,
		// and it now lives on that page instead. The three buttons used to be
		// hardcoded together in one actionsComponent, so every dashboard naming
		// that component got all three whether or not they belonged.
		await expect(
			page.getByRole('button', { name: 'New Request', exact: true }),
			'New Request belongs on Customer Support, not on the sales dashboard',
		).toHaveCount(0)
	})

	test('each KPI widget links into a filtered view', async ({ page }) => {
		// The KPI tiles are anchors into in-app routes. Asserting that each one
		// HAS an in-app href catches the regression that matters (a tile that
		// stopped linking) without pinning the exact destination of every tile,
		// which is manifest configuration and moves with the product.
		//
		// The shell routes on HISTORY now, so these are paths rather than `#/`
		// fragments, and the base differs between the `/apps/...` and
		// `/index.php/apps/...` forms Nextcloud serves — hence a contains-match
		// on the app segment rather than a prefix-match on the whole href.
		const linked = page.locator(
			'[role="group"][aria-label="pipeline-coverage"] a[href*="/apps/pipelinq/"]',
		)
		await expect(
			linked.first(),
			'the pipeline-coverage tile must link into a filtered view',
		).toHaveAttribute('href', /\/apps\/pipelinq\/.+/)
	})
})
