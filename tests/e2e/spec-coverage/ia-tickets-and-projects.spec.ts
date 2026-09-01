/*
 * SPDX-FileCopyrightText: 2026 Pipelinq Contributors
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Navigation changes: "Tickets" reads "All tickets", and "Projecten" is gone
 * from the menu because dossiq's workflow board covers that ground.
 *
 * The third test is the one that matters. `menu-layout.json` retires an entry
 * by id while promising its PAGE stays routable, so bookmarks, shared URLs and
 * existing e2e specs keep working. That promise is only worth anything if
 * something checks it: a removal that also broke the route would look identical
 * in the sidebar and only surface when a user opened an old link.
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

// @e2e openspec/specs/pipelinq-navigation/spec.md
test('Projecten is no longer offered in the navigation', async ({ page }) => {
	await openApp(page)

	const nav = page.locator('#app-navigation-vue, .app-navigation').first()
	await expect(
		nav.getByText(/^\s*(Projecten|Projects)\s*$/i),
		'the retired entry must be gone from the menu',
	).toHaveCount(0)
})

// @e2e openspec/specs/pipelinq-navigation/spec.md
test('the projects page stays reachable by direct link', async ({ page }) => {
	await page.goto('/apps/pipelinq/projects')
	await dismissWalkthrough(page)
	await dismissSupportDialog(page)

	// Retiring a MENU entry must not retire its ROUTE: menu-layout.json says so
	// explicitly, and old links and specs depend on it. A blank page here would
	// mean the removal took the route with it.
	await expect(
		page.locator('[data-testid="cn-page"]'),
		"the retired entry's page must still render for a deep link",
	).toBeVisible({ timeout: 20000 })
})
