/*
 * SPDX-FileCopyrightText: 2026 Pipelinq Contributors
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Gate-19 behavioral e2e coverage for the Billing categories page
 * (/billing-categories).
 *
 * LIVE STATE (verified 2026-06-09 against the deployed bundle): the page mounts
 * its `cn-index-page` chrome and the primary "New category" CTA. The store.js
 * slug-fallback registration (commit a53bc8c5) registers `billingCategory`
 * against the canonical OR schema slug when the app-config numeric id is empty
 * (register=16, empty *_schema), so the schema-driven data surface now loads.
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
test('Billing categories: list data surface renders', async ({ page }) => {
	// store.js slug-fallback registration (commit a53bc8c5) registers
	// "billingCategory" against the canonical OR schema slug when the app-config
	// numeric id is empty, so the collection fetch resolves: the index renders its
	// schema-driven data surface (the Cards/Table view toggle + a populated table,
	// or — register 16 holds the schema but no seeded categories — the genuine
	// "No items found" empty state) rather than the broken "not registered" state.
	await openApp(page)
	await navClick(page, 'Billing categories', /\/billing-categories/)
	const content = page.locator('#content-vue')
	await expect(content.getByRole('radio', { name: 'Table' })).toBeVisible()
	await expect(
		content.locator('table, .cn-data-table').first()
			.or(content.getByText(/No items found/i).first()),
	).toBeVisible()
})

/*
 * Backend / data-dependent scenarios excluded — covered by PHPUnit / Newman:
 * @e2e exclude billing-category-applied-to-transaction — server-side; covered by PHPUnit
 * @e2e exclude billing-category-detail — requires seeded record + store registration
 */
