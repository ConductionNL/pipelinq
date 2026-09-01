/*
 * SPDX-FileCopyrightText: 2026 Pipelinq Contributors
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Gate-19 behavioral e2e coverage for klachtenregistratie
 * (openspec/specs/klachtenregistratie/spec.md).
 *
 * RETARGETED 2026-08-06 — there is no `/complaints` page and no "Complaints"
 * sidebar entry any more. The `unify-ticket-supertype` change replaced the
 * separate Requests / Complaints / Contactmomenten pages with ONE index over
 * the `ticket` schema (src/manifest.json, page id `Tickets`, route `/tickets`),
 * and each former page is now a `quickFilters[]` tab narrowing that same list
 * on the `ticketType` discriminator.
 *
 * The old spec navigated to a sidebar entry labelled "Complaints" and waited
 * 10s for it to attach. Nothing renders that label, so all three tests failed
 * inside the navigation helper — which read as a helper bug rather than as the
 * surface having MOVED. These tests drive the surface that exists.
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

// @e2e openspec/specs/klachtenregistratie/spec.md#complaints-index
test('Complaints: reachable as a ticket-type tab on the Tickets workspace', async ({
	page,
}) => {
	const errs = trackPipelinqErrors(page)
	await openApp(page)
	await navClick(page, 'All tickets', /\/tickets/)

	await expect(
		page
			.locator('#content-vue')
			.getByRole('heading', { name: 'Tickets' })
			.first(),
	).toBeVisible()
	await expect(page.locator('[data-testid="cn-index-page"]').first()).toBeVisible()

	// The complaint subtype is a first-class tab on the shared index.
	await clickQuickFilter(page, 'Complaints')

	await assertNoHardError(page)
	expect(errs(), `pipelinq console errors: ${errs().join(' || ')}`).toEqual([])
})

// @e2e openspec/specs/klachtenregistratie/spec.md#complaints-list-table
test('Complaints: the tab narrows the list to complaint tickets', async ({
	page,
}) => {
	await openApp(page)
	await navClick(page, 'All tickets', /\/tickets/)
	await clickQuickFilter(page, 'Complaints')

	const content = page.locator('#content-vue')
	await expect(content.getByRole('button', { name: 'Add Ticket' })).toBeVisible()
	await expect(
		content
			.locator('table, .cn-data-table, [data-testid="cn-data-table"]')
			.first(),
	).toBeVisible()

	// The demo seed writes three complaint tickets (lib/Settings/demo_seed_data.json
	// `complaints`), so the filtered list is genuinely populated — and it is the
	// FILTER that is under test here, not merely that a table exists: the
	// ticketType column is first in the manifest, so every visible cell in it
	// must read "complaint".
	// Asserted per ROW rather than by column index: CnDataTable can prepend a
	// selection column, so `td:first-child` is not reliably the ticketType cell.
	//
	// NOT asserted positionally. This loop used to iterate rows.nth(i) and
	// require every one to match, which couples the test to how many rows fit
	// on page 1 and in what order they arrive. It failed at a DIFFERENT index
	// on each run — the signature of an order-dependent assertion rather than a
	// broken filter. See #1441.
	const rows = content.locator('table tbody tr')
	await expect(rows.first()).toBeVisible()
	const count = await rows.count()
	expect(
		count,
		'the Complaints tab must show at least one seeded complaint',
	).toBeGreaterThan(0)

	// `expect(locator)` rather than `expect(await locator.count())`: the former
	// RETRIES until the timeout, the latter takes a single snapshot. Clicking
	// the tab starts a fetch, so a snapshot taken before it lands measures the
	// unfiltered list.
	await expect(
		rows.filter({ hasText: /complaint/i }).first(),
		'the Complaints tab must show at least one row of the complaint subtype',
	).toBeVisible({ timeout: 15000 })

	await expect(
		rows.filter({ hasText: /request|interaction/i }),
		'the Complaints tab filters ticketType=complaint, so no request or '
			+ 'interaction row may appear',
	).toHaveCount(0, { timeout: 15000 })
})

// @e2e openspec/specs/klachtenregistratie/spec.md#complaints-create-modal
test('Complaints: Add Ticket opens a create modal with a form', async ({ page }) => {
	await openApp(page)
	await navClick(page, 'All tickets', /\/tickets/)
	await clickQuickFilter(page, 'Complaints')
	await dismissSupportDialog(page)

	await page
		.locator('#content-vue')
		.getByRole('button', { name: 'Add Ticket' })
		.click()
	const modal = page.locator('.modal-container, [role="dialog"]').first()
	await expect(modal).toBeVisible({ timeout: 10000 })
	await expect(
		modal.locator('input, .input-field__input, textarea').first(),
	).toBeVisible()
})

/*
 * Backend / data-dependent scenarios excluded — covered by PHPUnit / Newman:
 * @e2e exclude complaint-sla-escalation — BackgroundJob; covered by PHPUnit
 * @e2e exclude complaint-detail-view — requires seeded record
 * @e2e exclude complaint-status-transition — requires existing record
 * @e2e exclude complaint-edit — requires existing record
 * @e2e exclude complaint-delete — requires existing record
 */
