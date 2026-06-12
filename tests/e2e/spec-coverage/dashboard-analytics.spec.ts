/*
 * SPDX-FileCopyrightText: 2026 Pipelinq Contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 *
 * Gate-19 behavioral e2e coverage for the decomposed analytics dashboard
 * widgets (openspec/changes/decompose-unified-analytics, REQ-DASH-010).
 * The Unified Analytics mega-panel is gone: four KPI cards + two trend
 * charts render as individual grid widgets, and the reporting period is
 * driven by the dashboard-level date-range header.
 */

import { test, expect } from '@playwright/test'
import { openApp, trackPipelinqErrors, assertNoHardError } from '../helpers/pipelinq'

// @e2e dashboard::cross-module-kpi-cards-as-individual-dashboard-widgets
// @e2e dashboard::analytics-widget-registration
test('Dashboard: analytics KPIs render as individual widgets, no Unified Analytics panel', async ({ page }) => {
	const errs = trackPipelinqErrors(page)
	await openApp(page)

	const content = page.locator('#content-vue')
	await expect(content.getByText('Lead Conversion Rate').first()).toBeVisible({ timeout: 15000 })
	await expect(content.getByText('Avg Request Resolution').first()).toBeVisible()
	await expect(content.getByText('Contact Moment Volume').first()).toBeVisible()
	await expect(content.getByText('Customer Satisfaction').first()).toBeVisible()

	// The mega-panel (and its double title) must be gone.
	await expect(content.getByText('Unified Analytics')).toHaveCount(0)

	await assertNoHardError(page)
	expect(errs(), `pipelinq console errors: ${errs().join(' || ')}`).toEqual([])
})

// @e2e dashboard::period-driven-by-the-dashboard-date-range-header
test('Dashboard: one date-range header drives the analytics period, no widget-local selector', async ({ page }) => {
	await openApp(page)

	// Date-range header rendered by CnDashboardPage, between header and grid.
	const rangeHeader = page.getByTestId('cn-dashboard-page-date-range')
	await expect(rangeHeader).toBeVisible({ timeout: 15000 })

	// No widget-local "Period" NcSelect anywhere in the grid anymore.
	await expect(page.locator('#content-vue').getByLabel('Period', { exact: true })).toHaveCount(0)

	// Changing the preset re-fetches the analytics endpoints with the
	// mapped period parameter.
	const overviewRefetch = page.waitForRequest(
		(req) => req.url().includes('/apps/pipelinq/api/analytics/overview') && req.url().includes('period=week'),
		{ timeout: 15000 },
	)
	await rangeHeader.getByTestId('cn-date-range-picker-preset').click()
	await page.getByRole('option', { name: 'Last 7 days' }).click()
	await overviewRefetch
})

// @e2e dashboard::trend-chart-leads-over-time
test('Dashboard: leads-over-time renders as a chrome-titled chart widget', async ({ page }) => {
	await openApp(page)

	const content = page.locator('#content-vue')
	await expect(content.getByRole('heading', { name: 'Leads over time' })).toBeVisible({ timeout: 15000 })
})

// @e2e dashboard::trend-chart-requests-by-category
test('Dashboard: requests-by-category renders as a chrome-titled chart widget', async ({ page }) => {
	await openApp(page)

	const content = page.locator('#content-vue')
	await expect(content.getByRole('heading', { name: 'Requests by category' })).toBeVisible({ timeout: 15000 })
})
