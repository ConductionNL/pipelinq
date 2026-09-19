/*
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Visual-regression baselines for PipelinQ's key surfaces (GAP-5).
 *
 * Run:    npx playwright test --project visual
 * Update: npx playwright test --project visual --update-snapshots
 *
 * Baselines live in tests/e2e/visual/<spec>-snapshots/ and ARE committed.
 * See _visual-helpers.ts for the platform-rendering caveat.
 */
import { expect, test } from '@playwright/test'
import {
	dismissSupportDialog,
	dynamicMasks,
	freezePage,
	shootByNav,
	shootSurface,
	SHOT_OPTIONS,
	waitForContentReady,
} from './_visual-helpers.ts'

const APP = '/index.php/apps/pipelinq'

test.describe('PipelinQ — visual baselines', () => {
	test('dashboard', async ({ page }) => {
		await shootSurface(page, `${APP}/`, 'dashboard.png')
	})

	test('clients list', async ({ page }) => {
		await shootByNav(page, `${APP}/`, 'Clients', 'clients.png')
	})

	/*
	 * The store, deliberately shot with NO registry configured.
	 *
	 * That is the state the page is in on a fresh install and the one worth
	 * a baseline: the engine answers `not_configured` without a network call,
	 * so the shot is deterministic, and it covers the built-in template grid
	 * plus the note explaining why nothing was fetched.
	 *
	 * Shot by URL rather than by nav click because the entry lives in the
	 * FOOTER section, outside the scrollable nav that `shootByNav` clicks in.
	 */
	test('Store page (StoreGallery)', async ({ page }) => {
		await shootSurface(page, `${APP}/store`, 'store.png')
	})

	/*
	 * Features & roadmap, shot on its comparison section rather than its
	 * product one.
	 *
	 * The product section is the library's own page and the library owns its
	 * appearance. What belongs to this repo is FeaturesRoadmapView's second
	 * section: the caveat panel, the totals table and the twelve areas. That
	 * is a dense, wide, table-heavy surface where a CSS change lands quietly,
	 * which is exactly what a pixel baseline is for.
	 *
	 * Shot by URL, like the Store above, because the entry lives in the FOOTER
	 * section, outside the scrollable nav that `shootByNav` clicks in.
	 */
	test('Features & roadmap comparison (FeaturesRoadmapView)', async ({ page }) => {
		// Not shootSurface: that shoots where it lands, and this page lands on
		// its product section. The comparison needs one click first, so the
		// same four steps are spelled out here rather than given the helper a
		// parameter its two other callers would never pass.
		await page.goto(`${APP}/features-roadmap`, {
			waitUntil: 'domcontentloaded',
		})
		await dismissSupportDialog(page)
		await waitForContentReady(page)
		await page
			.locator('#content-vue')
			.getByRole('button', { name: 'How pipelinq compares' })
			.click()
		await freezePage(page)
		await expect(page).toHaveScreenshot('features-roadmap.png', {
			...SHOT_OPTIONS,
			mask: dynamicMasks(page),
		})
	})
})
