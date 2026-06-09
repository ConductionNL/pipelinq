/*
 * SPDX-FileCopyrightText: 2026 Pipelinq Contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 *
 * Behavioral regression marker for the BI export jobs page (/export/jobs).
 * Complements the existing bi-export.spec.ts (which only asserts no hard 500)
 * with the deeper UI observation captured during the gate-19 explore pass.
 *
 * KNOWN BUG (confirmed 2026-06-09): the export-jobs index never registers its
 * object type in the cn-vue store and a render path reads `.id` off an
 * undefined value, so the page logs BOTH:
 *   TypeError: Cannot read properties of undefined (reading 'id')
 *   Error: Object type "exportJob" is not registered in the store.
 *   Call registerObjectType('exportJob', schemaId, registerId) first.
 * The page heading is missing and the jobs table never populates. The
 * data-surface assertion is a test.fixme until src/registry.js registers
 * `exportJob` and the undefined-`id` read is guarded.
 */
import { test, expect } from '@playwright/test'
import { openApp, navClick, assertNoHardError } from '../helpers/pipelinq'

// @e2e openspec/changes/bi-export-and-data-warehouse-sink/specs.md#REQ-BIE-002-ui-shell
test('BI export jobs: navigates from sidebar without a hard server error', async ({ page }) => {
	await openApp(page)
	await navClick(page, 'BI export', /\/export\/jobs/)

	await assertNoHardError(page)
	await expect(page.locator('[data-testid="cn-index-page"]').first()).toBeVisible()
})

// @e2e openspec/changes/bi-export-and-data-warehouse-sink/specs.md#REQ-BIE-002-ui-list
test.fixme('BI export jobs: heading and jobs table render', async ({ page }) => {
	// BUG: object type "exportJob" is not registered in the cn-vue store + a
	// render path reads `.id` off undefined. Re-enable once src/registry.js
	// registers `exportJob` and the undefined read is guarded.
	await openApp(page)
	await navClick(page, 'BI export', /\/export\/jobs/)
	await expect(page.locator('#content-vue').getByRole('heading').first()).toBeVisible()
	await expect(page.locator('#content-vue table, #content-vue .cn-data-table').first()).toBeVisible()
})
