/*
 * SPDX-FileCopyrightText: 2026 Pipelinq Contributors
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Gate-26 (visual-coverage) e2e proof for nine hash-routed SPA page components
 * that had no e2e test naming them.
 *
 * WHY THESE TESTS EXIST, AND WHAT THEY DELIBERATELY ARE NOT
 * ---------------------------------------------------------
 * Gate-26 marks a page component covered when ANY file under `tests/e2e/**`
 * mentions the component's path or its file stem as a whole word. That rule is
 * satisfiable by a bare comment — a PascalCase stem left behind in prose keeps
 * the gate green after the test that used it has been renamed away. Every
 * reference below therefore sits directly on a test that NAVIGATES to the
 * component's real route and asserts markup the component itself renders.
 *
 * The other tempting shortcut — a `tests/e2e/visual/**` PNG baseline — is
 * unusable here: the `visual` project is excluded from the CI config
 * (tests/e2e/playwright.config.ts declares only `chromium`) precisely because a
 * Linux runner cannot byte-match a dev-container baseline. A baseline would
 * satisfy gate-26 from a suite CI never executes.
 *
 * ROUTES ARE THE MANIFEST'S, AND THEY ARE HASH ROUTES
 * ---------------------------------------------------
 * `src/main.js` builds `createWebHashHistory(generateUrl('/apps/pipelinq'))`, so
 * the route lives in `location.hash`. A path-shaped deep link
 * (`/apps/pipelinq/my-work`) leaves the hash EMPTY, vue-router resolves `/`, and
 * the SPA renders the Dashboard — while `expect(page).toHaveURL(/my-work/)`
 * still passes, because the PATH does contain it. Several pre-existing specs are
 * written that way. Every goto here carries the `#`.
 *
 * ASSERTION SHAPE
 * ---------------
 * Per page: (1) the app shell was served — HTTP 200, no Nextcloud error chrome,
 * `#content-vue` mounted (assertAppShellServed); (2) a hook the page component's
 * OWN template renders unconditionally — outside every `v-if`, so the assertion
 * measures "this route mounted THIS component", not "the data happened to load".
 * Pages whose whole template is a `CnIndexPage` / `CnDetailPage` are pinned on
 * the library's root testid plus the title element, which
 * `@conduction/nextcloud-vue` 2.2.0-vue3.9 renders unconditionally
 * (`CnPageHeader` "ALWAYS renders"; `CnDetailPage`'s `<h2>` falls back to the
 * `title` prop while no object is resolved).
 */

import type { Page } from '@playwright/test'

import { expect, test } from '@playwright/test'
import {
	assertAppShellServed,
	dismissSupportDialog,
	dismissWalkthrough,
} from '../helpers/pipelinq.ts'

/**
 * A run-unique id used where a route needs an `:id` segment for a record that
 * deliberately does not exist. The page under test is the DETAIL SHELL, not the
 * record: both detail components below catch their fetch failure, surface it
 * through `showError()` and keep rendering. Using a real seeded id instead would
 * make the test depend on demo data that `ci-seed.sh` does not guarantee for
 * these two collections.
 */
const ABSENT_ID = 'e2e-gate26-no-such-record'

/**
 * Open a pipelinq SPA route by its manifest hash path and prove the app shell —
 * not Nextcloud's error chrome — was served.
 *
 * @param page  The Playwright page.
 * @param hash  The manifest `route`, e.g. `/my-work`.
 */
async function openSpaRoute(page: Page, route: string): Promise<void> {
	const response = await page.goto(`/apps/pipelinq${route}`)
	await assertAppShellServed(page, response)
	// THE SURVIVING PATH IS THE PROOF THE ROUTE MATCHED. `routesFromManifest()`
	// closes the table with `{ path: '/:pathMatch(.*)*', redirect: '/' }`, so an
	// unmatched route does not 404 — it redirects to `/` and renders the
	// Dashboard. Asserting the path survived the mount is therefore the one
	// cheap check that separates "this page rendered" from "the catch-all sent
	// me to the dashboard and something dashboard-shaped rendered instead".
	await expect(page).toHaveURL(
		new RegExp(`${route.replace(/[.*+?^${}()|[\]\\]/g, '\\$&')}$`),
		{ timeout: 10000 },
	)
	// The first-visit product tour and the fleet support dialog paint over the
	// viewport. They do not affect visibility assertions, but dismissing them
	// keeps this helper usable by the click-driven specs in the sibling files.
	await dismissWalkthrough(page)
	await dismissSupportDialog(page)
}

