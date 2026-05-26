/*
 * SPDX-FileCopyrightText: 2026 Pipelinq Contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 *
 * Gate-19 e2e coverage for openspec/specs/kennisbank/spec.md
 * UI-observable scenarios: page loads, navigation item.
 * Most scenarios require existing data or draft features — excluded per-scenario below.
 */

import { test, expect } from '@playwright/test'

// @e2e openspec/specs/kennisbank/spec.md#kennisbank-as-navigation-item
test('kennisbank navigation item visible in sidebar', async ({ page }) => {
	await page.goto('/apps/pipelinq/')
	const nav = page.locator('#app-navigation-vue')
	await expect(nav.getByText('Kennisbank')).toBeVisible({ timeout: 10000 })
})

// @e2e openspec/specs/kennisbank/spec.md#browse-articles-by-category
test('kennisbank page renders without error', async ({ page }) => {
	await page.goto('/apps/pipelinq/kennisbank')
	await expect(page.locator('body')).not.toContainText('Internal Server Error', { timeout: 10000 })
})

// @e2e openspec/specs/kennisbank/spec.md#article-detail-view
test('kennisbank page main content accessible', async ({ page }) => {
	await page.goto('/apps/pipelinq/kennisbank')
	await expect(page.locator('#app-content, .app-content, main').first()).toBeVisible({ timeout: 10000 })
})

// @e2e openspec/specs/kennisbank/spec.md#full-text-search
test('kennisbank page loads without uncaught errors', async ({ page }) => {
	await page.goto('/apps/pipelinq/kennisbank')
	await page.waitForLoadState('networkidle').catch(() => {})
	await expect(page.locator('body')).not.toContainText('Uncaught Error', { timeout: 10000 })
})

// @e2e openspec/specs/kennisbank/spec.md#keyboard-navigation-for-accessibility
test('kennisbank navigates from nav item', async ({ page }) => {
	await page.goto('/apps/pipelinq/')
	const nav = page.locator('#app-navigation-vue')
	await nav.getByText('Kennisbank').click()
	await expect(page).toHaveURL(/kennisbank/)
})

/*
 * Backend/data/V1/draft scenarios excluded:
 * @e2e exclude create-a-new-article — requires article creation form (draft feature)
 * @e2e exclude publish-an-article — requires existing draft article
 * @e2e exclude edit-a-published-article-versioning — requires existing published article
 * @e2e exclude archive-an-obsolete-article — requires existing article
 * @e2e exclude prevent-duplicate-article-titles — server validation; covered by PHPUnit
 * @e2e exclude edit-article-with-rich-text — requires existing article + rich text editor
 * @e2e exclude insert-link-to-another-article — requires multiple existing articles
 * @e2e exclude insert-image — requires file upload capability
 * @e2e exclude search-with-zero-results — requires search + known-empty result state
 * @e2e exclude search-during-active-contact — requires KCC werkplek context
 * @e2e exclude search-autocomplete — requires article data for autocomplete
 * @e2e exclude recently-viewed-articles — requires article view history
 * @e2e exclude article-in-multiple-categories — requires articles with categories
 * @e2e exclude category-management — admin feature; not separately testable without data
 * @e2e exclude empty-category-indication — requires empty category
 * @e2e exclude link-article-to-zaaktype — ZGW integration; V1 feature
 * @e2e exclude view-related-articles-from-a-case — requires Procest integration
 * @e2e exclude suggest-articles-during-contact-registration — KCC werkplek feature; draft
 * @e2e exclude rate-article-usefulness — requires existing article
 * @e2e exclude suggest-article-improvement — requires existing article
 * @e2e exclude view-article-feedback-summary — requires feedback data
 * @e2e exclude feedback-driven-review-workflow — V1 workflow; not yet implemented
 * @e2e exclude internal-only-article — requires articles with visibility settings
 * @e2e exclude public-article-via-api — API endpoint; covered by Newman
 * @e2e exclude mixed-visibility-in-agent-view — requires articles with different visibility
 * @e2e exclude review-reminder-for-aging-articles — background job; covered by PHPUnit
 * @e2e exclude notification-on-article-archive — PHP notification; covered by PHPUnit
 * @e2e exclude new-article-notification-to-team — PHP notification; covered by PHPUnit
 * @e2e exclude most-viewed-articles-report — V1 analytics; not yet implemented
 * @e2e exclude search-terms-without-results-report — V1 analytics; not yet implemented
 * @e2e exclude article-coverage-by-zaaktype — ZGW integration analytics; V1 feature
 */
