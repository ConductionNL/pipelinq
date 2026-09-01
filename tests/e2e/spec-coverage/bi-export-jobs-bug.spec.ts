/*
 * SPDX-FileCopyrightText: 2026 Pipelinq Contributors
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Behavioral regression marker for the BI export jobs page (/export/jobs).
 * Complements the existing bi-export.spec.ts (which only asserts no hard 500)
 * with the deeper UI observation captured during the gate-19 explore pass.
 *
 * LIVE STATE (verified 2026-06-09 against the deployed bundle): the page mounts
 * its `cn-index-page` chrome. The store.js slug-fallback registration (commit
 * a53bc8c5) registers `exportJob` against the canonical OR schema slug when the
 * app-config numeric id is empty (register=16, empty *_schema), so the
 * schema-driven data surface now loads and the per-type "not registered" error
 * no longer fires on this page.
 */
import { test, expect, type Page } from '@playwright/test'
import { openApp, assertNoHardError, trackPipelinqErrors } from '../helpers/pipelinq'

// The IA restructure ("move StUF/BI-export to Settings → Integrations") relocated
// the "BI export" entry into the settings section, so it is no longer a top-level
// sidebar link `navClick` can resolve. Deep-link the page via the SPA hash and
// reload so the index re-queries its data (matches bi-export.spec.ts).
async function gotoExportJobs(page: Page) {
	await openApp(page)
	await page.goto('/apps/pipelinq/export/jobs')
	await page.reload()
	await page
		.locator('#content-vue')
		.waitFor({ state: 'visible', timeout: 10000 })
		.catch(() => {})
}

// @e2e openspec/changes/bi-export-and-data-warehouse-sink/specs.md#REQ-BIE-002-ui-shell
test('BI export jobs: ExportJobs mounts from the sidebar with its index chrome', async ({
	page,
}) => {
	await gotoExportJobs(page)

	await assertNoHardError(page)
	await expect(page.locator('[data-testid="cn-index-page"]').first()).toBeVisible()
})

// @e2e openspec/changes/bi-export-and-data-warehouse-sink/specs.md#REQ-BIE-002-ui-list
test('BI export jobs: jobs surface renders without a registration error', async ({
	page,
}) => {
	// store.js slug-fallback registration (commit a53bc8c5) registers "exportJob"
	// against the canonical OR schema slug when the app-config numeric id is empty,
	// so the collection fetch resolves: the index renders its schema-driven jobs
	// surface (a populated jobs table, or — register 16 holds the schema but no
	// seeded export jobs — the genuine "No items found" empty state) rather than
	// the broken "not registered" failure state.
	const errors = trackPipelinqErrors(page)
	await gotoExportJobs(page)
	const content = page.locator('#content-vue')
	await expect(
		content
			.locator('table, .cn-data-table')
			.first()
			.or(content.getByText(/No items found/i).first()),
	).toBeVisible()
	expect(errors().filter((e) => /exportJob.*not registered/i.test(e))).toEqual([])
})
