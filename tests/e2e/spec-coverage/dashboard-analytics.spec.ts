/*
 * SPDX-FileCopyrightText: 2026 Pipelinq Contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 *
 * Gate-19 behavioral e2e coverage for the decomposed analytics dashboard
 * widgets (openspec/changes/decompose-unified-analytics, REQ-DASH-010).
 * The Unified Analytics mega-panel is gone: four KPI cards + two trend
 * charts render as individual grid widgets, and the reporting period is
 * driven by the dashboard-level date-range header.
 *
 * IA NOTE (commercial-dashboard split): these cross-module analytics KPIs and
 * the leads/requests trend charts now live on the OPERATIONAL overview
 * (`/operational`), not the landing Commercial overview (`/`). Each test
 * therefore deep-links the Operational dashboard via the SPA hash before
 * asserting. The date-chip / refetch behaviour is unchanged by the split.
 */

import { test, expect } from '@playwright/test'
import { openApp, trackPipelinqErrors, assertNoHardError } from '../helpers/pipelinq'

/**
 * Land directly on the Operational overview where these widgets live. We set the
 * SPA hash on the very first navigation (then a reload so the router boots
 * straight onto `/operational`) so the landing Commercial dashboard's widgets
 * never mount — otherwise their in-flight fetches abort when we route away and
 * surface as spurious "Failed to fetch" console noise.
 */
async function openOperational(page) {
	await page.goto('/apps/pipelinq/#/operational')
	await expect(page.locator('#app-navigation-vue')).toBeVisible({ timeout: 15000 })
	await page.reload()
	await page.locator('#content-vue').waitFor({ state: 'visible', timeout: 15000 }).catch(() => {})
	// Wait for the dashboard chrome + its first widget to actually mount before
	// asserting, so a test never races the widget grid's initial render.
	await expect(page.getByTestId('cn-dashboard-page')).toBeVisible({ timeout: 15000 })
	await expect(
		page.locator('#content-vue').getByRole('heading', { name: 'Operational overview' }),
	).toBeVisible({ timeout: 15000 })
}

// @e2e dashboard::cross-module-kpi-cards-as-individual-dashboard-widgets
// @e2e dashboard::analytics-widget-registration
test('Dashboard: analytics KPIs render as individual widgets, no Unified Analytics panel', async ({ page }) => {
	const errs = trackPipelinqErrors(page)
	await openOperational(page)

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

// Removed: the "widget-header date chips drive the analytics period" feature
// (cn-dashboard-page-date-chip-* chips + chip-driven /analytics/overview
// refetch) was dropped in the IA restructure. The Operational overview now
// renders the chart widgets statically with no per-widget date chips and no
// page-level date-range picker, so there is no period-selection surface left to
// assert. The "no page-level picker" half of the old assertion is now the
// dashboard's permanent state rather than a behaviour worth a dedicated test.

// @e2e dashboard::trend-chart-leads-over-time
test('Dashboard: leads-over-time renders as a chrome-titled chart widget', async ({ page }) => {
	await openOperational(page)

	const content = page.locator('#content-vue')
	await expect(content.getByRole('heading', { name: 'Leads over time' })).toBeVisible({ timeout: 15000 })
})

// @e2e dashboard::trend-chart-requests-by-category
test('Dashboard: requests-by-category renders as a chrome-titled chart widget', async ({ page }) => {
	await openOperational(page)

	const content = page.locator('#content-vue')
	await expect(content.getByRole('heading', { name: 'Requests by category' })).toBeVisible({ timeout: 15000 })
})
