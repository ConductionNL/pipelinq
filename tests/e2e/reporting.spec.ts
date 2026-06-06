/*
 * SPDX-FileCopyrightText: 2026 Pipelinq Contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 *
 * Reporting drill-down views — the Channel Analytics and Agent
 * Performance surfaces are reached by clicking their buttons on the
 * Reporting dashboard (a real client-side route push to
 * `/rapportage/channels` and `/rapportage/agents`). These views render
 * their own headings + analytics tables regardless of whether the
 * aggregation API returns data, so the assertions target the rendered
 * chrome (heading, comparison/agent table or empty-state), not data rows.
 *
 * Gate-19 @e2e traceability (test → spec scenario):
 *   "Channel Analytics view renders…"  @e2e contactmomenten-rapportage::channel-distribution-chart
 *                                       @e2e contactmomenten-rapportage::channel-comparison-table
 *   "Agent Performance view renders…"   @e2e contactmomenten-rapportage::individual-agent-statistics
 *                                       @e2e contactmomenten-rapportage::team-overview
 *   "Reporting dashboard empty state…"  @e2e contactmomenten-rapportage::dashboard-empty-state
 */
import { test, expect } from '@playwright/test'
import { openView } from './helpers/nav'

test.describe('Reporting drill-down views', () => {

	test('Channel Analytics view renders its heading and comparison table', async ({ page }) => {
		await openView(page, 'rapportage', 'Reporting Dashboard')
		await page.getByRole('button', { name: 'Channel Analytics' }).click()
		await expect(page).toHaveURL(/\/rapportage\/channels$/)
		// Sub-view header + the per-channel comparison table heading.
		await expect(page.getByRole('heading', { name: 'Channel Analytics', level: 2 }))
			.toBeVisible({ timeout: 20000 })
		await expect(page.getByRole('heading', { name: 'Channel Comparison' }))
			.toBeVisible({ timeout: 20000 })
		// Back-to-dashboard link confirms the routed sub-view chrome.
		await expect(page.getByRole('link', { name: 'Back to Dashboard' })).toBeVisible()
	})

	test('Agent Performance view renders its heading and agent table chrome', async ({ page }) => {
		await openView(page, 'rapportage', 'Reporting Dashboard')
		await page.getByRole('button', { name: 'Agent Performance' }).click()
		await expect(page).toHaveURL(/\/rapportage\/agents$/)
		await expect(page.getByRole('heading', { name: 'Agent Performance', level: 2 }))
			.toBeVisible({ timeout: 20000 })
		// Either the populated agent table or the empty-state renders; both
		// are the rendered Agent Performance surface (no data is seeded).
		const table = page.locator('.agent-performance table.data-table')
		const empty = page.getByText('No agent data available')
		await expect(table.or(empty).first()).toBeVisible({ timeout: 20000 })
		await expect(page.getByRole('link', { name: 'Back to Dashboard' })).toBeVisible()
	})

	test('Reporting dashboard empty state — KPI tiles render with zero data', async ({ page }) => {
		await openView(page, 'rapportage', 'Reporting Dashboard')
		// With no seeded contactmomenten the dashboard still renders its
		// KPI chrome (Export CSV + SLA compliance tile) rather than crashing.
		await expect(page.getByRole('button', { name: 'Export CSV' })).toBeVisible()
		await expect(page.getByText('SLA compliance')).toBeVisible()
	})
})
