/*
 * SPDX-FileCopyrightText: 2026 Pipelinq Contributors
 * SPDX-License-Identifier: EUPL-1.2
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
	await page.goto('/apps/pipelinq/operational')
	await expect(page.locator('#app-navigation-vue')).toBeVisible({ timeout: 15000 })
	await page.reload()
	await page
		.locator('#content-vue')
		.waitFor({ state: 'visible', timeout: 15000 })
		.catch(() => {})
}

// @e2e dashboard::cross-module-kpi-cards-as-individual-dashboard-widgets
// @e2e dashboard::analytics-widget-registration
test('Dashboard: analytics KPIs render as individual widgets, no Unified Analytics panel', async ({
	page,
}) => {
	const errs = trackPipelinqErrors(page)
	await openOperational(page)

	const content = page.locator('#content-vue')
	await expect(content.getByText('Lead Conversion Rate').first()).toBeVisible({
		timeout: 15000,
	})
	await expect(content.getByText('Avg Request Resolution').first()).toBeVisible()
	// Targeted by ROLE, not text. This widget's manifest `title` is "Contact
	// Moment Volume" but its stat `label` — the only text it paints — is
	// "Contacts". The title survives as the region's accessible name, so
	// getByText could never match it while the widget rendered perfectly.
	await expect(
		content.getByRole('region', { name: 'Contact Moment Volume' }),
	).toBeVisible()

	// "Customer Satisfaction" is NOT asserted here. src/manifest.json records
	// that the SatisfactionKpiWidget (widget id 'satisfaction', layout slot 17)
	// "was removed 2026-07 because its data source is permanently empty
	// (AnalyticsService hardcodes no survey responses after the forms-leaf
	// migration); it returns with real CSAT data via openspec change
	// customer-satisfaction-closed-loop". Asserting it would assert a future
	// state; asserting its ABSENCE would block that change from landing.

	// The mega-panel (and its double title) must be gone.
	await expect(content.getByText('Unified Analytics')).toHaveCount(0)

	await assertNoHardError(page)
	expect(errs(), `pipelinq console errors: ${errs().join(' || ')}`).toEqual([])
})

// @e2e dashboard::period-driven-by-widget-header-date-chips
test('Dashboard: widget-header date chips drive the analytics period, no page-level picker', async ({
	page,
}) => {
	await openOperational(page)

	// The chart widgets surface the shared range as title-bar chips…
	const chip = page.getByTestId('cn-dashboard-page-date-chip-leads-over-time')
	await expect(chip).toBeVisible({ timeout: 15000 })
	await expect(
		page.getByTestId('cn-dashboard-page-date-chip-requests-by-category'),
	).toBeVisible()

	// …and the page-level header picker is gone (showHeaderPicker: false),
	// as is the old widget-local "Period" NcSelect.
	await expect(page.getByTestId('cn-dashboard-page-date-range')).toHaveCount(0)
	await expect(
		page.locator('#content-vue').getByLabel('Period', { exact: true }),
	).toHaveCount(0)

	// Picking a preset in a chip re-fetches the analytics endpoints with
	// the mapped period parameter (chips write the SHARED range).
	const overviewRefetch = page.waitForRequest(
		(req) =>
			req.url().includes('/apps/pipelinq/api/analytics/overview')
			&& req.url().includes('period=week'),
		{ timeout: 15000 },
	)
	await chip.getByRole('button').click()
	await page.getByRole('button', { name: 'Last 7 days' }).click()
	await overviewRefetch
})

// @e2e dashboard::trend-chart-leads-over-time
test('Dashboard: leads-over-time renders as a chrome-titled chart widget', async ({
	page,
}) => {
	await openOperational(page)

	const content = page.locator('#content-vue')
	await expect(
		content.getByRole('heading', { name: 'Leads over time' }),
	).toBeVisible({ timeout: 15000 })
})

// @e2e dashboard::trend-chart-requests-by-category
test('Dashboard: requests-by-category renders as a chrome-titled chart widget', async ({
	page,
}) => {
	await openOperational(page)

	const content = page.locator('#content-vue')
	await expect(
		content.getByRole('heading', { name: 'Requests by category' }),
	).toBeVisible({ timeout: 15000 })
})
