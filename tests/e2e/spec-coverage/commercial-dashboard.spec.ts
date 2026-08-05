/*
 * SPDX-FileCopyrightText: 2026 Pipelinq Contributors
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Gate-19 behavioral e2e coverage for the commercial-dashboard split
 * (openspec/changes/commercial-dashboard). The landing dashboard `/` is
 * the Commercial overview (revenue / pipeline / win KPIs + sales charts +
 * deal tables); the previous operational widgets live on the Operational
 * overview reachable from the nav.
 */

import { test, expect } from '@playwright/test'
import { openApp, trackPipelinqErrors, assertNoHardError } from '../helpers/pipelinq'

// @e2e commercial-dashboard::commercial-dashboard-renders-kpis-and-charts
test('Commercial dashboard: KPI strip + sales charts render on the landing page', async ({ page }) => {
	const errs = trackPipelinqErrors(page)
	await openApp(page)

	const content = page.locator('#content-vue')

	// The six commercial KPI cards.
	await expect(content.getByText('Revenue', { exact: true }).first()).toBeVisible({ timeout: 15000 })
	await expect(content.getByText('Won Value').first()).toBeVisible()
	await expect(content.getByText('Win Rate').first()).toBeVisible()
	await expect(content.getByText('Avg Deal Size').first()).toBeVisible()
	await expect(content.getByText('Weighted Forecast').first()).toBeVisible()
	await expect(content.getByText('Open Pipeline').first()).toBeVisible()

	// The commercial chart + table widget chrome titles.
	await expect(content.getByText('Revenue over time').first()).toBeVisible()
	await expect(content.getByText('Pipeline by stage').first()).toBeVisible()
	await expect(content.getByText('Top customers by revenue').first()).toBeVisible()
	await expect(content.getByText('Deals closing soon').first()).toBeVisible()

	// At least one ApexCharts svg has mounted from a commercial chart.
	await expect(content.locator('svg.apexcharts-svg').first()).toBeVisible({ timeout: 15000 })

	await assertNoHardError(page)
	expect(errs(), `pipelinq console errors: ${errs().join(' || ')}`).toEqual([])
})

// @e2e commercial-dashboard::operational-widgets-reachable-after-the-split
test('Operational dashboard: previous widgets remain reachable from the nav', async ({ page }) => {
	await openApp(page)

	// Deep-link the OperationalDashboard via the SPA hash (`/operational`); a
	// path-form goto boots the shell at the default Commercial dashboard.
	await page.goto('/apps/pipelinq/#/operational')
	await page.reload()

	const content = page.locator('#content-vue')
	// Operational KPIs/panels that used to live on the old Dashboard.
	await expect(content.getByText('Lead Conversion Rate').first()).toBeVisible({ timeout: 15000 })
	await expect(content.getByText('Customer Satisfaction').first()).toBeVisible()
	await expect(content.getByText('Requests by Status').first()).toBeVisible()

	await assertNoHardError(page)
})
