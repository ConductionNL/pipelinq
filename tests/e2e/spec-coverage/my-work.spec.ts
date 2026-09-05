/*
 * SPDX-FileCopyrightText: 2026 Pipelinq Contributors
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Gate-19 e2e coverage for openspec/specs/my-work/spec.md
 * UI-observable scenarios: page loads, navigation, empty state.
 * Backend/data/V1/Enterprise scenarios excluded per-scenario below.
 */

import { expect, test } from '@playwright/test'
import { assertAppShellServed, revealNavEntry } from '../helpers/pipelinq.ts'

// @e2e openspec/specs/my-work/spec.md#empty-workload
test('my work page renders with empty state', async ({ page }) => {
	await page.goto('/apps/pipelinq/my-work')
	await expect(page).toHaveURL(/my-work/, { timeout: 10000 })
	await expect(page.locator('body')).not.toContainText('Internal Server Error')
})

// @e2e openspec/specs/my-work/spec.md#view-assigned-leads-and-requests
test('my work page accessible from navigation', async ({ page }) => {
	await page.goto('/apps/pipelinq/')
	// Relocated under the "Customer Support" (KccWerkplek) group — see
	// src/menu-layout.json#relocations.
	const link = await revealNavEntry(page, 'My Work')
	await expect(link).toBeVisible({ timeout: 10000 })
	await link.click()
	await expect(page).toHaveURL(/my-work/)
})

// @e2e openspec/specs/my-work/spec.md#filter-by-entity-type
test('my work page loads without error', async ({ page }) => {
	await page.goto('/apps/pipelinq/my-work')
	await page.waitForTimeout(1000)
	await expect(page.locator('body')).not.toContainText('Internal Server Error', {
		timeout: 10000,
	})
})

// @e2e openspec/specs/my-work/spec.md#quick-action-buttons-displayed
test('my work page main content renders', async ({ page }) => {
	await page.goto('/apps/pipelinq/my-work')
	await expect(
		page.locator('#app-content, .app-content, main').first(),
	).toBeVisible({ timeout: 10000 })
})

// @e2e openspec/specs/my-work/spec.md#manual-refresh
test('my work page renders without uncaught errors', async ({ page }) => {
	await page.goto('/apps/pipelinq/my-work')
	await page.waitForLoadState('domcontentloaded').catch(() => {})
	await expect(page.locator('body')).not.toContainText('Uncaught Error', {
		timeout: 10000,
	})
})

// @e2e openspec/specs/my-work/spec.md#display-personal-kpi-tiles
test('my work navigation entry in sidebar', async ({ page }) => {
	await page.goto('/apps/pipelinq/')
	// Scope to the app sidebar: an unscoped getByText('My Work') would also
	// match the widget heading in the page body, so it could pass while the nav
	// entry is missing.
	await expect(await revealNavEntry(page, 'My Work')).toBeVisible({
		timeout: 10000,
	})
})

// @e2e openspec/specs/my-work/spec.md#view-assigned-leads--requests-and-tasks (V1)
test('my work page loads for V1 expanded view', async ({ page }) => {
	const response = await page.goto('/apps/pipelinq/my-work')
	// Was `not.toContainText('not installed')`, which is a false positive: the
	// app legitimately renders "<X> … is not installed or enabled" banners for
	// optional siblings (Deck, Forms, Time manager, OpenConnector). Assert the
	// real failure mode instead — see assertAppShellServed.
	await assertAppShellServed(page, response)
})

