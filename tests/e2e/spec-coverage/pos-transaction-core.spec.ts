/*
 * SPDX-FileCopyrightText: 2026 Pipelinq Contributors
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Gate-19 e2e coverage for openspec/specs/pos-transaction-core/spec.md
 * UI-observable scenarios: the POS transaction list (Kassabon) at /pos —
 * the manifest CnIndexPage list surface, its empty state, and the
 * new-transaction (Add) entry point reachable from the global Kassabon nav.
 *
 * The POS list renders through @conduction/nextcloud-vue's CnIndexPage
 * (see src/views/pos/PosTransactionList.vue), which mounts a
 * `.cn-index-page` host with a CnActionsBar (the primary "Add" CTA), a
 * data table / empty state body, and the page <main> labelled "Kassabon".
 * It is mounted by the CnAppRoot manifest shell inside `#content-vue`.
 *
 * Filter-by-status and search-by-reference are excluded in the spec: this
 * list does not configure CnIndexPage's `sidebar` prop, so no status-filter
 * or search control is rendered, and both scenarios are data-dependent
 * (GIVEN seeded transactions). They are covered by PHPUnit / Newman.
 */

import { test, expect } from '@playwright/test'
import { openApp, navClick, clickHeaderAction } from '../helpers/pipelinq'

// @e2e openspec/specs/pos-transaction-core/spec.md#display-transaction-list-with-key-columns
test('POS transaction list (Kassabon) page renders the real list shell', async ({
	page,
}) => {
	await page.goto('/apps/pipelinq/pos')
	await expect(page).toHaveURL(/pos/, { timeout: 10000 })
	await expect(page.locator('body')).not.toContainText('Internal Server Error')
	// The real CnIndexPage list surface (not just the shell mount) — its host
	// element and the Kassabon page <main> region must both render.
	const indexPage = page.locator('[data-testid="cn-index-page"]').first()
	await expect(indexPage).toBeVisible({ timeout: 10000 })
	// The list mounts inside the app's <main> region (the real content area,
	// not the capability-guard screen).
	await expect(page.getByRole('main').first()).toBeVisible()
	// The actions bar (toolbar hosting the Add CTA + view toggle) is part of
	// the real list, not the capability-guard screen.
	await expect(
		page.locator('[data-testid="cn-actions-bar"]').first(),
	).toBeVisible()
})

// @e2e openspec/specs/pos-transaction-core/spec.md#empty-state
test('POS transaction list shows the real empty state (or populated rows) without error', async ({
	page,
}) => {
	await page.goto('/apps/pipelinq/pos')
	await expect(page.locator('body')).not.toContainText('Internal Server Error', {
		timeout: 10000,
	})
	await expect(page.locator('[data-testid="cn-index-page"]').first()).toBeVisible({
		timeout: 10000,
	})
	// Either the empty-state placeholder (bare env: no transactions) or a
	// populated data table — both are valid real-UI outcomes for this surface.
	const emptyState = page.locator('.cn-index-page__empty')
	const dataTable = page.locator('.cn-data-table, table')
	await expect(emptyState.or(dataTable).first()).toBeVisible({ timeout: 10000 })
})

// @e2e openspec/specs/pos-transaction-core/spec.md#create-a-new-draft-transaction
test('Kassabon (POS) page exposes the new-transaction entry point and nav', async ({
	page,
}) => {
	await openApp(page)
	// Navigate via the Kassabon nav entry rather than a bare deep-link (more
	// robust than goto, which can reset the manifest router to Dashboard).
	await navClick(page, 'Kassabon', /#\/pos/)
	await expect(page.locator('body')).not.toContainText('Internal Server Error')

	// CORRECTED 2026-08-06. This asserted `[data-testid="cn-cta-primary"]`.
	// CnActionsBar emits that element ONLY when `showAdd` is true, and the
	// PosTransactions page sets `showAdd: false` in src/manifest.json — it
	// declares the create entry point as a `headerActions[]` entry labelled
	// "Nieuwe transactie" instead, and CnActionsBar renders manifest header
	// actions inside the overflow "Actions" menu (verified in
	// @conduction/nextcloud-vue 2.2.0-vue3.3 dist/). So the CTA was absent by
	// design and this assertion could not pass on a correct build.
	//
	// Clicking through is what proves the entry point WORKS rather than merely
	// exists: the handler is `navigate` → route PosTransactionNew.
	await clickHeaderAction(page, /Nieuwe transactie/i)
	await expect(page).toHaveURL(/pos\/new/, { timeout: 10000 })
})

/*
 * Scenarios with no rendered UI control on this list (no CnIndexPage sidebar
 * configured) and/or data-dependent — excluded, covered by PHPUnit / Newman:
 * @e2e exclude filter-by-status — list configures no CnIndexPage sidebar so no
 *      status-filter control renders; scenario is data-dependent (seeded
 *      transactions per status); covered by PHPUnit/Newman.
 * @e2e exclude search-by-reference — list configures no CnIndexPage sidebar so
 *      no search control renders; scenario is data-dependent (seeded
 *      references); covered by PHPUnit/Newman.
 */
