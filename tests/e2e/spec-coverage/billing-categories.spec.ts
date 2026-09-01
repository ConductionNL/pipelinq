/*
 * SPDX-FileCopyrightText: 2026 Pipelinq Contributors
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Gate-19 behavioral e2e coverage for the billing-category surface.
 *
 * RETARGETED 2026-08-06 — there is no `/billing-categories` page and no
 * "Billing categories" sidebar entry. src/menu-layout.json records the decision
 * verbatim:
 *
 *   "'Reports & Compliance' (AnalyticsGroup) and its sole child 'Billing
 *    categories' are dropped … The billingCategory SCHEMA and its objects stay
 *    (ShillinqWipService and the 'Hours by billing category' widget on the
 *    Operational dashboard still read them); only the nav entry and its three
 *    pages go."
 *
 * So the browser-observable contract that survives is the Operational-dashboard
 * widget. The previous spec waited 10s for the retired sidebar entry and failed
 * all three tests inside the navigation helper, blaming the helper for an IA
 * decision that had already shipped.
 */
import { test, expect } from '@playwright/test'
import { openApp, assertNoHardError } from '../helpers/pipelinq'

// @e2e openspec/specs/pos-transaction-core/spec.md#billing-categories-page
test('Billing categories: the widget renders on the Operational dashboard', async ({
	page,
}) => {
	await openApp(page)
	await page.goto('/apps/pipelinq/operational')
	await page.reload()

	const content = page.locator('#content-vue')
	await expect(content.getByText('Hours by billing category').first()).toBeVisible(
		{ timeout: 15000 },
	)
	await assertNoHardError(page)
})

// @e2e openspec/specs/pos-transaction-core/spec.md#billing-categories-create
test('Billing categories: the retired nav entry is gone from the sidebar', async ({
	page,
}) => {
	await openApp(page)

	// nav-ia-cleanup dropped both the entry and the group that carried it. This
	// asserts the decision, so re-introducing a duplicate navigation surface for
	// the same schema fails here instead of silently returning.
	await expect(
		page
			.locator('#app-navigation-vue')
			.getByText('Billing categories', { exact: true }),
	).toHaveCount(0)
	await expect(
		page
			.locator('#app-navigation-vue')
			.getByText('Reports & Compliance', { exact: true }),
	).toHaveCount(0)
})

// @e2e openspec/specs/pos-transaction-core/spec.md#billing-categories-list
test('Billing categories: the widget sits alongside the other operational widgets', async ({
	page,
}) => {
	await openApp(page)
	await page.goto('/apps/pipelinq/operational')
	await page.reload()

	const content = page.locator('#content-vue')
	// The widget is part of the operational grid, not a page of its own — assert
	// it renders together with its neighbours so a dashboard that dropped the
	// whole grid cannot pass this by rendering nothing.
	await expect(content.getByText('Hours by billing category').first()).toBeVisible(
		{ timeout: 15000 },
	)
	await expect(content.getByText('Client Overview').first()).toBeVisible()
	await expect(content.getByText('Requests by Status').first()).toBeVisible()
})

/*
 * Backend / data-dependent scenarios excluded — covered by PHPUnit / Newman:
 * @e2e exclude billing-category-applied-to-transaction — server-side; covered by PHPUnit
 * @e2e exclude billing-category-detail — the detail page was retired with the index (nav-ia-cleanup)
 */