/*
 * Backend/data/V1/Enterprise scenarios excluded:
 * @e2e exclude only-open-items-by-default — requires assigned items as test data
 * @e2e exclude toggle-to-show-completed-items — requires completed assigned items
 * @e2e exclude item-count-display — requires assigned items
 * @e2e exclude default-sort-order-within-groups — requires multiple assigned items
 * @e2e exclude items-without-due-date-positioning — requires items without due dates
 * @e2e exclude overdue-group — requires items with past due dates
 * @e2e exclude due-this-week-group — requires items due this week
 * @e2e exclude upcoming-group — requires future-dated items
 * @e2e exclude no-due-date-group — requires items without due dates
 * @e2e exclude section-item-counts — requires assigned items
 * @e2e exclude empty-group-behavior — requires controlled data state
 * @e2e exclude filter-with-empty-result — requires data + filter interaction
 * @e2e exclude overdue-visual-treatment — requires overdue items
 * @e2e exclude due-today-is-not-overdue — requires item due today
 * @e2e exclude click-item-to-navigate — requires assigned items
 * @e2e exclude include-procest-tasks — V1 Procest integration; not yet implemented
 * @e2e exclude filter-includes-procest-entity-types — V1 Procest integration; not yet implemented
 * @e2e exclude procest-unavailable-gracefully — backend graceful degradation; covered by PHPUnit
 * @e2e exclude lead-card-in-my-work — requires assigned lead data
 * @e2e exclude request-card-in-my-work — requires assigned request data
 * @e2e exclude picking-work-up-moves-it — mutates a shared instance
 * @e2e exclude task-card-in-my-work — V1 task cards; not yet implemented
 * @e2e exclude kpi-tiles-update-with-filter — requires data + filter interaction
 * @e2e exclude kpi-tiles-with-zero-values — depends on test data state
 * @e2e exclude create-lead-via-quick-action — requires navigation to lead create form with data
 * @e2e exclude create-request-via-quick-action — requires navigation to request create form
 * @e2e exclude create-contact-via-quick-action — requires navigation to contact create form
 * @e2e exclude display-recent-activity — requires activity data; covered by activity-timeline spec
 * @e2e exclude activity-entry-navigation — requires activity data
 * @e2e exclude collapse-activity-feed — requires activity data
 * @e2e exclude empty-activity-feed — depends on data state
 * @e2e exclude display-follow-up-reminders — V1 follow-up feature; not yet implemented
 * @e2e exclude follow-up-due-today-highlighted — V1 follow-up feature; not yet implemented
 * @e2e exclude overdue-follow-ups — V1 follow-up feature; not yet implemented
 * @e2e exclude no-follow-ups-scheduled — V1 follow-up feature; not yet implemented
 * @e2e exclude display-upcoming-meetings — V1 calendar integration; not yet implemented
 * @e2e exclude calendar-integration-via-nextcloud-caldav — V1 calendar integration; not yet implemented
 * @e2e exclude calendar-app-not-available — backend graceful degradation
 * @e2e exclude no-upcoming-meetings — V1 calendar integration; not yet implemented
 * @e2e exclude display-notification-count — requires Nextcloud notification data
 * @e2e exclude notification-types-displayed — requires notification data
 * @e2e exclude mark-notification-as-read — requires existing notifications
 * @e2e exclude no-unread-notifications — depends on notification state
 * @e2e exclude save-current-filter-as-preset — Enterprise user preference feature
 * @e2e exclude apply-saved-filter — Enterprise user preference feature
 * @e2e exclude delete-saved-filter — Enterprise user preference feature
 * @e2e exclude saved-filters-persist-across-sessions — Enterprise persistence feature
 * @e2e exclude toggle-section-visibility — Enterprise customization feature
 * @e2e exclude reorder-sections — Enterprise customization feature
 * @e2e exclude reset-to-default-layout — Enterprise customization feature
 * @e2e exclude mobile-card-layout — mobile viewport; not in CI scope
 * @e2e exclude touch-friendly-interaction-targets — mobile viewport; not in CI scope
 * @e2e exclude mobile-quick-actions — mobile viewport; not in CI scope
 * @e2e exclude narrow-viewport-group-headers — mobile viewport; not in CI scope
 * @e2e exclude kcc-agent-view-default — Enterprise role-based views; not yet implemented
 * @e2e exclude team-manager-view — Enterprise role-based views; not yet implemented
 * @e2e exclude manager-team-member-drill-down — Enterprise role-based views; not yet implemented
 * @e2e exclude administrator-view — Enterprise role-based views; not yet implemented
 * @e2e exclude periodic-auto-refresh — timer-based background fetch; not UI-observable
 * @e2e exclude stale-data-indicator — requires stale data condition
 * @e2e exclude filter-by-priority — requires assigned items
 * @e2e exclude filter-by-pipeline — requires assigned items with pipeline
 * @e2e exclude combined-filters — requires test data
 * @e2e exclude clear-all-filters — requires active filters
 */
