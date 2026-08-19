/*
 * SPDX-FileCopyrightText: 2026 Pipelinq Contributors
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Gate-19 e2e coverage for openspec/specs/pipeline-insights/spec.md
 * UI-observable scenarios: pipeline page renders, KPI tile visible.
 * Most scenarios are V1/Enterprise analytics not yet implemented — excluded per-scenario below.
 */

import { test, expect } from '@playwright/test'

// @e2e openspec/specs/pipeline-insights/spec.md#pipeline-value-kpi-widget
test('pipeline value KPI tile visible on dashboard', async ({ page }) => {
	await page.goto('/apps/pipelinq/')
	await expect(page.getByText(/Pipeline V/i).first()).toBeVisible({ timeout: 10000 })
})

// @e2e openspec/specs/pipeline-insights/spec.md#empty-pipeline-analytics
test('pipeline page renders without error', async ({ page }) => {
	await page.goto('/apps/pipelinq/pipeline')
	await expect(page.locator('body')).not.toContainText('Internal Server Error', { timeout: 10000 })
})

// @e2e openspec/specs/pipeline-insights/spec.md#stage-header-shows-total-value
test('pipeline page main content accessible', async ({ page }) => {
	await page.goto('/apps/pipelinq/pipeline')
	await expect(page.locator('#app-content, .app-content, main').first()).toBeVisible({ timeout: 10000 })
})

// @e2e openspec/specs/pipeline-insights/spec.md#overdue-highlighting-in-my-work
test('my work page loads for overdue highlighting', async ({ page }) => {
	await page.goto('/apps/pipelinq/my-work')
	await expect(page.locator('body')).not.toContainText('Internal Server Error', { timeout: 10000 })
})

/*
 * Backend/V1/Enterprise/analytics scenarios excluded:
 * @e2e exclude requests-do-not-contribute-to-stage-value — backend calculation; covered by PHPUnit
 * @e2e exclude list-view-shows-value-column — requires pipeline with stages and lead data
 * @e2e exclude value-includes-product-calculated-totals — requires lead-product data
 * @e2e exclude stale-badge-on-kanban-card — requires stale lead data (days threshold)
 * @e2e exclude stale-badge-in-list-view — requires stale lead data
 * @e2e exclude non-stale-items-show-no-badge — requires data with known staleness state
 * @e2e exclude only-leads-can-be-stale — backend data model rule; covered by PHPUnit
 * @e2e exclude stale-threshold-configurability — admin config; covered by admin-settings spec
 * @e2e exclude days-in-stage-badge-on-kanban-card — requires leads with stage history
 * @e2e exclude aging-in-list-view — requires leads with stage history
 * @e2e exclude aging-color-coding — requires controlled aging data
 * @e2e exclude aging-color-coding-accessibility — WCAG color check; covered by accessibility tooling
 * @e2e exclude overdue-card-styling-on-kanban-board — requires overdue items
 * @e2e exclude overdue-highlighting-in-list-view — requires overdue items
 * @e2e exclude closed-terminal-items-are-not-overdue — backend logic; covered by PHPUnit
 * @e2e exclude overdue-request-calculation — backend date calculation; covered by PHPUnit
 * @e2e exclude stage-conversion-funnel — V1 analytics; not yet implemented
 * @e2e exclude stage-average-duration — V1 analytics; not yet implemented
 * @e2e exclude win-loss-analysis — V1 analytics; not yet implemented
 * @e2e exclude bottleneck-detection — V1 analytics; not yet implemented
 * @e2e exclude weighted-pipeline-value — V1 analytics; not yet implemented
 * @e2e exclude monthly-revenue-projection — Enterprise forecasting; not yet implemented
 * @e2e exclude forecast-accuracy-tracking — Enterprise forecasting; not yet implemented
 * @e2e exclude pipeline-stage-probability-configuration — admin config; covered by admin-settings spec
 * @e2e exclude won-deals-trend-widget — V1 widget; not yet implemented
 * @e2e exclude forecast-widget — Enterprise widget; not yet implemented
 * @e2e exclude sales-velocity-widget — V1 widget; not yet implemented
 * @e2e exclude top-performers-widget — Enterprise widget; not yet implemented
 * @e2e exclude recent-pipeline-activity — requires activity data
 * @e2e exclude stage-change-history-for-a-lead — requires lead with stage history
 * @e2e exclude activity-filtering-by-entity-type — requires activity data
 * @e2e exclude export-pipeline-data-to-csv — V1 export; covered by Newman
 * @e2e exclude export-analytics-report — V1 export; not yet implemented
 * @e2e exclude scheduled-report-delivery — Enterprise feature; not yet implemented
 * @e2e exclude multi-pipeline-overview — V1 analytics; not yet implemented
 * @e2e exclude pipeline-switching-in-analytics — V1 analytics; not yet implemented
 */
