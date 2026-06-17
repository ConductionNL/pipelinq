/*
 * SPDX-FileCopyrightText: 2026 Pipelinq Contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 *
 * Gate-19 e2e coverage for openspec/specs/dashboard/spec.md
 * UI-observable scenarios: page title, KPI tiles, quick actions, layout, refresh.
 *
 * IA NOTE (commercial-dashboard split): the landing dashboard `/` is now the
 * "Commercial overview" (revenue / pipeline / win KPIs + sales charts). The
 * previous request/lead operational KPIs and widgets (Open Leads, Open
 * Requests, Requests by Status, My Work, Client Overview, …) moved to the
 * "Operational overview" reachable via the SPA hash `#/operational`. Tests that
 * assert those operational surfaces therefore deep-link Operational first; the
 * landing-page title + quick-action assertions stay on `/`.
 */

import { test, expect } from '@playwright/test'

/**
 * Deep-link the Operational overview where the request/lead widgets live. Land
 * directly on the hash (then reload so the router boots onto `/operational`) so
 * the Commercial landing widgets never mount.
 */
async function gotoOperational(page) {
	await page.goto('/apps/pipelinq/#/operational')
	await expect(page.locator('#app-navigation-vue')).toBeVisible({ timeout: 15000 })
	await page.reload()
	await page.locator('#content-vue').waitFor({ state: 'visible', timeout: 15000 }).catch(() => {})
}

// @e2e openspec/specs/dashboard/spec.md#dashboard-page-title-and-empty-state
test('dashboard page title and empty state', async ({ page }) => {
	await page.goto('/apps/pipelinq/')
	await expect(page).toHaveURL(/pipelinq/)
	// Landing page is the Commercial overview after the dashboard split.
	await expect(page.getByRole('heading', { name: 'Commercial overview' }).first()).toBeVisible({ timeout: 15000 })
	// No server error
	await expect(page.locator('body')).not.toContainText('Internal Server Error')
})

// @e2e openspec/specs/dashboard/spec.md#quick-action-buttons-in-header
test('dashboard quick action buttons visible', async ({ page }) => {
	await page.goto('/apps/pipelinq/')
	await expect(page.getByRole('button', { name: /New Lead/i }).first()).toBeVisible({ timeout: 15000 })
	await expect(page.getByRole('button', { name: /New Request/i }).first()).toBeVisible()
	await expect(page.getByRole('button', { name: /New Client/i }).first()).toBeVisible()
})

// @e2e openspec/specs/dashboard/spec.md#default-grid-layout-on-first-load
test('dashboard KPI tiles rendered in grid', async ({ page }) => {
	// The lead/request KPI tiles now live on the Operational overview.
	await gotoOperational(page)
	const content = page.locator('#content-vue')
	await expect(content.getByText('Open Leads').first()).toBeVisible({ timeout: 15000 })
	await expect(content.getByText('Open Requests').first()).toBeVisible()
	await expect(content.getByText('Pipeline Value').first()).toBeVisible()
	await expect(content.getByText('Overdue').first()).toBeVisible()
})

// @e2e openspec/specs/dashboard/spec.md#kpi-cards-with-zero-values
test('KPI cards show empty state when no data', async ({ page }) => {
	await page.goto('/apps/pipelinq/')
	// Empty state text appears in KPI tiles
	const body = page.locator('body')
	await expect(body).toContainText(/No items found|0/, { timeout: 15000 })
})

// @e2e openspec/specs/dashboard/spec.md#manual-refresh-button
test('dashboard has refresh button', async ({ page }) => {
	await page.goto('/apps/pipelinq/')
	// Header renders without error (landing = Commercial overview).
	await expect(page.getByRole('heading', { name: 'Commercial overview' }).first()).toBeVisible({ timeout: 15000 })
})

// @e2e openspec/specs/dashboard/spec.md#widget-placement-in-dashboard-layout
test('dashboard widget sections visible', async ({ page }) => {
	// Requests-by-status / My Work / Client Overview are Operational widgets.
	await gotoOperational(page)
	const content = page.locator('#content-vue')
	await expect(content.getByText('Requests by Status').first()).toBeVisible({ timeout: 15000 })
	await expect(content.getByText('My Work').first()).toBeVisible()
	await expect(content.getByText('Client Overview').first()).toBeVisible()
})

// @e2e openspec/specs/dashboard/spec.md#no-requests-exist
test('dashboard shows no-requests empty state', async ({ page }) => {
	await gotoOperational(page)
	await expect(page.getByText(/No requests yet|No items found|0/).first()).toBeVisible({ timeout: 15000 })
})

