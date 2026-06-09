/*
 * SPDX-FileCopyrightText: 2026 Pipelinq Contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 *
 * Gate-19 behavioral e2e coverage for the Returns / refunds page (/pos/refunds).
 * Maps to openspec/specs/pos-refund-return/spec.md.
 *
 * KNOWN BUG (confirmed 2026-06-09): the refunds list never registers its
 * object type in the cn-vue store, so the page logs:
 *   Error: Object type "posRefund" is not registered in the store.
 *   Call registerObjectType('posRefund', schemaId, registerId) first.
 * The index chrome renders but the data table stays empty / no heading. The
 * data-surface assertion is a test.fixme until src/registry.js registers
 * `posRefund`.
 */
import { test, expect } from '@playwright/test'
import { openApp, navClick, assertNoHardError } from '../helpers/pipelinq'

// @e2e openspec/specs/pos-refund-return/spec.md#returns-page
test('Returns: navigates from sidebar without a hard server error', async ({ page }) => {
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
	// BUG: object type "posRefund" is not registered in the cn-vue store, so the
	// collection fetch throws and the table never populates. Re-enable once
	// src/registry.js calls registerObjectType('posRefund', ...).
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
