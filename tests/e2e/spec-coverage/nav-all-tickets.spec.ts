/*
 * SPDX-FileCopyrightText: 2026 Pipelinq Contributors
 * SPDX-License-Identifier: EUPL-1.2
 *
 * "Tickets" reads "All tickets".
 *
 * Projecten was going to be retired here too, on the reasoning that dossiq's
 * workflow board covers the same ground. It is not, and the reason is worth
 * recording: `project` is not a planning surface in pipelinq, it is a BILLING
 * entity carrying billable / budgetHours / hourlyRate / ledgerSync, and
 * sixteen files hang off it — ShillinqWipService, TimeBillingHandoffService,
 * LedgerController, ShillinqApService and two listeners. dossiq's board plans
 * work; it does not carry hourly rates. Retiring the menu entry would also
 * have tripped gate-53, which enforces ADR-044: a removal must not orphan a
 * route.
 */

import { test, expect, type Page } from '@playwright/test'

import { dismissSupportDialog, dismissWalkthrough } from '../helpers/pipelinq'

test.beforeEach(() => {
	test.slow()
})

/** Open the app and settle the first-run dialogs. */
async function openApp(page: Page) {
	await page.goto('/apps/pipelinq/')
	await dismissWalkthrough(page)
	await dismissSupportDialog(page)
	await expect(
		page.locator('#app-navigation-vue, .app-navigation').first(),
	).toBeVisible({
		timeout: 20000,
	})
}

// @e2e openspec/specs/pipelinq-navigation/spec.md
test('the tickets entry reads "All tickets"', async ({ page }) => {
	await openApp(page)

	const nav = page.locator('#app-navigation-vue, .app-navigation').first()

	// Presence, not visibility. The entry lives inside the collapsible
	// "Customer Support" group, so it is rendered but hidden until the group is
	// expanded — asserting visibility fails on a correctly renamed entry, which
	// is exactly what happened the first time this ran.
	await expect(
		nav.getByText(/^\s*(All tickets|Alle tickets)\s*$/i),
		'the renamed entry must be in the navigation',
	).toHaveCount(1, { timeout: 15000 })

	// And the old label must be gone, so a half-applied rename fails here
	// rather than leaving both entries in the tree.
	await expect(
		nav.getByText(/^\s*Tickets\s*$/),
		'the old label must not survive alongside the new one',
	).toHaveCount(0)
})
