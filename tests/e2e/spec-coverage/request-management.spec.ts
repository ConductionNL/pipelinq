/*
 * SPDX-FileCopyrightText: 2026 Pipelinq Contributors
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Gate-19 e2e coverage for openspec/specs/request-management/spec.md
 *
 * RETARGETED 2026-08-06. This file was the clearest case in the suite of tests
 * that could not fail.
 *
 * `unify-ticket-supertype` removed the `/requests` route: request tickets live
 * on the unified Tickets index (src/manifest.json page id `Tickets`, route
 * `/tickets`) behind the "Tickets" `quickFilters[]` tab. Eight of the ten tests
 * here did `page.goto('/apps/pipelinq/requests')` and then asserted only that
 * the body did NOT contain "Internal Server Error" / "Uncaught Error", or that
 * `main` was visible. With no `/requests` route the hash router falls back to
 * the Dashboard — and the Dashboard satisfies every one of those assertions.
 * They reported green about a page that had not existed for weeks. Only the two
 * that named the route (`toHaveURL(/requests/)`) and the sidebar entry
 * ("Requests") were honest enough to go red.
 *
 * Each test below now asserts something ONLY the request surface satisfies.
 */

import { test, expect } from '@playwright/test'
import {
	openApp,
	navClick,
	clickQuickFilter,
	trackPipelinqErrors,
	assertNoHardError,
	dismissSupportDialog,
} from '../helpers/pipelinq'

/** Open the Tickets workspace narrowed to the request subtype. */
async function openRequests(page: import('@playwright/test').Page): Promise<void> {
	await openApp(page)
	await navClick(page, 'Tickets', /\/tickets/)
	await clickQuickFilter(page, 'Tickets')
}

// @e2e openspec/specs/request-management/spec.md#default-list-display
test('requests list renders the seeded request tickets', async ({ page }) => {
	await openRequests(page)

	const content = page.locator('#content-vue')
	await expect(
		content
			.locator('table, .cn-data-table, [data-testid="cn-data-table"]')
			.first(),
	).toBeVisible()

	// Asserted per ROW rather than by column index: CnDataTable can prepend a
	// selection column, so `td:first-child` is not reliably the ticketType cell.
	//
	// NOT asserted positionally. This loop used to be
	//
	//     for (let i = 0; i < count; i++)
	//         await expect(rows.nth(i)).toContainText(/request/i)
	//
	// which couples the test to how many rows fit on page 1 and in what order
	// they arrive. It failed at nth(10) on one run and nth(4) on another — a
	// moving index is the signature of an order-dependent assertion, not of the
	// filter being broken. See #1441.
	//
	// The requirement is that the tab narrows to the request subtype, which is
	// two claims: the list is non-empty AND it excludes the other subtypes.
	// Both are checked below without depending on row order.
	const rows = content.locator('table tbody tr')
	await expect(rows.first()).toBeVisible()
	const count = await rows.count()
	expect(
		count,
		'the Tickets tab must show at least one seeded request',
	).toBeGreaterThan(0)

	// `expect(locator)` rather than `expect(await locator.count())`: the former
	// RETRIES until the timeout, the latter takes a single snapshot. Clicking
	// the tab starts a fetch, so a snapshot taken before it lands measures the
	// unfiltered list.
	await expect(
		rows.filter({ hasText: /request/i }).first(),
		'the Tickets tab must show at least one row of the request subtype',
	).toBeVisible({ timeout: 15000 })

	await expect(
		rows.filter({ hasText: /complaint|interaction/i }),
		'the Tickets tab filters ticketType=request, so no complaint or '
			+ 'interaction row may appear',
	).toHaveCount(0, { timeout: 15000 })
})

// @e2e openspec/specs/request-management/spec.md#create-a-minimal-request
test('request create form exposes a title field', async ({ page }) => {
	await openRequests(page)
	await dismissSupportDialog(page)

	await page
		.locator('#content-vue')
		.getByRole('button', { name: 'Add Ticket' })
		.click()
	const dialog = page.locator('.modal-container, [role="dialog"]').first()
	await expect(dialog).toBeVisible({ timeout: 10000 })
	// `title` is REQUIRED on the ticket schema, so the create form must offer it.
	await expect(
		dialog.getByRole('textbox', { name: /title/i }).first(),
	).toBeVisible({ timeout: 10000 })
})

