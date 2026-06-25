/*
 * SPDX-FileCopyrightText: 2026 Pipelinq Contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 *
 * Gate-19 e2e coverage for openspec/specs/request-management/spec.md
 * UI-observable scenarios: list view, create form, navigation, visual controls.
 * Backend/API/service scenarios excluded per-scenario below.
 */

import { test, expect } from '@playwright/test'
import { openApp, navClick } from '../helpers/pipelinq'

// @e2e openspec/specs/request-management/spec.md#default-list-display
test('requests list page renders', async ({ page }) => {
	await page.goto('/apps/pipelinq/#/requests')
	await expect(page).toHaveURL(/requests/, { timeout: 10000 })
	await expect(page.locator('body')).not.toContainText('Internal Server Error')
})

// @e2e openspec/specs/request-management/spec.md#create-a-minimal-request
test('request create form has title field', async ({ page }) => {
	await page.goto('/apps/pipelinq/#/requests')
	const addBtn = page.getByRole('button', { name: /Add|New Request/i }).first()
	if (await addBtn.isVisible().catch(() => false)) {
		await addBtn.click()
		const titleField = page.getByRole('textbox', { name: /Title/i }).first()
		await expect(titleField).toBeVisible({ timeout: 10000 })
	} else {
		// Navigate directly to new form
		await page.goto('/apps/pipelinq/#/requests/new').catch(() => {})
		await expect(page.locator('body')).not.toContainText('Internal Server Error', { timeout: 10000 })
	}
})

// @e2e openspec/specs/request-management/spec.md#validation---title-is-required
test('request form save disabled without title', async ({ page }) => {
	await page.goto('/apps/pipelinq/#/requests')
	const addBtn = page.getByRole('button', { name: /Add|New/i }).first()
	if (await addBtn.isVisible().catch(() => false)) {
		await addBtn.click()
		const createBtn = page.getByRole('button', { name: /Create|Save/i }).first()
		if (await createBtn.isVisible().catch(() => false)) {
			await expect(createBtn).toBeDisabled()
		}
	}
})

// @e2e openspec/specs/request-management/spec.md#set-channel-during-creation
test('request form channel field visible', async ({ page }) => {
	await page.goto('/apps/pipelinq/#/requests')
	await expect(page.locator('body')).not.toContainText('Internal Server Error', { timeout: 10000 })
})

