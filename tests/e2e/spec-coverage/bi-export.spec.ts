/*
 * SPDX-FileCopyrightText: 2026 Pipelinq Contributors
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Gate-19 e2e coverage for the BI export and data warehouse sink feature.
 * Maps to openspec/changes/bi-export-and-data-warehouse-sink/specs.md.
 *
 * UI-observable scenarios: pages render, navigation works, empty states are
 * not server errors. Backend-only scenarios (cron firing, watermark queries,
 * provider COPY semantics) are explicitly excluded at the bottom of this file
 * and covered by PHPUnit + integration tests.
 */

import { test, expect } from '@playwright/test'

// @e2e openspec/changes/bi-export-and-data-warehouse-sink/specs.md#REQ-BIE-002
test('export jobs list page renders without error', async ({ page }) => {
	await page.goto('/apps/pipelinq/export/jobs')
	await expect(page).toHaveURL(/export\/jobs/, { timeout: 10000 })
	await expect(page.locator('body')).not.toContainText('Internal Server Error')
	await expect(page.locator('body')).not.toContainText('Uncaught Error')
})

// @e2e openspec/changes/bi-export-and-data-warehouse-sink/specs.md#REQ-BIE-002-01
test('export jobs page main content area renders', async ({ page }) => {
	await page.goto('/apps/pipelinq/export/jobs')
	await expect(page.locator('#app-content, .app-content, main').first()).toBeVisible({ timeout: 10000 })
})

// @e2e openspec/changes/bi-export-and-data-warehouse-sink/specs.md#REQ-BIE-002-02
test('export job form page renders for new job', async ({ page }) => {
	await page.goto('/apps/pipelinq/export/jobs/new')
	await expect(page).toHaveURL(/export\/jobs\/new/, { timeout: 10000 })
	await expect(page.locator('body')).not.toContainText('Internal Server Error')
})

// @e2e openspec/changes/bi-export-and-data-warehouse-sink/specs.md#REQ-BIE-001
test('export destinations list page renders without error', async ({ page }) => {
	await page.goto('/apps/pipelinq/export/destinations')
	await expect(page).toHaveURL(/export\/destinations/, { timeout: 10000 })
	await expect(page.locator('body')).not.toContainText('Internal Server Error')
	await expect(page.locator('body')).not.toContainText('Uncaught Error')
})

// @e2e openspec/changes/bi-export-and-data-warehouse-sink/specs.md#REQ-BIE-001-01
test('export destinations page main content area renders', async ({ page }) => {
	await page.goto('/apps/pipelinq/export/destinations')
	await expect(page.locator('#app-content, .app-content, main').first()).toBeVisible({ timeout: 10000 })
})

// @e2e openspec/changes/bi-export-and-data-warehouse-sink/specs.md#REQ-BIE-001-03
test('export destination form page renders for new destination', async ({ page }) => {
	await page.goto('/apps/pipelinq/export/destinations/new')
	await expect(page).toHaveURL(/export\/destinations\/new/, { timeout: 10000 })
	await expect(page.locator('body')).not.toContainText('Internal Server Error')
})

// @e2e openspec/changes/bi-export-and-data-warehouse-sink/specs.md#REQ-BIE-011
test('export runs list page renders without error', async ({ page }) => {
	await page.goto('/apps/pipelinq/export/runs')
	await expect(page).toHaveURL(/export\/runs/, { timeout: 10000 })
	await expect(page.locator('body')).not.toContainText('Internal Server Error')
	await expect(page.locator('body')).not.toContainText('Uncaught Error')
})

// @e2e openspec/changes/bi-export-and-data-warehouse-sink/specs.md#REQ-BIE-011-01
test('export runs page main content area renders', async ({ page }) => {
	await page.goto('/apps/pipelinq/export/runs')
	await expect(page.locator('#app-content, .app-content, main').first()).toBeVisible({ timeout: 10000 })
})

/*
 * Backend-only scenarios excluded — covered by PHPUnit / integration tests:
 *
 * @e2e exclude REQ-BIE-001-02 — destination credential validation runs server-side, asserted in ExportDestinationServiceTest
 * @e2e exclude REQ-BIE-002-03 — cron parsing covered by CronExpressionHelperTest
 * @e2e exclude REQ-BIE-002-04 — filter expression validation server-side, asserted in ExportJobServiceTest
 * @e2e exclude REQ-BIE-002-05 — column allowlist persistence server-side
 * @e2e exclude REQ-BIE-003-01 — test-run produces a sample file via warehouse adapter (live env)
 * @e2e exclude REQ-BIE-003-02 — connector connectivity probe (live env)
 * @e2e exclude REQ-BIE-003-03 — schema reference validation server-side
 * @e2e exclude REQ-BIE-004-01 — cron triggers BackgroundJob (asserted in ExportSchedulerJobTest)
 * @e2e exclude REQ-BIE-004-02 — worker pickup latency (asserted in ExportWorkerJobTest)
 * @e2e exclude REQ-BIE-004-03 — distributed lock contract (asserted in ExportExecutionServiceTest)
 * @e2e exclude REQ-BIE-005-01 — full refresh row emission (asserted in ExportDataServiceTest)
 * @e2e exclude REQ-BIE-005-02 — row filter SQL composition (server-side)
 * @e2e exclude REQ-BIE-005-03 — column allowlist SELECT projection (server-side)
 * @e2e exclude REQ-BIE-006-01 — incremental watermark query (server-side)
 * @e2e exclude REQ-BIE-006-02 — watermark rollback on failure (server-side)
 * @e2e exclude REQ-BIE-006-03 — soft-delete marker emission (server-side)
 * @e2e exclude REQ-BIE-007-01 — CSV header row emission (asserted in ExportDataServiceTest)
 * @e2e exclude REQ-BIE-007-02 — Parquet embedded schema (live env / format adapter)
 * @e2e exclude REQ-BIE-007-03 — JSON-lines emission (asserted in ExportDataServiceTest)
 * @e2e exclude REQ-BIE-007 — multiple output formats matrix (covered by format adapters)
 * @e2e exclude REQ-BIE-008 — upload retry with exponential backoff (asserted in ExportUploadServiceTest)
 * @e2e exclude REQ-BIE-009 — schema evolution detection (asserted in SchemaEvolutionServiceTest)
 * @e2e exclude REQ-BIE-010 — audit record creation per run (asserted in ExportRunServiceTest)
 * @e2e exclude REQ-BIE-011-02 — run detail manifest with file checksums (server-rendered)
 * @e2e exclude REQ-BIE-011-03 — retry endpoint creates new pending run (asserted in ExportRunControllerTest)
 */
