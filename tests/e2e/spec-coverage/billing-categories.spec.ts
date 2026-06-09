/*
 * SPDX-FileCopyrightText: 2026 Pipelinq Contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 *
 * Gate-19 behavioral e2e coverage for the Billing categories page
 * (/billing-categories).
 *
 * LIVE STATE (verified 2026-06-09 against the deployed bundle): the page mounts
 * its `cn-index-page` chrome and the primary "New category" CTA. The store.js
 * slug-fallback registration (commit a53bc8c5) does NOT yet make the
 * schema-driven data surface render — `billingCategory` is still reported as
 * not registered at fetch time, so the heading + table never populate. The data
 * surface assertion is kept as `test.fixme` until that is resolved.
 */
import { test, expect } from '@playwright/test'
import { openApp, navClick, assertNoHardError } from '../helpers/pipelinq'

// @e2e openspec/specs/pos-transaction-core/spec.md#billing-categories-page
test('Billing categories: navigates from sidebar and mounts the index chrome', async ({ page }) => {
	await openApp(page)
	await navClick(page, 'Billing categories', /\/billing-categories/)

	await assertNoHardError(page)
	await expect(page.locator('[data-testid="cn-index-page"]').first()).toBeVisible()
})

// @e2e openspec/specs/pos-transaction-core/spec.md#billing-categories-create
test('Billing categories: primary "New category" action is present', async ({ page }) => {
	await openApp(page)
	await navClick(page, 'Billing categories', /\/billing-categories/)

	await expect(page.locator('#content-vue').getByRole('button', { name: 'New category' })).toBeVisible()
})

// @e2e openspec/specs/pos-transaction-core/spec.md#billing-categories-list
test.fixme('Billing categories: list data surface renders', async ({ page }) => {
	// KNOWN GAP: the "billingCategory" object type is still not registered at
	// fetch time on the deployed bundle, so the collection fetch throws and the
	// index renders only its empty/chrome state (no heading, no data table).
	// Unskip once store.js registration makes the schema-driven surface load.
	await openApp(page)
	await navClick(page, 'Billing categories', /\/billing-categories/)
	await expect(page.locator('#content-vue').getByRole('heading').first()).toBeVisible()
	await expect(page.locator('#content-vue table, #content-vue .cn-data-table').first()).toBeVisible()
})

/*
 * Backend / data-dependent scenarios excluded — covered by PHPUnit / Newman:
 * @e2e exclude billing-category-applied-to-transaction — server-side; covered by PHPUnit
 * @e2e exclude billing-category-detail — requires seeded record + store registration
 */
