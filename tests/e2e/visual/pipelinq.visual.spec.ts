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
})
