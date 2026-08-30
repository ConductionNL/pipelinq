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
	content(page)
		.locator('[data-testid="cn-index-page"] [data-testid="cn-page-title"]')
		.first()

// ── src/views/export/ExportDestinations.vue — route `/export/destinations` ────
test('ExportDestinations: /export/destinations mounts src/views/export/ExportDestinations.vue', async ({
	page,
}) => {
	await openSpaRoute(page, '/export/destinations')

	await expect(
		content(page).locator('[data-testid="cn-index-page"]').first(),
	).toBeVisible({ timeout: 15000 })
	await expect(indexTitle(page)).toHaveText('Export destinations')
})

// ── src/views/export/ExportDestinationForm.vue — `/export/destinations/new` ───
test('ExportDestinationForm: /export/destinations/new mounts src/views/export/ExportDestinationForm.vue', async ({
	page,
}) => {
	await openSpaRoute(page, '/export/destinations/new')

	const detail = content(page).locator('[data-testid="cn-detail-page"]').first()
	await expect(detail).toBeVisible({ timeout: 15000 })
	// ⚠️ THIS HEADING'S SOURCE HAS CHANGED — read before editing the literal.
	//
	// This file used to record the opposite rule: that a manifest page `title`
	// reaches CnDetailPage as a fallthrough attribute on a single-root
	// component and beats the component's own `:title`. Run 33253937930
	// measured the reverse — `33 × locator resolved to <h2
	// class="cn-detail-page__title">New destination</h2>`, i.e. the
	// COMPONENT's binding is what renders — so `src/manifest.json` (page
	// `ExportDestinationNew`) is no longer the string the user sees here.
	//
	// Nothing else in the suite can see that flip: this was the only page
	// whose manifest title and component literal disagreed. The
	// `/kassakoppeling/audit/:id` case looks like a counter-example but is
	// not — its own comment says it asserts the COMPONENT's fallback, which
	// merely happens to equal the manifest title.
	//
	// The component now says 'New export destination' too, so both layers
	// agree and the heading is right whichever one wins. That does mean this
	// assertion no longer discriminates between them: if you need to know
	// which layer renders, make them disagree deliberately rather than
	// reading this test's result as an answer.
	await expect(detail.locator('.cn-detail-page__title')).toHaveText(
		'New export destination',
	)
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

// ── src/views/export/ExportRuns.vue — route `/export/runs` ────────────────────
test('ExportRuns: /export/runs mounts src/views/export/ExportRuns.vue', async ({
	page,
}) => {
	await openSpaRoute(page, '/export/runs')

	await expect(
		content(page).locator('[data-testid="cn-index-page"]').first(),
	).toBeVisible({ timeout: 15000 })
	await expect(indexTitle(page)).toHaveText('Export runs')
})

// ── src/views/export/ExportRunDetail.vue — route `/export/runs/:id` ───────────
test('ExportRunDetail: /export/runs/:id mounts src/views/export/ExportRunDetail.vue', async ({
	page,
}) => {
	await openSpaRoute(page, `/export/runs/${ABSENT_RUN_ID}`)

	const detail = content(page).locator('[data-testid="cn-detail-page"]').first()
	await expect(detail).toBeVisible({ timeout: 15000 })

	// ⚠️ THIS PAGE RENDERS NO TITLE AT ALL, so it cannot be pinned on one.
	// The first version of this file asserted `.cn-detail-page__title` here and
	// CI answered `element(s) not found` — NOT a wrong literal, an absent
	// element. Cause: ExportRunDetail is the only one of the four CnDetailPage
	// consumers in this app that overrides `<template #header>` (the other three
	// use `#actions`), and CnDetailPage's `header` slot DEFAULT CONTENT is the
	// whole left header block — icon, type eyebrow and the
	// `<h2 class="cn-detail-page__title">`. Supplying `#header` replaces all of
	// it, so the status badge and Retry button this page puts there come at the
	// cost of the page heading. The captured DOM confirms it: `<main>` goes
	// straight from the optional-dependency banners to `heading "Summary"
	// [level=3]`, with no h1 or h2 anywhere — an unlabelled `<main>` landmark
	// (WCAG 1.3.1 / 2.4.6). Reported as a product defect rather than asserted
	// into place, and rather than papered over by relaxing the matcher.
	//
	// Pinned instead on the two CnDetailCards this component always renders —
	// both read verbatim out of the failing run's own ARIA snapshot, so they are
	// observed rather than guessed, and both are unique to this page.
	await expect(
		detail.getByRole('heading', { name: 'Summary', level: 3 }),
	).toBeVisible({ timeout: 15000 })
	await expect(
		detail.getByRole('heading', { name: 'File manifest', level: 3 }),
	).toBeVisible()
})
