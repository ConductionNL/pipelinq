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
 * a53bc8c5) does NOT yet make the schema-driven data surface render — `exportJob`
 * is still reported as not registered at fetch time, so the heading + jobs table
 * never populate (the page falls back to its empty state). The data surface
 * assertion is kept as `test.fixme` until that is resolved.
 */
import { test, expect } from '@playwright/test'
import { openApp, navClick, assertNoHardError } from '../helpers/pipelinq'

// @e2e openspec/changes/bi-export-and-data-warehouse-sink/specs.md#REQ-BIE-002-ui-shell
test('BI export jobs: navigates from sidebar and mounts the index chrome', async ({ page }) => {
	await openApp(page)
	await navClick(page, 'BI export', /\/export\/jobs/)

	await assertNoHardError(page)
	await expect(page.locator('[data-testid="cn-index-page"]').first()).toBeVisible()
})

// @e2e openspec/changes/bi-export-and-data-warehouse-sink/specs.md#REQ-BIE-002-ui-list
test.fixme('BI export jobs: heading and jobs table render', async ({ page }) => {
	// KNOWN GAP: the "exportJob" object type is still not registered at fetch
	// time on the deployed bundle, so the collection fetch throws and the index
	// renders only its empty/chrome state (no heading, no jobs table). Unskip
	// once store.js registration makes the schema-driven surface load.
	await openApp(page)
	await navClick(page, 'BI export', /\/export\/jobs/)
	await expect(page.locator('#content-vue').getByRole('heading').first()).toBeVisible()
	await expect(page.locator('#content-vue table, #content-vue .cn-data-table').first()).toBeVisible()
})
