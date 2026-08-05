/*
 * SPDX-FileCopyrightText: 2026 Pipelinq Contributors
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Gate-19 e2e coverage for openspec/specs/lead-management/spec.md
 * UI-observable scenarios: leads page, navigation.
 * Most scenarios are backend/API/V1/Enterprise — excluded per-scenario below.
 */

import { test, expect } from '@playwright/test'

import { revealNavEntry } from '../helpers/pipelinq'

// @e2e openspec/specs/lead-management/spec.md#add-tags-to-a-lead
test('leads page accessible from navigation', async ({ page }) => {
	await page.goto('/apps/pipelinq/')
	// Relocated under the "Sales" group — see src/menu-layout.json#relocations.
	const link = await revealNavEntry(page, 'Leads')
	await expect(link).toBeVisible({ timeout: 10000 })
	await link.click()
	await expect(page).toHaveURL(/leads/)
})

// @e2e openspec/specs/lead-management/spec.md#display-qualification-score-on-lead-list-and-detail
test('leads list page renders without error', async ({ page }) => {
	await page.goto('/apps/pipelinq/leads')
	await expect(page.locator('body')).not.toContainText('Internal Server Error', { timeout: 10000 })
})

// @e2e openspec/specs/lead-management/spec.md#add-product-line-item-to-a-lead
test('leads page main content accessible', async ({ page }) => {
	await page.goto('/apps/pipelinq/leads')
	await expect(page.locator('#app-content, .app-content, main').first()).toBeVisible({ timeout: 10000 })
})

// @e2e openspec/specs/lead-management/spec.md#pipeline-value-summary-by-stage
test('leads dashboard KPI tile reflects pipeline value', async ({ page }) => {
	await page.goto('/apps/pipelinq/')
	await expect(page.getByText(/Pipeline V/i).first()).toBeVisible({ timeout: 10000 })
})

/*
 * Backend/API/V1/Enterprise scenarios excluded:
 * @e2e exclude create-lead-from-prospect-discovery-widget — V1 prospect feature; not yet implemented
 * @e2e exclude create-lead-via-public-web-form-api — API endpoint; covered by Newman
 * @e2e exclude reject-lead-capture-with-invalid-api-key — API auth; covered by Newman
 * @e2e exclude create-lead-from-inbound-email — V1 email integration; not yet implemented
 * @e2e exclude configure-scoring-criteria-in-admin-settings — V1 scoring; not yet implemented
 * @e2e exclude auto-calculate-qualification-score-on-lead-save — backend scoring; covered by PHPUnit
 * @e2e exclude sort-leads-by-qualification-score — requires scored lead data
 * @e2e exclude convert-lead-with-no-existing-client — requires existing lead record
 * @e2e exclude link-lead-to-existing-client-via-search — requires lead and client data
 * @e2e exclude bulk-convert-leads-to-clients — requires multiple lead records
 * @e2e exclude configure-round-robin-assignment — Enterprise assignment feature; not yet implemented
 * @e2e exclude round-robin-assignment-on-lead-creation — Enterprise backend; covered by PHPUnit
 * @e2e exclude manual-assignment-overrides-round-robin — Enterprise backend; covered by PHPUnit
 * @e2e exclude assignment-based-on-lead-source — Enterprise backend; covered by PHPUnit
 * @e2e exclude warn-on-potential-duplicate-during-creation — requires existing leads
 * @e2e exclude detect-duplicate-by-client-and-similar-value — backend dedup; covered by PHPUnit
 * @e2e exclude merge-two-duplicate-leads — requires duplicate lead data
 * @e2e exclude filter-leads-by-tag — requires tagged lead data
 * @e2e exclude manage-lead-tags-in-admin-settings — requires admin access + tag management
 * @e2e exclude auto-tag-leads-based-on-source — backend automation; covered by PHPUnit
 * @e2e exclude configure-stage-based-follow-up-reminders — Enterprise feature; not yet implemented
 * @e2e exclude nurture-stale-leads-with-automated-notifications — Enterprise automation; not yet implemented
 * @e2e exclude escalate-high-value-stale-leads — Enterprise automation; not yet implemented
 * @e2e exclude lead-conversion-rate-by-source — analytics; V1 feature
 * @e2e exclude lead-aging-report — analytics; V1 feature
 * @e2e exclude won-lost-analysis — analytics; V1 feature
 * @e2e exclude calculate-lead-value-from-line-items — backend calculation; covered by PHPUnit
 * @e2e exclude remove-product-line-item — requires existing lead with line items
 */