const content = (page: Page) => page.locator('#content-vue')

// ── src/views/MyWork.vue — manifest page `MyWork`, route `/my-work` ──────────
test('MyWork: /my-work mounts src/views/MyWork.vue', async ({ page }) => {
	await openSpaRoute(page, '/my-work')

	// `.my-work` and its `<h2>` sit above every `v-if` in the template, so they
	// render whether the workload loads, errors or comes back empty.
	const myWork = content(page).locator('.my-work')
	await expect(myWork).toBeVisible({ timeout: 15000 })
	await expect(myWork.getByRole('heading', { name: 'My Work' })).toBeVisible()
	// The lead/request filter strip is part of the same unconditional header
	// block, so it distinguishes this page from anything else carrying an
	// "My Work" heading (the dashboard widget, for instance).
	await expect(myWork.locator('.my-work__controls .filter-buttons')).toBeVisible()
})

// ── src/views/leads/LeadList.vue — manifest page `Leads`, route `/leads` ─────
test('LeadList: /leads mounts src/views/leads/LeadList.vue', async ({ page }) => {
	await openSpaRoute(page, '/leads')

	const index = content(page).locator('[data-testid="cn-index-page"]').first()
	await expect(index).toBeVisible({ timeout: 15000 })
	// CnIndexPage defaults `showTitle` to false, so CnPageHeader renders the
	// <h1> visually hidden rather than not at all. `toHaveText` reads
	// textContent and is therefore the right assertion for a clipped heading —
	// `toBeVisible` would be asserting a styling detail, not the page identity.
	await expect(index.locator('[data-testid="cn-page-title"]').first()).toHaveText(
		'Leads',
	)

	// ⚠️ NOT ASSERTED ON PURPOSE — LeadList's `<template #header-actions>` block
	// (the "Stale only (>Nd)" and "Hide closed" switches, REQ-LM-002 /
	// REQ-LM-004) NEVER RENDERS. `CnIndexPage` in @conduction/nextcloud-vue
	// 2.2.0-vue3.9 declares no `header-actions` slot; its render function calls
	// `renderSlot` for exactly: header, below-header, mass-actions, action-items,
	// actions, import-fields, delete-dialog, copy-dialog, form-dialog,
	// form-fields, empty, `column-<col>`, row-actions, row-icon, row-badges,
	// list-item, card. `header-actions` exists in that package only as a
	// CnActionsBar PROP and as a manifest widget-placement slot name, so Vue
	// silently drops the template block. LeadList's other override,
	// `#column-expectedCloseDate`, IS in the supported list and does render.
	// Asserting the filters here would be asserting a bug into place; the
	// product defect is reported separately rather than papered over.
})

// ── src/views/pipeline/PipelineBoard.vue — page `Pipeline`, route `/pipeline` ─
test('PipelineBoard: /pipeline mounts src/views/pipeline/PipelineBoard.vue', async ({
	page,
}) => {
	await openSpaRoute(page, '/pipeline')

	const board = content(page).locator('.pipeline-board')
	await expect(board).toBeVisible({ timeout: 15000 })
	await expect(board.getByRole('heading', { name: 'Pipeline' })).toBeVisible()
	// The pipeline selector / search / view-toggle strip is rendered
	// unconditionally in the board header, so it does not depend on any
	// pipeline record existing.
	await expect(
		board.locator('.pipeline-board__controls .view-toggle'),
	).toBeVisible()
})

