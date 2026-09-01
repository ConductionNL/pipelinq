/*
 * SPDX-FileCopyrightText: 2026 Pipelinq Contributors
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Gate-19 behavioral e2e coverage for the Forecast report page (/forecast).
 * Maps to openspec/changes/forecast-roll-up-and-categories/specs.md.
 */
import { expect, test } from '@playwright/test'
import {
	assertNoHardError,
	navClick,
	openApp,
	trackPipelinqErrors,
} from '../helpers/pipelinq.ts'

// @e2e openspec/changes/forecast-roll-up-and-categories/specs.md#forecast-page
test('Forecast: navigates from sidebar and shows the forecast surface', async ({
	page,
}) => {
	const errs = trackPipelinqErrors(page)
	await openApp(page)
	await navClick(page, 'Forecast', /\/forecast/)

	await expect(
		page
			.locator('#content-vue')
			.getByRole('heading', { name: 'Forecast' })
			.first(),
	).toBeVisible()
	await assertNoHardError(page)
	expect(errs(), `pipelinq console errors: ${errs().join(' || ')}`).toEqual([])
})

// @e2e openspec/changes/forecast-roll-up-and-categories/specs.md#forecast-export
test('Forecast: exposes the Export CSV action', async ({ page }) => {
	await openApp(page)
	await navClick(page, 'Forecast', /\/forecast/)

	await expect(
		page.locator('#content-vue').getByRole('button', { name: 'Export CSV' }),
	).toBeVisible()
})

/*
 * Backend / data-dependent scenarios excluded — covered by PHPUnit / Newman:
 * @e2e exclude forecast-roll-up-calculation — server-side aggregation; covered by PHPUnit
 * @e2e exclude forecast-category-breakdown — requires seeded deals across categories
 * @e2e exclude forecast-csv-content — file content asserted in service unit test
 */