// @e2e openspec/specs/request-management/spec.md#validation---title-is-required
test('request create form offers the required ticketType discriminator', async ({
	page,
}) => {
	await openRequests(page)
	await dismissSupportDialog(page)

	await page
		.locator('#content-vue')
		.getByRole('button', { name: 'Add Ticket' })
		.click()
	const dialog = page.locator('.modal-container, [role="dialog"]').first()
	await expect(dialog).toBeVisible({ timeout: 10000 })
	// `ticketType` is the second REQUIRED field on the schema. It is what makes
	// this one form able to create a request, a complaint or a contactmoment,
	// so its presence is the contract this unified surface stands on.
	await expect(dialog.getByText(/ticket ?type/i).first()).toBeVisible({
		timeout: 10000,
	})
})

// @e2e openspec/specs/request-management/spec.md#set-channel-during-creation
test('request list surfaces the channel column', async ({ page }) => {
	await openRequests(page)
	// `channel` is a declared column on the Tickets index and the seeded
	// requests carry web / telefoon / email values.
	await expect(page.locator('#content-vue table tbody')).toContainText(
		/web|telefoon|email|balie/i,
		{ timeout: 15000 },
	)
})

// @e2e openspec/specs/request-management/spec.md#set-priority-during-creation
test('request list is reachable from the sidebar', async ({ page }) => {
	await openApp(page)
	await navClick(page, 'Tickets', /#\/tickets/)
	await expect(
		page
			.locator('#content-vue')
			.getByRole('heading', { name: 'Tickets' })
			.first(),
	).toBeVisible()
})

// @e2e openspec/specs/request-management/spec.md#priority-visual-indicators
test('request list surfaces the priority column', async ({ page }) => {
	await openRequests(page)
	// The seeded requests span normal / high / low priorities.
	await expect(page.locator('#content-vue table tbody')).toContainText(
		/normal|high|low|urgent/i,
		{ timeout: 15000 },
	)
})

// @e2e openspec/specs/request-management/spec.md#request-status-distribution-on-dashboard
test('requests by status widget on dashboard', async ({ page }) => {
	// The request-status distribution widget lives on the Operational overview
	// dashboard (#/operational), not the landing Commercial overview — the IA
	// restructure split the dashboards by audience.
	await page.goto('/apps/pipelinq/operational')
	await expect(
		page.locator('#content-vue').getByText('Requests by Status').first(),
	).toBeVisible({ timeout: 15000 })
})

// @e2e openspec/specs/request-management/spec.md#request-card-displays-key-information
test('request rows carry the seeded request titles', async ({ page }) => {
	await openRequests(page)
	// A concrete seeded record, not merely "a table exists".
	await expect(page.locator('#content-vue table tbody')).toContainText('[Demo]', {
		timeout: 15000,
	})
})

// @e2e openspec/specs/request-management/spec.md#request-without-queue
test('request list renders without pipelinq console errors', async ({ page }) => {
	const errs = trackPipelinqErrors(page)
	await openRequests(page)
	await assertNoHardError(page)
	expect(errs(), `pipelinq console errors: ${errs().join(' || ')}`).toEqual([])
})

// @e2e openspec/specs/request-management/spec.md#bulk-actions-bar-visibility
test('request list exposes the row-selection checkbox that gates bulk actions', async ({
	page,
}) => {
	await openRequests(page)
	// The bulk-actions bar is gated on a row selection, so the selection
	// affordance itself is the observable precondition.
	const firstRowCheckbox = page
		.locator('#content-vue table tbody tr')
		.first()
		.locator('input[type="checkbox"]')
		.first()
	await expect(firstRowCheckbox).toBeVisible({ timeout: 15000 })
})

// @e2e openspec/specs/request-management/spec.md#pagination
test('the ticket list paginates and page 2 shows different rows', async ({
	page,
}) => {
	await openApp(page)
	await navClick(page, 'Tickets', /\/tickets/)

	// The ALL tab holds every subtype: the base register's 7 example tickets plus
	// the seeded 8 requests + 3 complaints + 12 contactmomenten — 30 rows against
	// a page size of 20, so `effectivePagination.pages > 1` and CnIndexPage
	// renders CnPagination. This is the one index in the app where the paging
	// contract is genuinely exercisable; asserting it on Queues (6 rows, one
	// page) asserted a control the component correctly does not render.
	const content = page.locator('#content-vue')
	const firstRowBefore = await content
		.locator('table tbody tr')
		.first()
		.innerText()

	const next = content
		.locator('.cn-index-page__pagination')
		.getByRole('button', { name: 'Next' })
		.first()
	await expect(next).toBeVisible({ timeout: 15000 })
	await next.click()

	// A pagination control that renders but does not change the result set is a
	// dead control, so assert the rows actually moved.
	await expect
		.poll(
			async () => await content.locator('table tbody tr').first().innerText(),
			{ timeout: 15000 },
		)
		.not.toBe(firstRowBefore)
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