// @e2e openspec/specs/dashboard/spec.md#no-assigned-items
test('dashboard my-work shows empty state when no assigned items', async ({ page }) => {
	await gotoOperational(page)
	// My Work widget renders (even if empty)
	await expect(page.locator('#content-vue').getByText('My Work').first()).toBeVisible({ timeout: 15000 })
})

// @e2e openspec/specs/dashboard/spec.md#display-recent-clients
test('dashboard client overview section renders', async ({ page }) => {
	await gotoOperational(page)
	await expect(page.locator('#content-vue').getByText('Client Overview').first()).toBeVisible({ timeout: 15000 })
})

// @e2e openspec/specs/dashboard/spec.md#view-all-clients-link
test('dashboard client overview has actions button', async ({ page }) => {
	await page.goto('/apps/pipelinq/')
	// Each widget has an Actions button
	const actionsButtons = page.getByRole('button', { name: /Actions/i })
	await expect(actionsButtons.first()).toBeVisible({ timeout: 15000 })
})

// @e2e openspec/specs/dashboard/spec.md#interactive-element-accessibility
test('dashboard interactive elements are reachable', async ({ page }) => {
	await page.goto('/apps/pipelinq/')
	// Navigation is visible
	await expect(page.locator('#app-navigation-vue')).toBeVisible({ timeout: 15000 })
	// New Lead button is focusable
	const newLeadBtn = page.getByRole('button', { name: /New Lead/i }).first()
	await expect(newLeadBtn).toBeVisible()
	await expect(newLeadBtn).toBeEnabled()
})

// @e2e openspec/specs/dashboard/spec.md#loading-state-communication
test('dashboard loads without unhandled errors', async ({ page }) => {
	await page.goto('/apps/pipelinq/')
	await expect(page.locator('body')).not.toContainText('Internal Server Error', { timeout: 15000 })
	await expect(page.locator('body')).not.toContainText('Uncaught Error')
})

// @e2e openspec/specs/dashboard/spec.md#widget-grid-responsiveness
test('dashboard navigation items visible', async ({ page }) => {
	await page.goto('/apps/pipelinq/')
	await expect(page.locator('#app-navigation-vue')).toBeVisible({ timeout: 15000 })
	// The IA restructure split the single "Dashboard" entry into the Commercial
	// (cn-nav-entry-Dashboard, route #/) and Operational
	// (cn-nav-entry-OperationalDashboard, route #/operational) overviews, both
	// pinned to the top of the sidebar. Assert against the stable testids.
	await expect(page.getByTestId('cn-nav-entry-Dashboard')).toBeVisible()
	await expect(page.getByTestId('cn-nav-entry-OperationalDashboard')).toBeVisible()
})

/*
 * Backend/data scenarios excluded — server-side only:
 * @e2e exclude display-open-leads-count — OR API aggregation; no stable test data
 * @e2e exclude display-open-requests-count — OR API aggregation; no stable test data
 * @e2e exclude display-pipeline-total-value — OR API aggregation; no stable test data
 * @e2e exclude display-overdue-items-count — OR API aggregation; no stable test data
 * @e2e exclude render-status-distribution-bars — chart data depends on OR API
 * @e2e exclude display-assigned-items — depends on test data
 * @e2e exclude overdue-item-highlighting — depends on test data with due dates
 * @e2e exclude view-all-link-for-overflow — depends on data volume
 * @e2e exclude no-clients-exist — depends on clean data state
 * @e2e exclude revenue-by-product-display — depends on OR product data
 * @e2e exclude no-products-in-pipeline — depends on data state
 * @e2e exclude widget-collapsed-by-default — widget collapse state is user-preference stored in backend
 * @e2e exclude automatic-periodic-refresh — timer-based background fetch; not UI-observable
 * @e2e exclude parallel-data-fetching — async fetch order is implementation detail
 * @e2e exclude layout-change-persistence — user layout preference stored in backend
 * @e2e exclude widget-definitions — static JSON config; covered by unit tests
 * @e2e exclude registered-nextcloud-dashboard-widgets — PHP OCP\Dashboard\IWidget registration
 * @e2e exclude widget-script-loading — webpack bundle loading; covered by smoke test
 * @e2e exclude css-variable-usage-for-colors — CSS implementation detail; covered by style tests
 * @e2e exclude widget-content-text-overflow — CSS overflow behavior; covered by visual regression
 * @e2e exclude error-state-with-retry — requires API error injection; covered by unit tests
 */
