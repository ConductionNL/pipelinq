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
import { test } from '@playwright/test'
import { shootByNav, shootSurface } from './_visual-helpers.ts'

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
})
