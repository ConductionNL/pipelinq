import type { Page } from '@playwright/test'

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
import { expect, test } from '@playwright/test'
import {
	assertNoHardError,
	dismissSupportDialog,
	dismissWalkthrough,
	trackPipelinqErrors,
} from '../helpers/pipelinq.ts'

// The IA restructure ("move StUF/BI-export to Settings → Integrations") relocated
// the "BI export" entry into the settings section, so it is no longer a top-level
// sidebar link `navClick` can resolve. Deep-link the page instead, exactly as
// bi-export.spec.ts does.
//
// ONE NAVIGATION, NOT THREE. This helper used to call `openApp()` (a full load
// of the dashboard, only to leave it immediately), then deep-link, then
// `reload()`. The shell routes on history, so the deep link is already a fresh
// document load of the jobs route: the dashboard visit and the reload re-fetched
// the whole app for nothing.
//
// That waste is what failed run 34507105326. The traces of all three attempts
// show the same shape, with the entire 60s test budget spent before the first
// assertion ran:
//
//   goto /apps/pipelinq/          22.6s   (19.1s, 21.2s on the other attempts)
//   expect #app-navigation-vue     5.1s
//   goto /apps/pipelinq/export/jobs 15.4s
//   reload()                      13.5s
//   -> 59.6s elapsed, deadline hit
//
// The assertions themselves were never the problem: the failure screenshot shows
// the index rendered, with "Add Export Job" and its "No items found" empty state.
// A page load costs 13-23s here because CI runs 6 workers against one `php -S`,
// so a spec gets roughly two loads of headroom, not four.
//
// The navigation carries an explicit timeout so that a load which does blow the
// budget reports itself ("page.goto: Timeout ... exceeded" naming the URL)
// instead of surfacing as a bare test timeout that names nothing, which is what
// made this failure opaque in the first place.
async function gotoExportJobs(page: Page) {
	await page.goto('/apps/pipelinq/export/jobs', { timeout: 30000 })
	await page
		.locator('#content-vue')
		.waitFor({ state: 'visible', timeout: 15000 })
		.catch(() => {})
	// Both app-chrome overlays can paint over the content on a first visit; they
	// are cheap to check and were previously dismissed by `openApp()`.
	await dismissWalkthrough(page)
	await dismissSupportDialog(page)
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
