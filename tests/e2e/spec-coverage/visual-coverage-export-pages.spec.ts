/*
 * SPDX-FileCopyrightText: 2026 Pipelinq Contributors
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Gate-26 (visual-coverage) e2e proof for the five BI-export page components
 * that had no e2e test naming them.
 *
 * WHY THE EXISTING bi-export.spec.ts DID NOT COVER THESE. It drives the same
 * feature but deep-links with PATH-shaped URLs — `/apps/pipelinq/export/runs` —
 * while `src/main.js` builds `createWebHashHistory(...)`. With an empty hash
 * vue-router resolves `/` and renders the Dashboard; the spec's
 * `toHaveURL(/export\/runs/)` still passes because the PATH contains the words.
 * So those tests assert against whatever the Dashboard renders, and they never
 * name a component either. Everything below carries the `#`.
 *
 * All five pages are `type: "custom"` manifest entries, so the route maps
 * straight onto the .vue file rather than onto the manifest renderer:
 *
 *   /export/destinations       ExportDestinationsView   ExportDestinations.vue
 *   /export/destinations/new   ExportDestinationFormView ExportDestinationForm.vue
 *   /export/jobs/new           ExportJobFormView        ExportJobForm.vue
 *   /export/runs               ExportRunsView           ExportRuns.vue
 *   /export/runs/:id           ExportRunDetailView      ExportRunDetail.vue
 */

import { test, expect, type Page } from '@playwright/test'

import {
	assertAppShellServed,
	dismissSupportDialog,
	dismissWalkthrough,
} from '../helpers/pipelinq'

/**
 * A run id that deliberately does not exist. `ExportRunDetail.load()` answers a
 * failed fetch with `showError()` and leaves `run = {}`, so the DETAIL SHELL —
 * which is the page component under test — still renders. Pinning the test to a
 * seeded run instead would make it depend on export history that `ci-seed.sh`
 * does not create.
 */
const ABSENT_RUN_ID = 'e2e-gate26-no-such-run'

/**
 * Open a pipelinq SPA route by its manifest hash path and prove both that the
 * app shell was served and that the route actually matched.
 *
 * @param page  The Playwright page.
 * @param hash  The manifest `route`, e.g. `/export/runs`.
 */
async function openSpaRoute(page: Page, hash: string): Promise<void> {
	const response = await page.goto(`/apps/pipelinq/#${hash}`)
	await assertAppShellServed(page, response)
	// `routesFromManifest()` ends the table with a catch-all that REDIRECTS to
	// `/`, so an unmatched route silently becomes the Dashboard. A surviving
	// hash is the evidence that this route matched.
	await expect(page).toHaveURL(
		new RegExp(`#${hash.replace(/[.*+?^${}()|[\]\\]/g, '\\$&')}$`),
		{ timeout: 10000 },
	)
	await dismissWalkthrough(page)
	await dismissSupportDialog(page)
}

const content = (page: Page) => page.locator('#content-vue')

/**
 * The CnIndexPage / CnDetailPage title element.
 *
 * CnIndexPage defaults `showTitle` to false, which makes CnPageHeader render the
 * `<h1>` VISUALLY HIDDEN rather than not at all ("CnPageHeader ALWAYS renders …
 * `visuallyHidden` clips everything but the `<h1>` out of the layout"), so the
 * title is asserted with `toHaveText` — which reads textContent — instead of
 * `toBeVisible`, which would be asserting a styling decision rather than page
 * identity. CnDetailPage's `<h2>` is `objectDisplayName || title`; these pages
 * pass no object, so it is the literal `title` prop.
 *
 * @param page The Playwright page.
 */
const indexTitle = (page: Page) =>
	content(page).locator('[data-testid="cn-index-page"] [data-testid="cn-page-title"]').first()

// ── src/views/export/ExportDestinations.vue — route `/export/destinations` ────
test('ExportDestinations: /export/destinations mounts src/views/export/ExportDestinations.vue', async ({ page }) => {
	await openSpaRoute(page, '/export/destinations')

	await expect(content(page).locator('[data-testid="cn-index-page"]').first())
		.toBeVisible({ timeout: 15000 })
	await expect(indexTitle(page)).toHaveText('Export destinations')
})

// ── src/views/export/ExportDestinationForm.vue — `/export/destinations/new` ───
test('ExportDestinationForm: /export/destinations/new mounts src/views/export/ExportDestinationForm.vue', async ({ page }) => {
	await openSpaRoute(page, '/export/destinations/new')

	const detail = content(page).locator('[data-testid="cn-detail-page"]').first()
	await expect(detail).toBeVisible({ timeout: 15000 })
	// "New destination" rather than "Edit destination" also proves the literal
	// `/new` route won over `/export/destinations/:id`: `routesFromManifest()`
	// sorts by parameter count ascending, so the parameterless path is
	// registered first, and `isEdit` is `!!destinationId`.
	await expect(detail.locator('.cn-detail-page__title')).toHaveText('New destination')
})

// ── src/views/export/ExportJobForm.vue — route `/export/jobs/new` ─────────────
test('ExportJobForm: /export/jobs/new mounts src/views/export/ExportJobForm.vue', async ({ page }) => {
	await openSpaRoute(page, '/export/jobs/new')

	const detail = content(page).locator('[data-testid="cn-detail-page"]').first()
	await expect(detail).toBeVisible({ timeout: 15000 })
	await expect(detail.locator('.cn-detail-page__title')).toHaveText('New export job')
})

// ── src/views/export/ExportRuns.vue — route `/export/runs` ────────────────────
test('ExportRuns: /export/runs mounts src/views/export/ExportRuns.vue', async ({ page }) => {
	await openSpaRoute(page, '/export/runs')

	await expect(content(page).locator('[data-testid="cn-index-page"]').first())
		.toBeVisible({ timeout: 15000 })
	await expect(indexTitle(page)).toHaveText('Export runs')
})

// ── src/views/export/ExportRunDetail.vue — route `/export/runs/:id` ───────────
test('ExportRunDetail: /export/runs/:id mounts src/views/export/ExportRunDetail.vue', async ({ page }) => {
	await openSpaRoute(page, `/export/runs/${ABSENT_RUN_ID}`)

	const detail = content(page).locator('[data-testid="cn-detail-page"]').first()
	await expect(detail).toBeVisible({ timeout: 15000 })
	// ExportRunDetail passes a STATIC title, so the header reads "Export run"
	// whether or not the run resolves — which is exactly why it is a safe pin
	// for "this route mounted this component".
	await expect(detail.locator('.cn-detail-page__title')).toHaveText('Export run')
})
