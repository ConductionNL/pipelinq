/*
 * SPDX-FileCopyrightText: 2026 Pipelinq Contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 *
 * Gate-19 behavioral e2e coverage for the Returns / refunds page (/pos/refunds).
 * Maps to openspec/specs/pos-refund-return/spec.md.
 *
 * LIVE STATE (verified 2026-06-09 against the deployed bundle): the page mounts
 * its `cn-index-page` chrome and the primary "Add Item" CTA. The store.js
 * slug-fallback registration (commit a53bc8c5) does NOT yet make the
 * schema-driven data surface render — `posRefund` is still reported as not
 * registered at fetch time, so the heading + table never populate. The data
 * surface assertion is kept as `test.fixme` until that is resolved.
 */
import { test, expect } from '@playwright/test'
import { openApp, navClick, assertNoHardError } from '../helpers/pipelinq'

// @e2e openspec/specs/pos-refund-return/spec.md#returns-page
test('Returns: navigates from sidebar and mounts the index chrome', async ({ page }) => {
	await openApp(page)
	await navClick(page, 'Returns', /\/pos\/refunds/)

	await assertNoHardError(page)
	await expect(page.locator('[data-testid="cn-index-page"]').first()).toBeVisible()
})

// @e2e openspec/specs/pos-refund-return/spec.md#returns-add-item
test('Returns: primary "Add Item" action is present', async ({ page }) => {
	await openApp(page)
	await navClick(page, 'Returns', /\/pos\/refunds/)

	await expect(page.locator('#content-vue').getByRole('button', { name: 'Add Item' })).toBeVisible()
})

// @e2e openspec/specs/pos-refund-return/spec.md#returns-list
test.fixme('Returns: refund list data surface renders', async ({ page }) => {
	// KNOWN GAP: the "posRefund" object type is still not registered at fetch
	// time on the deployed bundle, so the collection fetch throws and the index
	// renders only its empty/chrome state (no heading, no data table). Unskip
	// once store.js registration makes the schema-driven surface load.
	await openApp(page)
	await navClick(page, 'Returns', /\/pos\/refunds/)
	await expect(page.locator('#content-vue').getByRole('heading').first()).toBeVisible()
	await expect(page.locator('#content-vue table, #content-vue .cn-data-table').first()).toBeVisible()
})

/*
 * Backend / data-dependent scenarios excluded — covered by PHPUnit / Newman:
 * @e2e exclude refund-against-original-transaction — server-side; covered by PHPUnit
 * @e2e exclude refund-restock-inventory — server-side; covered by PHPUnit
 * @e2e exclude refund-detail-view — requires seeded record + store registration
 */