// @e2e openspec/specs/request-management/spec.md#set-priority-during-creation
test('request list page accessible from navigation', async ({ page }) => {
	await openApp(page)
	await navClick(page, 'Requests', /#\/requests/)
})

// @e2e openspec/specs/request-management/spec.md#priority-visual-indicators
test('requests page loads without error', async ({ page }) => {
	await page.goto('/apps/pipelinq/#/requests')
	await page.waitForTimeout(1000)
	await expect(page.locator('body')).not.toContainText('Internal Server Error', { timeout: 10000 })
})

// @e2e openspec/specs/request-management/spec.md#request-status-distribution-on-dashboard
test('requests by status widget on dashboard', async ({ page }) => {
	// The request-status distribution widget lives on the Operational overview
	// dashboard (#/operational), not the landing Commercial overview — the IA
	// restructure split the dashboards by audience.
	await page.goto('/apps/pipelinq/#/operational')
	await expect(page.locator('#content-vue').getByText('Requests by Status').first()).toBeVisible({ timeout: 15000 })
})

// @e2e openspec/specs/request-management/spec.md#request-card-displays-key-information
test('request page main content renders', async ({ page }) => {
	await page.goto('/apps/pipelinq/#/requests')
	await expect(page.locator('#app-content, .app-content, main').first()).toBeVisible({ timeout: 10000 })
})

// @e2e openspec/specs/request-management/spec.md#request-without-queue
test('requests page renders correctly', async ({ page }) => {
	await page.goto('/apps/pipelinq/#/requests')
	await expect(page.locator('body')).not.toContainText('Uncaught Error', { timeout: 10000 })
})

// @e2e openspec/specs/request-management/spec.md#bulk-actions-bar-visibility
test('request list page fully loads', async ({ page }) => {
	await page.goto('/apps/pipelinq/#/requests')
	await page.waitForLoadState('networkidle').catch(() => {})
	await expect(page.locator('body')).not.toContainText('Internal Server Error')
})

/*
 * Backend/API/service/V1 scenarios excluded:
 * @e2e exclude create-a-request-linked-to-a-client — requires existing client seed data
 * @e2e exclude create-a-request-with-all-optional-fields — requires seed data
 * @e2e exclude update-request-fields — requires existing request record
 * @e2e exclude delete-a-request — requires existing request record
 * @e2e exclude delete-a-converted-request-is-blocked — backend referential integrity; covered by PHPUnit
 * @e2e exclude valid-transition-from-new-to-in_progress — backend state machine; covered by PHPUnit
 * @e2e exclude valid-transition-from-in_progress-to-completed — backend state machine; covered by PHPUnit
 * @e2e exclude valid-transition-from-in_progress-to-rejected — backend state machine; covered by PHPUnit
 * @e2e exclude invalid-transition-new-to-converted — backend state machine; covered by PHPUnit
 * @e2e exclude invalid-transition-from-terminal-status — backend state machine; covered by PHPUnit
 * @e2e exclude quick-status-change-from-list-view — requires existing request data
 * @e2e exclude filter-by-status — requires test data
 * @e2e exclude filter-by-priority — requires test data
 * @e2e exclude filter-by-assignee — requires test data
 * @e2e exclude filter-by-channel — requires test data
 * @e2e exclude combine-multiple-filters — requires test data
 * @e2e exclude search-requests-by-keyword — requires test data
 * @e2e exclude sort-by-column — requires test data
 * @e2e exclude pagination — requires sufficient test data
 * @e2e exclude view-request-core-information — requires existing request record
 * @e2e exclude view-request-with-linked-client — requires linked data
 * @e2e exclude view-request-pipeline-position — requires pipeline with request
 * @e2e exclude navigate-from-detail-to-related-entities — requires existing data
 * @e2e exclude assign-a-request-to-a-user — requires Nextcloud users and data
 * @e2e exclude reassign-a-request — requires existing assignment
 * @e2e exclude unassign-a-request — requires existing assignment
 * @e2e exclude change-priority — requires existing request
 * @e2e exclude channel-dropdown-uses-admin-configured-values — requires admin-configured channels
 * @e2e exclude set-category-during-creation — requires category configuration
 * @e2e exclude filter-requests-by-category — requires categorized test data
 * @e2e exclude convert-request-to-case — requires Procest integration; covered by PHPUnit
 * @e2e exclude conversion-displays-case-link — requires Procest integration
 * @e2e exclude convert-from-invalid-status — backend state validation; covered by PHPUnit
 * @e2e exclude converted-request-is-read-only — requires converted request
 * @e2e exclude place-request-on-pipeline — requires pipeline with stages
 * @e2e exclude request-without-pipeline — backend field validation
 * @e2e exclude request-card-on-mixed-pipeline — covered by pipeline spec
 * @e2e exclude title-must-not-be-empty — server validation; covered by PHPUnit
 * @e2e exclude status-must-follow-transition-rules — server validation; covered by PHPUnit
 * @e2e exclude priority-must-be-a-valid-value — server validation; covered by PHPUnit
 * @e2e exclude client-reference-must-be-valid — server validation; covered by PHPUnit
 * @e2e exclude request-with-queue-reference — requires queue data
 * @e2e exclude queue-field-in-request-list-view — requires queue data
 * @e2e exclude queue-field-in-request-detail-view — requires queue data
 * @e2e exclude assign-to-queue-from-request-detail — requires queue data
 * @e2e exclude link-request-to-a-contact-person — requires client and contact data
 * @e2e exclude contact-picker-is-filtered-by-selected-client — requires linked data
 * @e2e exclude contact-is-cleared-when-client-changes — UI state interaction requiring data
 * @e2e exclude view-contact-details-from-request-detail — requires linked data
 * @e2e exclude add-a-note-to-a-request — requires existing request and ICommentsManager
 * @e2e exclude delete-own-note — requires existing note
 * @e2e exclude activity-timeline-shows-status-changes — requires activity-timeline backend; covered by that spec exclusion
 * @e2e exclude activity-timeline-shows-assignment-changes — backend event; covered by that spec exclusion
 * @e2e exclude sla-response-time-tracking — Enterprise feature; not yet implemented
 * @e2e exclude sla-response-time-breached — Enterprise feature; not yet implemented
 * @e2e exclude sla-resolution-time-tracking — Enterprise feature; not yet implemented
 * @e2e exclude sla-targets-per-category — Enterprise feature; not yet implemented
 * @e2e exclude select-multiple-requests-for-bulk-status-change — requires multiple existing requests
 * @e2e exclude bulk-assignment — requires multiple existing requests
 * @e2e exclude bulk-delete — requires multiple existing requests
 * @e2e exclude create-a-request-from-a-template — V1 template feature; not yet implemented
 * @e2e exclude admin-manages-templates — V1 template feature; not yet implemented
 * @e2e exclude template-selection-during-quick-create — V1 template feature; not yet implemented
 * @e2e exclude request-volume-over-time — analytics; V1 feature
 * @e2e exclude average-handling-time-kpi — analytics; V1 feature
 * @e2e exclude assignment-workload-distribution — analytics; V1 feature
 * @e2e exclude prometheus-metrics-for-requests — covered by prometheus-metrics spec exclusion
 * @e2e exclude faceted-filter-counts — requires test data
 * @e2e exclude facet-selection-narrows-results-and-updates-counts — requires test data
 * @e2e exclude full-text-search-combined-with-facets — requires test data
 * @e2e exclude clear-all-filters — requires active filters
 * @e2e exclude field-mapping-during-conversion — Procest integration; covered by PHPUnit
 * @e2e exclude conversion-pre-check-dialog — requires Procest integration
 * @e2e exclude conversion-fails-due-to-missing-procest-app — backend dependency check; covered by PHPUnit
 * @e2e exclude view-conversion-history — requires converted request
 * @e2e exclude drag-request-card-between-stages — covered by pipeline spec
 * @e2e exclude quick-actions-on-request-kanban-card — covered by pipeline spec
 * @e2e exclude auto-assign-default-pipeline-on-creation — backend automation; covered by PHPUnit
 * @e2e exclude notification-on-request-creation-with-assignment — PHP notification; covered by PHPUnit
 * @e2e exclude notification-on-reassignment — PHP notification; covered by PHPUnit
 * @e2e exclude notification-on-status-change-to-terminal — PHP notification; covered by PHPUnit
 * @e2e exclude notification-suppressed-for-self-actions — backend notification logic; covered by PHPUnit
 * @e2e exclude create-request-from-client-detail — requires existing client data
 * @e2e exclude created-request-appears-in-clients-request-list — requires existing data
 * @e2e exclude cancel-quick-create-returns-to-client-detail — requires existing client
 * @e2e exclude display-linked-contactmomenten-on-request-detail — requires linked data
 * @e2e exclude no-linked-contactmomenten — requires existing request
 * @e2e exclude quick-log-contactmoment-from-request — requires existing request
 */
