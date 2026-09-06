/*
 * SPDX-FileCopyrightText: 2026 Pipelinq Contributors
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Navigation changes: "Tickets" reads "All tickets", and "Projecten" is gone
 * from the menu because planninq owns the project entity.
 *
 * That last claim has been reversed twice, so it is worth writing down which
 * way round it now is. #1672 retired the entry on the theory that dossiq's
 * workflow board replaced it; #1728 put it back, because a board does not
 * replace a billing entity; #1757 removed it again for a different and better
 * reason, handing the whole work breakdown structure to planninq, deleting
 * pipelinq's four project schemas and its three project pages.
 *
 * So the surface is not lost, it moved: a client's projects still render on
 * ClientDetail, through a widget that reads planninq's register and declares
 * `requiredApp: planninq`. The menu half of that claim is asserted here; the
 * client-page half is asserted in declarative-view-system.spec.ts, which
 * already pins a seeded client deterministically.
 */

import type { Page } from '@playwright/test'

import { expect, test } from '@playwright/test'
import { dismissSupportDialog, dismissWalkthrough } from '../helpers/pipelinq.ts'

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

// @e2e openspec/specs/pipelinq-navigation/spec.md
test("Projecten is not offered in pipelinq's navigation", async ({ page }) => {
	// INVERTED for the second time, and the reason matters more than the
	// assertion. This read "the billing surface must stay in the menu" after
	// #1728, which was right against the claim it answered: a planning board
	// does not replace a billing entity. #1757 answered a different question.
	// Planninq already owned `project`, `task` and `timeEntry`, and a schema
	// slug is GLOBAL on a shared OpenRegister, so pipelinq's parallel
	// definitions could answer for planninq's without anything reporting it.
	// Pipelinq gave its four project schemas up rather than keep a second
	// definition of the same word.
	//
	// So this is not the earlier mistake repeating. It is kept as an assertion
	// rather than deleted because an entry restored by accident is as much a
	// regression as one removed by accident, and this app has now done both.
	await openApp(page)

	const nav = page.locator('#app-navigation-vue, .app-navigation').first()
	await expect(
		nav.getByText(/^\s*(Projecten|Projects)\s*$/i),
		'planninq owns projects, so pipelinq must not offer them in its menu',
	).toHaveCount(0, { timeout: 15000 })
})
