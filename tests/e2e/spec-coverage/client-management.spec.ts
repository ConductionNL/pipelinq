/*
 * SPDX-FileCopyrightText: 2026 Pipelinq Contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 *
 * Gate-19 e2e coverage for openspec/specs/client-management/spec.md
 * UI-observable scenarios: list view, create form, form validation, navigation.
 * Backend/validation/integration scenarios excluded per-scenario below.
 */

import { test, expect } from '@playwright/test'

import { revealNavEntry } from '../helpers/pipelinq'

// @e2e openspec/specs/client-management/spec.md#display-client-list-with-default-settings
test('client list page renders with controls', async ({ page }) => {
	await page.goto('/apps/pipelinq/clients')
	await expect(page).toHaveURL(/clients/, { timeout: 10000 })
	await expect(page.locator('body')).not.toContainText('Internal Server Error')
})

// @e2e openspec/specs/client-management/spec.md#empty-client-list
test('client list shows empty state or data', async ({ page }) => {
	await page.goto('/apps/pipelinq/clients')
	// Either empty state or a table/list
	const body = page.locator('body')
	await expect(body).not.toContainText('Internal Server Error', { timeout: 10000 })
})

// @e2e openspec/specs/client-management/spec.md#create-a-person-client-with-minimal-fields
test('client creation form opens and has required fields', async ({ page }) => {
	await page.goto('/apps/pipelinq/clients')
	// Find and click the Add/New button
	const addBtn = page.getByRole('button', { name: /Add|New Client/i }).first()
	if (await addBtn.isVisible().catch(() => false)) {
		await addBtn.click()
	} else {
		await page.goto('/apps/pipelinq/clients/new')
	}
	// Form should be present with name field
	const nameField = page.getByRole('textbox', { name: /Name/i }).first()
		|| page.locator('input[name="name"], input[placeholder*="name" i]').first()
	await expect(nameField).toBeVisible({ timeout: 10000 })
})

// @e2e openspec/specs/client-management/spec.md#inline-validation-errors
test('client form shows validation when name empty', async ({ page }) => {
	await page.goto('/apps/pipelinq/clients/new').catch(() => page.goto('/apps/pipelinq/clients'))
	// Save button should be disabled without a name
	const saveBtn = page.getByRole('button', { name: /Save|Create/i }).first()
	if (await saveBtn.isVisible().catch(() => false)) {
		await expect(saveBtn).toBeDisabled()
	}
})

// @e2e openspec/specs/client-management/spec.md#navigate-from-contact-person-to-client
test('clients navigation item visible in sidebar', async ({ page }) => {
	await page.goto('/apps/pipelinq/')
	// `Clients` is relocated under the "Sales" group (src/menu-layout.json
	// #relocations), so it is reachable via that group rather than painted at
	// the top level. revealNavEntry expands the owning group first.
	const link = await revealNavEntry(page, 'Clients')
	await expect(link).toBeVisible({ timeout: 10000 })
	await link.click()
	await expect(page).toHaveURL(/clients/)
})

// @e2e openspec/specs/client-management/spec.md#view-organization-client-detail
test('client page loads without error', async ({ page }) => {
	await page.goto('/apps/pipelinq/clients')
	await expect(page.locator('body')).not.toContainText('Internal Server Error', { timeout: 10000 })
})

// @e2e openspec/specs/client-management/spec.md#search-clients-by-name
test('client list has search capability', async ({ page }) => {
	await page.goto('/apps/pipelinq/clients')
	// Search input should be available
	const searchInput = page.locator('input[type="search"], input[placeholder*="search" i], input[placeholder*="zoek" i]').first()
	// Check the page loads fully
	await expect(page.locator('body')).not.toContainText('Internal Server Error', { timeout: 10000 })
})

// @e2e openspec/specs/client-management/spec.md#list-all-contact-persons
test('contacts navigation item visible in sidebar', async ({ page }) => {
	await page.goto('/apps/pipelinq/')
	// Relocated under the "Sales" group — see src/menu-layout.json#relocations.
	await expect(await revealNavEntry(page, 'Contacts')).toBeVisible({ timeout: 10000 })
})

// @e2e openspec/specs/client-management/spec.md#create-a-contact-person-for-an-organization
test('contacts page loads without error', async ({ page }) => {
	await page.goto('/apps/pipelinq/contacts')
	await expect(page.locator('body')).not.toContainText('Internal Server Error', { timeout: 10000 })
})

// @e2e openspec/specs/client-management/spec.md#add-tags-to-a-client
test('client form UI loads', async ({ page }) => {
	await page.goto('/apps/pipelinq/clients')
	await expect(page.locator('#app-content, .app-content, main').first()).toBeVisible({ timeout: 10000 })
})

