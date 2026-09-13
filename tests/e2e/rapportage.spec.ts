// SPDX-License-Identifier: EUPL-1.2
// Copyright (C) 2026 Conduction B.V.
//
// REQ-CR-001: Reporting dashboard loads with KPI cards visible.
// @spec openspec/changes/contactmomenten-rapportage/tasks.md#task-6
import type { Page } from '@playwright/test'

import { expect, test } from '@playwright/test'
import { gotoAppRoute } from './helpers/pipelinq.ts'

test.describe('Rapportage (Reporting)', () => {
	// This raise was taken when every test here ran openApp() — booting the
	// shell and dismissing both overlays — and THEN navigated, two full
	// document loads before the first assertion, with the Reports page a lazy
	// chunk (CnPageRenderer maps `type:"reports"` through
	// defineAsyncComponent) on top. At 13 to 23 s a load on the CI runner that
	// did not fit 60 s, and the failure carried no assertion error at all,
	// just the timeout, which reads as "the page is broken" rather than "this
	// test is too slow".
	//
	// The second load is gone from all six tests now, so the raise is headroom
	// rather than a requirement. It is kept rather than tuned because nobody
	// has measured what this file costs post-change on the CI runner, and a
	// number picked without one is the thing this comment exists to warn about.
	//
	// (Historical: these were failing on `cn-report-card` resolving to 0,
	// which was a real defect — CnReportsPage read `page.config.cards` while
	// CnPageRenderer spreads config keys as top-level props, so the page
	// rendered its empty state for every consumer. nextcloud-vue#920 fixed
	// that and 2.30.0 ships it.)
	test.setTimeout(180_000)

	/**
	 * The contactmomenten Reporting Dashboard (KPI cards) lives at the
	 * `/rapportage/contactmomenten` page (manifest id RapportageContactmomenten
	 * → RapportageDashboard.vue). The "Reporting" sidebar link now points at the
	 * Lead-analytics page (`/rapportage`), so deep-link the dashboard route
	 * directly by PATH. This said the opposite until #1684 — that a path goto
	 * boots the shell at the Dashboard and only a hash goto mounts the target
	 * view — which stopped being true when the shell moved to
	 * createWebHistory(routerBase()).
	 *
	 * This used to be a `beforeEach`, and it used to end with a `reload()`.
	 * Both were waste, and between them they cost every test in this file two
	 * full document loads:
	 *
	 *   • Only the test below looks at this dashboard. The other five navigate
	 *     to the Reports page or to one of the two analytics pages, so for them
	 *     the beforeEach loaded a page they never asserted against and then
	 *     threw it away.
	 *   • The `reload()` was justified as making the view "re-query its KPI
	 *     data after the navigation". After a full-document `goto` there is
	 *     nothing to re-query: the view has only just mounted and fetched. It
	 *     is the same stale reload that spec-coverage/declarative-view-system.ts
	 *     already documents and guards, left over from hash routing where the
	 *     goto did NOT remount.
	 *
	 * CI runs 6 workers against one `php -S`, so a load is 13 to 23 s. Ten
	 * loads came out of this file for that reason.
	 */
	async function gotoContactmomentenDashboard(page: Page): Promise<void> {
		await gotoAppRoute(page, '/rapportage/contactmomenten')
		await expect(page.locator('body')).not.toContainText('Internal Server Error')
	}

	/**
	 * REQ-CR-001: Dashboard loads with KPI cards.
	 *
	 * Verifies that the Rapportage page is reachable, the heading renders,
	 * and the four KPI cards are present in the DOM.
	 *
	 * @spec openspec/changes/contactmomenten-rapportage/tasks.md#task-6
	 */
	test('REQ-CR-001: rapportage dashboard loads with KPI cards', async ({
		page,
	}) => {
		await gotoContactmomentenDashboard(page)

		// Retargeted onto the declarative dashboard that replaced the bespoke
		// RapportageDashboard.vue (change `pipelinq-dashboards-declarative`).
		// The old assertions named that component's private CSS — `.kpi-grid`,
		// `.kpi-card`, `.date-range-selector` — none of which exist anywhere in
		// src/ any more, so they could only ever fail. The page is now
		// `type: "dashboard"` in src/manifest.json (id RapportageContactmomenten)
		// and that manifest entry is the contract asserted here.

		// Manifest `title` renders as the page heading.
		await expect(
			page.getByRole('heading', { name: 'Contact reporting' }).first(),
		).toBeVisible({ timeout: 15000 })

		// The `period` pageFilter replaced the hand-rolled date-range selector.
		await expect(page.getByText('Period').first()).toBeVisible({
			timeout: 10000,
		})

		// The four headline KPIs, by the labels the manifest declares for them.
		const content = page.locator('#content-vue')
		for (const kpi of [
			'Total Contacts',
			'FCR %',
			'Avg Handling Time',
			'SLA Compliance',
		]) {
			await expect(content.getByText(kpi).first()).toBeVisible({
				timeout: 10000,
			})
		}
	})

	/*
	 * pipelinq#687 — FIXED 2026-08-06 by giving the orphan pages a way in.
	 *
	 * These two tests were correct and deliberately left red: `/rapportage/channels`
	 * (ChannelAnalyticsView) and `/rapportage/agents` (AgentPerformanceView) —
	 * and `/rapportage/contactmomenten` with them — were routed, built and
	 * rendering, with NO menu entry, no in-page link and no deepLinks
	 * registration. The only way to reach them was to type the hash by hand.
	 *
	 * They also asserted against `.rapportage-links`, a container from the
	 * bespoke RapportageDashboard.vue that the `pipelinq-dashboards-declarative`
	 * change deleted — the same class of stale selector the sibling test above
	 * already documents. So the old assertion could not have passed even if the
	 * buttons had existed.
	 *
	 * The fix was a navigation fix, not a test fix: menu entries were declared
	 * for all three reporting pages and menu-layout relocated them under
	 * `Rapportage`, so "Reporting" became a group carrying its own sub-reports.
	 *
	 * UPDATED (ADR-112): that group is now one Reports page of cards, and the
	 * per-report menu entries are retired. The route these tests take changes
	 * with it — through the Reports page rather than the sidebar — but what
	 * they assert does not, and must not: a user who has not memorised a URL
	 * can still get to every report. That is the whole point of #687, and the
	 * ADR-044 no-functionality-loss guarantee the removal rests on.
	 */
	test('a reader reaches channel analytics from the reports page', async ({
		page,
	}) => {
		// One load, not two: openApp() booted the Dashboard and the next
		// line navigated straight off it.
		await gotoAppRoute(page, '/reports')

		await page
			.getByTestId('cn-report-card')
			.filter({ hasText: /Channel analytics|Kanaalanalyse/i })
			.first()
			.click()

		await expect(page).toHaveURL(/rapportage\/channels/, { timeout: 10000 })
		await expect(
			page.getByRole('heading', { name: /Channel Analytics|Kanaalanalyse/i }),
		).toBeVisible({ timeout: 10000 })
	})

	test('a reader reaches agent performance from the reports page', async ({
		page,
	}) => {
		// One load, not two: openApp() booted the Dashboard and the next
		// line navigated straight off it.
		await gotoAppRoute(page, '/reports')

		await page
			.getByTestId('cn-report-card')
			.filter({ hasText: /Agent performance|Agentprestaties/i })
			.first()
			.click()

		await expect(page).toHaveURL(/rapportage\/agents/, { timeout: 10000 })
		await expect(
			page.getByRole('heading', {
				name: /Agent Performance|Agentprestaties/i,
			}),
		).toBeVisible({ timeout: 10000 })
	})

	test('the reports page lists every report pipelinq offers', async ({ page }) => {
		// The assertion that distinguishes "regrouped" from "lost". Report pages
		// went from menu entries to cards; a change that dropped one would
		// otherwise look like a tidier menu.
		//
		// This used to be `toHaveCount(4)` alone, which is a weaker claim than it
		// reads as. A bare count cannot say WHICH report went missing, it passes
		// if one report is swapped for another, and it fails on a report being
		// ADDED — which is not a loss, and is exactly what happened when Forecast
		// and Loyalty moved here off the sidebar. Naming them keeps the guard the
		// comment above promises: removing one fails and says which, and adding
		// one fails until somebody writes the new name down here on purpose.
		const EXPECTED = [
			'Reporting',
			'Contact reporting',
			'Channel analytics',
			'Agent performance',
			'Forecast',
			'Loyalty reporting',
			'Campaign report',
			'Weekly review',
		]

		// One load, not two: openApp() booted the Dashboard and the next
		// line navigated straight off it.
		await gotoAppRoute(page, '/reports')

		const cards = page.getByTestId('cn-report-card')
		await expect(cards).toHaveCount(EXPECTED.length)

		// Matched on the START of each card's text, not `hasText`, which is a
		// substring: "Reporting" is inside "Contact reporting" and "Loyalty
		// reporting" too, so a `hasText` check would still pass with the
		// Reporting card deleted. A card reads "<label> <description>
		// <category>", so the label is its prefix and nothing else's.
		const texts = (await cards.allTextContents()).map((t) =>
			t.replace(/\s+/g, ' ').trim(),
		)
		for (const label of EXPECTED) {
			expect(
				texts.filter((t) => t.startsWith(label)),
				`exactly one card on the reports page must be titled "${label}"`,
			).toHaveLength(1)
		}
	})

	test('channel analytics page loads', async ({ page }) => {
		// Deep-link by PATH. This comment used to say the opposite — that a
		// path-form goto boots the shell at the Dashboard and the route has to
		// travel in the hash — which was true until #1684 moved the shell to
		// createWebHistory(routerBase()).
		await gotoAppRoute(page, '/rapportage/channels')
		await expect(
			page.getByRole('heading', { name: /Channel Analytics|Kanaalanalyse/i }),
		).toBeVisible({ timeout: 15000 })
	})

	test('agent performance page loads', async ({ page }) => {
		await gotoAppRoute(page, '/rapportage/agents')
		await expect(
			page.getByRole('heading', {
				name: /Agent Performance|Agentprestaties/i,
			}),
		).toBeVisible({ timeout: 15000 })
	})
})
