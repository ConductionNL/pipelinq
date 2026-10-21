/*
 * SPDX-FileCopyrightText: 2026 Pipelinq Contributors
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Gate-26 (visual-coverage) e2e proof for the five BI-export page components
 * that had no e2e test naming them.
 *
 * WHY THE EXISTING bi-export.spec.ts DID NOT COVER THESE. It drives the same
 * feature but, while the app was hash-routed, deep-linked with PATH-shaped URLs
 * — `/apps/pipelinq/export/runs` — which left the hash empty. vue-router
 * resolved `/` and rendered the Dashboard, yet the spec's
 * `toHaveURL(/export\/runs/)` still passed because the PATH contains the words,
 * so those tests asserted against whatever the Dashboard rendered and never
 * named a component. `src/main.js` now builds `createWebHistory(routerBase())`,
 * so a path-shaped deep link resolves to the page it names.
 *
 * The lists are `type: "index"` and the run and destination `type: "detail"`
 * manifest pages; the job form is a `type: "custom"` entry over its .vue file:
 *
 *   /export/destinations       index page (exportDestination)
 *   /export/destinations/:id   detail page (exportDestination); new ones through the list's Add form
 *   /export/jobs/new           ExportJobFormView        ExportJobForm.vue
 *   /export/runs               index page (exportRun)
 *   /export/runs/:id           detail page (exportRun)
 */

import type { Page } from '@playwright/test'

import { expect, test } from '@playwright/test'
import {
	assertAppShellServed,
	dismissSupportDialog,
	dismissWalkthrough,
} from '../helpers/pipelinq.ts'

/** A run id that deliberately does not exist, so the test needs no seeded export history. */
const ABSENT_RUN_ID = 'e2e-gate26-no-such-run'

/**
 * Open a pipelinq SPA route by its manifest hash path and prove both that the
 * app shell was served and that the route actually matched.
 *
 * @param page  The Playwright page.
 * @param hash  The manifest `route`, e.g. `/export/runs`.
 */
async function openSpaRoute(page: Page, hash: string): Promise<void> {
	const response = await page.goto(`/apps/pipelinq${hash}`)
	await assertAppShellServed(page, response)
	// `routesFromManifest()` ends the table with a catch-all that REDIRECTS to
	// `/`, so an unmatched route silently becomes the Dashboard. A surviving
	// path is the evidence that this route matched.
	//
	// ANCHORED AT THE END, which is the whole point. The header above records
	// why: `toHaveURL(/export\/runs/)` passes on the Dashboard too, because the
	// path it was deep-linked with still contains those words. `$` is what the
	// Dashboard cannot satisfy — it lands on `/apps/pipelinq/`, which does not
	// end with the route. This assertion used to anchor on a leading `#`, which
	// stopped existing when the shell moved to createWebHistory.
	await expect(page).toHaveURL(
		new RegExp(`${hash.replace(/[.*+?^${}()|[\]\\]/g, '\\$&')}$`),
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
function indexTitle(page: Page) {
	return content(page)
		.locator('[data-testid="cn-index-page"] [data-testid="cn-page-title"]')
		.first()
}

// ── index page `ExportDestinations` — route `/export/destinations` ────
test('ExportDestinations: /export/destinations mounts the destination index page', async ({
	page,
}) => {
	await openSpaRoute(page, '/export/destinations')

	await expect(
		content(page).locator('[data-testid="cn-index-page"]').first(),
	).toBeVisible({ timeout: 15000 })
	await expect(indexTitle(page)).toHaveText('Export destinations')
})

// ── New destination: the destination list's create form ───
test('ExportDestinations: New destination opens the destination form', async ({
	page,
}) => {
	await openSpaRoute(page, '/export/destinations')
	await content(page).getByRole('button', { name: 'New destination' }).first().click()

	const dialog = page.locator('.modal-container, [role="dialog"]').first()
	await expect(dialog).toBeVisible({ timeout: 15000 })
	await expect(dialog.getByText('OpenConnector source ID').first()).toBeVisible()
})

// ── src/views/export/ExportJobForm.vue — route `/export/jobs/new` ─────────────
test('ExportJobForm: /export/jobs/new mounts src/views/export/ExportJobForm.vue', async ({
	page,
}) => {
	await openSpaRoute(page, '/export/jobs/new')

	const detail = content(page).locator('[data-testid="cn-detail-page"]').first()
	await expect(detail).toBeVisible({ timeout: 15000 })
	await expect(detail.locator('.cn-detail-page__title')).toHaveText(
		'New export job',
	)
})

// ── index page `ExportRuns` — route `/export/runs` ────────────────────
test('ExportRuns: /export/runs mounts the run index page', async ({
	page,
}) => {
	await openSpaRoute(page, '/export/runs')

	await expect(
		content(page).locator('[data-testid="cn-index-page"]').first(),
	).toBeVisible({ timeout: 15000 })
	await expect(indexTitle(page)).toHaveText('Export runs')
})

// ── detail page `ExportRunDetail` — route `/export/runs/:id` ───────────
test('ExportRunDetail: /export/runs/:id mounts the run detail page', async ({
	page,
}) => {
	await openSpaRoute(page, `/export/runs/${ABSENT_RUN_ID}`)

	// A run that does not exist still mounts the detail page shell; its
	// widgets (Summary, File manifest, Schema snapshots) need a seeded run,
	// which ci-seed.sh does not create.
	await expect(
		content(page).locator('[data-testid="cn-detail-page"]').first(),
	).toBeVisible({ timeout: 15000 })
})