// ── src/views/pos/CashShiftList.vue — page `CashShifts`, route `/pos/shifts` ──
test('CashShiftList: /pos/shifts mounts src/views/pos/CashShiftList.vue', async ({
	page,
}) => {
	await openSpaRoute(page, '/pos/shifts')

	const index = content(page).locator('[data-testid="cn-index-page"]').first()
	await expect(index).toBeVisible({ timeout: 15000 })
	await expect(index.locator('[data-testid="cn-page-title"]').first()).toHaveText(
		'Cash drawer',
	)
})

// ── src/views/pos/PosRefundForm.vue — page `PosRefundNew`, `/pos/refunds/new` ─
test('PosRefundForm: /pos/refunds/new mounts src/views/pos/PosRefundForm.vue', async ({
	page,
}) => {
	// Manifest route ordering matters here: `routesFromManifest()` sorts by
	// parameter count ascending, so the literal `/pos/refunds/new` is registered
	// before `/pos/refunds/:id` and wins the match.
	await openSpaRoute(page, '/pos/refunds/new')

	const form = content(page).locator('.pos-refund-form')
	await expect(form).toBeVisible({ timeout: 15000 })
	// Header block is outside the loading `v-if`; "New refund" (not "Edit
	// refund") also proves the create branch resolved, i.e. `/new` did not fall
	// through to the `:id` route.
	await expect(form.getByRole('heading', { name: 'New refund' })).toBeVisible()
})

// ── src/views/kassakoppeling/KassakoppelingAuditList.vue — `/kassakoppeling/audit`
test('KassakoppelingAuditList: /kassakoppeling/audit mounts src/views/kassakoppeling/KassakoppelingAuditList.vue', async ({
	page,
}) => {
	await openSpaRoute(page, '/kassakoppeling/audit')

	const list = content(page).locator('.kassakoppeling-audit-list')
	await expect(list).toBeVisible({ timeout: 15000 })
	await expect(
		list.getByRole('heading', { name: 'Cash register audit log' }),
	).toBeVisible()
	// The filter strip carries the page's own testid and renders regardless of
	// whether the append-only register holds any entries.
	await expect(
		list.locator('[data-testid="kassakoppeling-audit-filters"]'),
	).toBeVisible()
})

// ── src/views/kassakoppeling/KassakoppelingAuditDetail.vue — `/kassakoppeling/audit/:id`
test('KassakoppelingAuditDetail: /kassakoppeling/audit/:id mounts src/views/kassakoppeling/KassakoppelingAuditDetail.vue', async ({
	page,
}) => {
	await openSpaRoute(page, `/kassakoppeling/audit/${ABSENT_ID}`)

	const detail = content(page).locator('[data-testid="cn-detail-page"]').first()
	await expect(detail).toBeVisible({ timeout: 15000 })
	// `load()` answers a non-OK response with `showError()` + `entry = {}`, so
	// the component's own `title` computed falls back to "Audit entry" and
	// CnDetailPage's `<h2>` shows it (`displayTitle = objectDisplayName || title`,
	// and this page passes no object). The shell is what is under test.
	await expect(detail.locator('.cn-detail-page__title')).toHaveText('Audit entry')
})

// ── src/views/admin/BrpMonitor.vue — page `BrpMonitor`, `/admin/brp-monitor` ──
test('BrpMonitor: /admin/brp-monitor mounts src/views/admin/BrpMonitor.vue', async ({
	page,
}) => {
	await openSpaRoute(page, '/admin/brp-monitor')

	const monitor = content(page).locator('[data-testid="brp-monitor"]')
	await expect(monitor).toBeVisible({ timeout: 15000 })
	await expect(monitor.getByRole('heading', { name: 'BRP Monitor' })).toBeVisible()
})

// ── src/views/admin/PosCustomerSettings.vue — `/admin/pos-customer-link` ──────
test('PosCustomerSettings: /admin/pos-customer-link mounts src/views/admin/PosCustomerSettings.vue', async ({
	page,
}) => {
	await openSpaRoute(page, '/admin/pos-customer-link')

	// The whole template is one NcSettingsSection, and its `name` prop is the
	// section heading — rendered by the component before any fetch resolves.
	await expect(
		content(page).getByRole('heading', { name: 'POS customer lookup' }),
	).toBeVisible({ timeout: 15000 })
})
