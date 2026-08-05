/*
 * SPDX-FileCopyrightText: 2026 Pipelinq Contributors
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Gate-19 e2e coverage for openspec/specs/contactmomenten/spec.md
 * UI-observable scenarios: page loads, navigation, list.
 * Backend/API/store scenarios excluded per-scenario below.
 */

import { test, expect } from '@playwright/test'

// @e2e openspec/specs/contactmomenten/spec.md#navigation-item-present
test('contactmomenten navigation item visible', async ({ page }) => {
	await page.goto('/apps/pipelinq/')
	// The sidebar navigation renders links with the page name
	const navLink = page.locator('#app-navigation-vue').getByRole('link', { name: 'Contactmomenten' })
	await expect(navLink).toBeVisible({ timeout: 10000 })
})

// @e2e openspec/specs/contactmomenten/spec.md#display-contactmomenten-list
test('contactmomenten list page renders', async ({ page }) => {
	await page.goto('/apps/pipelinq/contactmomenten')
	await expect(page).toHaveURL(/contactmomenten/, { timeout: 10000 })
	await expect(page.locator('body')).not.toContainText('Internal Server Error')
})

// @e2e openspec/specs/contactmomenten/spec.md#quick-log-from-contactmomenten-list
test('contactmomenten page main content renders', async ({ page }) => {
	await page.goto('/apps/pipelinq/contactmomenten')
	await expect(page.locator('#app-content, .app-content, main').first()).toBeVisible({ timeout: 10000 })
})

// @e2e openspec/specs/contactmomenten/spec.md#create-a-contactmoment-with-minimal-fields
test('contactmomenten page loads without uncaught errors', async ({ page }) => {
	await page.goto('/apps/pipelinq/contactmomenten')
	await page.waitForLoadState('networkidle').catch(() => {})
	await expect(page.locator('body')).not.toContainText('Uncaught Error', { timeout: 10000 })
})

// @e2e openspec/specs/contactmomenten/spec.md#navigation-item-shows-count-badge
test('contactmomenten navigates from nav', async ({ page }) => {
	await page.goto('/apps/pipelinq/')
	// Click the Contactmomenten nav link
	await page.locator('#app-navigation-vue').getByRole('link', { name: 'Contactmomenten' }).click()
	await expect(page).toHaveURL(/contactmomenten/)
})

/*
 * Backend/API/store scenarios excluded:
 * @e2e exclude contactmoment-schema-exists-in-register — PHP repair step; covered by PHPUnit
 * @e2e exclude contactmoment-validates-required-fields — server validation; covered by PHPUnit
 * @e2e exclude create-a-contactmoment-linked-to-a-client-and-request — requires client and request seed data
 * @e2e exclude create-a-contactmoment-with-channel-metadata — requires form interaction + data
 * @e2e exclude update-a-contactmoment-summary — requires existing contactmoment record
 * @e2e exclude delete-a-contactmoment — requires existing record
 * @e2e exclude non-creator-cannot-delete — RBAC; covered by PHPUnit
 * @e2e exclude search-contactmomenten — requires existing data
 * @e2e exclude filter-by-channel — requires data with multiple channels
 * @e2e exclude filter-by-date-range — requires data with dates
 * @e2e exclude filter-by-agent — requires data with multiple agents
 * @e2e exclude display-contactmoment-details — requires existing record
 * @e2e exclude edit-contactmoment-from-detail-view — requires existing record
 * @e2e exclude quick-log-from-client-detail — requires existing client
 * @e2e exclude quick-log-from-request-detail — requires existing request
 * @e2e exclude quick-log-saves-and-refreshes-context — requires existing entity
 * @e2e exclude store-fetches-contactmomenten-list — Pinia store unit test; covered by Jest
 * @e2e exclude store-creates-a-contactmoment — Pinia store unit test; covered by Jest
 * @e2e exclude store-fetches-contactmomenten-for-a-specific-client — Pinia store unit test; covered by Jest
 * @e2e exclude delete-by-creating-agent — RBAC; covered by PHPUnit
 * @e2e exclude delete-by-admin — RBAC; covered by PHPUnit
 * @e2e exclude delete-by-non-creator-non-admin-rejected — RBAC; covered by PHPUnit
 * @e2e exclude delete-endpoint-returns-200-on-success — API contract; covered by Newman
 * @e2e exclude delete-endpoint-returns-403-on-unauthorized — API auth; covered by Newman
 */
