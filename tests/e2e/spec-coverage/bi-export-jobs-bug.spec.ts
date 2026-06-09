/*
 * SPDX-FileCopyrightText: 2026 Pipelinq Contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
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
import { test, expect } from '@playwright/test'
import { openApp, navClick, assertNoHardError, trackPipelinqErrors } from '../helpers/pipelinq'

// @e2e openspec/changes/bi-export-and-data-warehouse-sink/specs.md#REQ-BIE-002-ui-shell
test('BI export jobs: navigates from sidebar and mounts the index chrome', async ({ page }) => {
	await openApp(page)
	await navClick(page, 'BI export', /\/export\/jobs/)

	await assertNoHardError(page)
	await expect(page.locator('[data-testid="cn-index-page"]').first()).toBeVisible()
})

// @e2e openspec/changes/bi-export-and-data-warehouse-sink/specs.md#REQ-BIE-002-ui-list
test('BI export jobs: jobs surface renders without a registration error', async ({ page }) => {
	// store.js slug-fallback registration (commit a53bc8c5) registers "exportJob"
	// against the canonical OR schema slug when the app-config numeric id is empty,
	// so the collection fetch resolves: the index renders its schema-driven jobs
	// surface (a populated jobs table, or — register 16 holds the schema but no
	// seeded export jobs — the genuine "No items found" empty state) rather than
	// the broken "not registered" failure state.
	const errors = trackPipelinqErrors(page)
	await openApp(page)
	await navClick(page, 'BI export', /\/export\/jobs/)
	const content = page.locator('#content-vue')
	await expect(
		content.locator('table, .cn-data-table').first()
			.or(content.getByText(/No items found/i).first()),
	).toBeVisible()
	expect(errors().filter((e) => /exportJob.*not registered/i.test(e))).toEqual([])
})
