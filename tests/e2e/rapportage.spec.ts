// SPDX-License-Identifier: EUPL-1.2
// Copyright (C) 2026 Conduction B.V.
//
// REQ-CR-001: Reporting dashboard loads with KPI cards visible.
// @spec openspec/changes/contactmomenten-rapportage/tasks.md#task-6
import { expect, test } from '@playwright/test'
import { openApp } from './helpers/pipelinq.ts'

test.describe('Rapportage (Reporting)', () => {
	test.beforeEach(async ({ page }) => {
		// The contactmomenten Reporting Dashboard (KPI cards) lives at the
		// `/rapportage/contactmomenten` page (manifest id RapportageContactmomenten
		// → RapportageDashboard.vue). The "Reporting" sidebar link now points at
		// the Lead-analytics page (`/rapportage`), so deep-link the dashboard
		// route directly via the SPA hash. A path-form goto boots the shell at the
		// Dashboard; a hash goto mounts the target view. Reload once so the view
		// re-queries its KPI data after the same-document hash change.
		await page.goto('/apps/pipelinq/rapportage/contactmomenten')
		await expect(page.locator('body')).not.toContainText('Internal Server Error')
		await page.reload()
	})

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
		await openApp(page)
		await page.goto('/apps/pipelinq/reports')

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
		await openApp(page)
		await page.goto('/apps/pipelinq/reports')

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

	test('the reports page lists every report pipelinq offers', async ({
		page,
	}) => {
		// The assertion that distinguishes "regrouped" from "lost". Four report
		// pages went from four menu entries to four cards; a change that dropped
		// one would otherwise look like a tidier menu.
		await openApp(page)
		await page.goto('/apps/pipelinq/reports')

		await expect(page.getByTestId('cn-report-card')).toHaveCount(4)
	})

	test('channel analytics page loads', async ({ page }) => {
		// Deep-link via the SPA hash; a path-form goto boots the shell at the
		// Dashboard instead of the target view.
		await page.goto('/apps/pipelinq/rapportage/channels')
		await page.reload()
		await expect(
			page.getByRole('heading', { name: /Channel Analytics|Kanaalanalyse/i }),
		).toBeVisible({ timeout: 15000 })
	})

	test('agent performance page loads', async ({ page }) => {
		await page.goto('/apps/pipelinq/rapportage/agents')
		await page.reload()
		await expect(
			page.getByRole('heading', {
				name: /Agent Performance|Agentprestaties/i,
			}),
		).toBeVisible({ timeout: 15000 })
	})
})