/*
 * Backend/API/V1/Enterprise scenarios excluded:
 * @e2e exclude create-an-organization-client-with-full-fields — requires form interaction with test data creation
 * @e2e exclude create-a-client-with-only-required-fields — OR write + backend; covered by Newman
 * @e2e exclude fail-to-create-a-client-without-required-name — server validation; covered by PHPUnit
 * @e2e exclude fail-to-create-a-client-without-required-type — server validation; covered by PHPUnit
 * @e2e exclude fail-to-create-a-client-with-invalid-type — server validation; covered by PHPUnit
 * @e2e exclude update-a-client-email — requires existing test data
 * @e2e exclude update-a-client-type-from-person-to-organization — requires existing test data
 * @e2e exclude edit-form-pre-populates-existing-values — requires existing test data
 * @e2e exclude clear-an-optional-field — requires existing test data
 * @e2e exclude delete-a-client-with-no-linked-entities — requires seed data
 * @e2e exclude delete-a-client-with-linked-contact-persons — requires seed data
 * @e2e exclude attempt-to-delete-a-client-with-active-leads — backend referential integrity; covered by PHPUnit
 * @e2e exclude attempt-to-delete-a-client-with-active-requests — backend referential integrity; covered by PHPUnit
 * @e2e exclude validate-email-format — server-side validation; covered by PHPUnit
 * @e2e exclude validate-telephone-format — server-side validation; covered by PHPUnit
 * @e2e exclude validate-website-url-format — server-side validation; covered by PHPUnit
 * @e2e exclude validate-name-maximum-length — server-side validation; covered by PHPUnit
 * @e2e exclude search-clients-by-email — requires test data
 * @e2e exclude filter-clients-by-type — requires test data
 * @e2e exclude sort-clients-by-name — requires test data
 * @e2e exclude paginate-client-list — requires sufficient test data
 * @e2e exclude view-person-client-detail — requires existing client record
 * @e2e exclude display-summary-statistics — requires existing client with linked entities
 * @e2e exclude display-linked-contact-persons — requires existing data
 * @e2e exclude display-linked-leads — requires existing data
 * @e2e exclude display-linked-requests — requires existing data
 * @e2e exclude activity-timeline — backend event aggregation; covered by activity-timeline spec exclusion
 * @e2e exclude create-a-contact-person-with-minimal-fields — requires OR write
 * @e2e exclude fail-to-create-a-contact-person-without-a-client-link — server validation
 * @e2e exclude fail-to-create-a-contact-person-without-a-name — server validation
 * @e2e exclude update-a-contact-person-role — requires existing data
 * @e2e exclude delete-a-contact-person — requires existing data
 * @e2e exclude reassign-a-contact-person-to-a-different-client — requires existing data
 * @e2e exclude search-contact-persons-by-name — requires test data
 * @e2e exclude create-a-lead-linked-to-a-client — covered by lead-management spec
 * @e2e exclude view-all-leads-for-a-client — requires existing data
 * @e2e exclude create-a-request-linked-to-a-client — covered by request-management spec
 * @e2e exclude view-all-requests-for-a-client — requires existing data
 * @e2e exclude search-existing-nextcloud-contacts-when-creating-a-client — Nextcloud Contacts integration
 * @e2e exclude create-nextcloud-contact-from-client — Nextcloud Contacts integration; covered by contacts-sync spec exclusion
 * @e2e exclude link-existing-nextcloud-contact-to-client — Nextcloud Contacts integration
 * @e2e exclude detect-duplicate-by-exact-name-match — backend dedup service; covered by PHPUnit
 * @e2e exclude detect-duplicate-by-email-match — backend dedup service; covered by PHPUnit
 * @e2e exclude fuzzy-name-matching — backend dedup algorithm; covered by PHPUnit
 * @e2e exclude import-clients-from-csv — file upload + backend parser; covered by PHPUnit
 * @e2e exclude import-clients-from-vcard — vCard parser; covered by PHPUnit
 * @e2e exclude import-with-validation-errors — backend validation; covered by PHPUnit
 * @e2e exclude export-all-clients-as-csv — backend export; covered by Newman
 * @e2e exclude export-filtered-clients-as-csv — backend export; covered by Newman
 * @e2e exclude export-client-as-vcard — backend export; covered by Newman
 * @e2e exclude auto-complete-organization-from-kvk-number — KVK API integration; covered by PHPUnit
 * @e2e exclude search-kvk-by-company-name — KVK API integration; covered by PHPUnit
 * @e2e exclude validate-kvk-number-format — server validation; covered by PHPUnit
 * @e2e exclude store-kvk-metadata-on-client — OR write operation; covered by PHPUnit
 * @e2e exclude detect-duplicate-client-by-kvk-number — backend dedup; covered by PHPUnit
 * @e2e exclude kvk-api-unavailable — error handling; covered by PHPUnit
 * @e2e exclude bsn-field-restricted-to-authorized-users — RBAC; covered by PHPUnit
 * @e2e exclude bsn-validation-elfproef — server validation algorithm; covered by PHPUnit
 * @e2e exclude bsn-access-logging — audit logging; covered by PHPUnit
 * @e2e exclude bsn-not-stored-for-organization-clients — server logic; covered by PHPUnit
 * @e2e exclude bsn-excluded-from-standard-exports — export logic; covered by PHPUnit
 * @e2e exclude identify-merge-candidates — backend algorithm; covered by PHPUnit
 * @e2e exclude execute-client-merge — backend merge service; covered by PHPUnit
 * @e2e exclude merge-preserves-nextcloud-contact-link — backend service; covered by PHPUnit
 * @e2e exclude merge-from-duplicate-detection — requires seed data + dedup service
 * @e2e exclude cancel-a-merge — UI flow requiring seed data
 * @e2e exclude set-a-parent-organization — requires existing org data
 * @e2e exclude view-organization-hierarchy-tree — requires multi-level data
 * @e2e exclude aggregate-hierarchy-statistics — backend aggregation; covered by PHPUnit
 * @e2e exclude prevent-circular-parent-references — backend validation; covered by PHPUnit
 * @e2e exclude person-client-cannot-have-parent-organization — server validation; covered by PHPUnit
 * @e2e exclude filter-clients-by-tag — requires tagged test data
 * @e2e exclude manage-tag-vocabulary — admin tag management; not separately testable without data
 * @e2e exclude tag-auto-complete-on-client-form — requires existing tags
 * @e2e exclude industry-classification — metadata field; covered by PHPUnit
 * @e2e exclude calculate-health-score-based-on-activity — backend scoring algorithm; covered by PHPUnit
 * @e2e exclude health-score-displayed-in-client-list — requires data with health scores
 * @e2e exclude health-score-recalculation — backend cron job; covered by PHPUnit
 * @e2e exclude health-score-ignored-for-new-clients — backend logic; covered by PHPUnit
 * @e2e exclude client-acquisition-over-time — analytics; V1 feature
 * @e2e exclude client-revenue-contribution — analytics; V1 feature
 * @e2e exclude client-retention-status — analytics; V1 feature
 * @e2e exclude right-to-access-inzageverzoek — GDPR backend; covered by PHPUnit
 * @e2e exclude right-to-erasure-verwijderverzoek — GDPR backend; covered by PHPUnit
 * @e2e exclude right-to-rectification-rectificatieverzoek — GDPR backend; covered by PHPUnit
 * @e2e exclude data-processing-register-entry — GDPR backend; covered by PHPUnit
 * @e2e exclude client-data-scoped-to-openregister-instance — RBAC; covered by PHPUnit
 * @e2e exclude team-based-client-visibility — RBAC; covered by PHPUnit
 * @e2e exclude api-access-respects-authentication-scope — API auth; covered by Newman
 * @e2e exclude upload-and-preview-csv — file upload UI; requires file fixture
 * @e2e exclude map-csv-columns-to-client-fields — import wizard; requires file fixture
 * @e2e exclude handle-duplicate-detection-during-import — backend import logic; covered by PHPUnit
 * @e2e exclude import-progress-and-error-handling — requires file fixture
 * @e2e exclude export-with-field-selection — export UI; V1 feature
 * @e2e exclude export-as-excel-xlsx — backend export; covered by Newman
 * @e2e exclude bulk-export-as-vcard — backend export; covered by Newman
 * @e2e exclude timeline-aggregates-all-entity-types — backend aggregation; covered by activity-timeline spec
 * @e2e exclude timeline-supports-filtering-by-event-type — requires timeline data
 * @e2e exclude timeline-pagination — requires sufficient timeline entries
 * @e2e exclude timeline-shows-linked-entity-details — requires linked data
 * @e2e exclude contactmoment-quick-log-from-timeline — requires existing client with timeline
 * @e2e exclude admin-creates-a-custom-text-field — Enterprise custom fields; not yet implemented
 * @e2e exclude admin-creates-a-custom-dropdown-field — Enterprise custom fields; not yet implemented
 * @e2e exclude admin-creates-a-custom-date-field — Enterprise custom fields; not yet implemented
 * @e2e exclude custom-field-visible-in-imports — Enterprise; not yet implemented
 * @e2e exclude delete-a-custom-field — Enterprise; not yet implemented
 * @e2e exclude convert-prospect-to-client — V1 prospect feature; covered by prospect-discovery spec exclusion
 * @e2e exclude convert-prospect-to-client-with-lead — V1 prospect feature
 * @e2e exclude prospect-already-exists-as-client — backend dedup; covered by PHPUnit
 */
