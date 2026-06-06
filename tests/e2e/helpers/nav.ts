/*
 * SPDX-FileCopyrightText: 2026 Pipelinq Contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 *
 * Navigation helpers for the manifest-driven Pipelinq app shell.
 *
 * Why this exists (issue #392): Pipelinq now renders inside the
 * nextcloud-vue CnAppRoot manifest shell. A full-page navigation
 * (`page.goto('/apps/pipelinq/clients')`) lands on the shell entrypoint
 * and the client-side router resets to the Dashboard — so deep links can
 * NOT be reached with `goto`. Views must be opened by clicking the
 * in-app left-navigation link, which performs client-side routing.
 *
 * Reloading the shell before each click also resets the shared list
 * component. Without the reload, visiting two list views in one session
 * bleeds state and every "Add <Entity>" button / "Create <Entity>" modal
 * collapses to the first-visited entity's label.
 */
import { Page, expect } from '@playwright/test'

export const APP_ROOT = '/index.php/apps/pipelinq/'

/**
 * Open the Pipelinq Dashboard (the app-shell entrypoint) from a clean
 * page load and wait for it to render.
 */
export async function openApp(page: Page): Promise<void> {
	// `domcontentloaded` (not the default `load`): the dashboard fires
	// background XHRs whose widgets spin on "Loading…" indefinitely, which
	// keeps the slow dev instance from settling the `load` event in time.
	await page.goto(APP_ROOT, { waitUntil: 'domcontentloaded', timeout: 60000 })
	await expect(
		page.getByRole('heading', { name: 'Dashboard', level: 2 }),
	).toBeVisible({ timeout: 30000 })
}

/**
 * Open a Pipelinq detail view by deep-linking to its manifest route.
 *
 * Detail routes (`/clients/:id`, `/leads/:id`, …) are registered in the
 * vue-router built from the manifest, and Nextcloud serves the app shell
 * for any sub-path, so the router boots straight onto the detail page.
 * The OpenRegister object endpoint may 500 / return nothing for an
 * unseeded id — that is expected. The detail component (CnDetailPage)
 * still renders its shell (header + "Back to list" button + a fallback
 * title), so the assertion targets the rendered chrome, NOT data rows.
 *
 * @param page   the Playwright page
 * @param route  the detail route slug, e.g. `leads`, `clients`
 * @param id     the object id segment (any value — shell renders regardless)
 */
export async function openDetail(
	page: Page,
	route: string,
	id = '1',
): Promise<void> {
	await page.goto(`${APP_ROOT}${route}/${id}`, {
		waitUntil: 'domcontentloaded',
		timeout: 60000,
	})
	// The CnDetailPage shell renders for every load/error/empty state.
	await expect(
		page.locator('[data-testid="cn-detail-page"]'),
	).toBeVisible({ timeout: 30000 })
}

/**
 * Open a Pipelinq sub-view by reloading the shell and clicking its
 * left-nav link, then assert the view's level-2 heading is visible.
 *
 * @param route    the route slug, e.g. `clients`, `leads`, `my-work`
 * @param heading  the expected `<h2>` text of the view
 */
export async function openView(
	page: Page,
	route: string,
	heading: string | RegExp,
): Promise<void> {
	await openApp(page)
	await page.locator(`a[href="/apps/pipelinq/${route}"]`).first().click()
	await expect(page).toHaveURL(new RegExp(`/${route.replace(/[/?]/g, '\\$&')}`))
	await expect(
		page.getByRole('heading', { name: heading, level: 2 }).first(),
	).toBeVisible({ timeout: 20000 })
}
