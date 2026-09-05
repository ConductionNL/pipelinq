/*
 * SPDX-FileCopyrightText: 2026 Pipelinq Contributors
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Gate-19 behavioral e2e coverage for the Forecast report page (/forecast).
 * Maps to openspec/changes/forecast-roll-up-and-categories/specs.md.
 *
 * THE ROUTE IN CHANGED, THE CLAIM DID NOT. Forecast used to be a sidebar leaf
 * under Sales, so both tests reached it with `navClick(page, 'Forecast', ...)`.
 * It is a report, and ADR-112 puts every report on one Reports page of cards,
 * so `src/menu-layout.json#removals` retires the sidebar entry and the card is
 * the way in. What these tests assert is unchanged and must stay unchanged: a
 * user who has not memorised a URL can still open the forecast.
 */
import type { Page } from '@playwright/test'

import { expect, test } from '@playwright/test'
import {
	assertNoHardError,
	openApp,
	trackPipelinqErrors,
} from '../helpers/pipelinq.ts'

// The 30s default does not cover this route any more. Each test boots the shell
// through openApp(), dismisses the walkthrough and support dialogs, then loads
// the Reports page, which CnPageRenderer maps through defineAsyncComponent and
// therefore fetches as its own chunk. rapportage.spec.ts hit the same wall and
// budgets 180s for the same three steps; a bare timeout there read as "the page
// is broken" when it only meant "this test ran out of time".
test.describe.configure({ timeout: 180_000 })

/**
 * Open the forecast the way a user does: through the Reports page.
 *
 * @param page The page under test.
 */
async function openForecast(page: Page) {
	await openApp(page)
	await page.goto('/apps/pipelinq/reports')

	await page
		.getByTestId('cn-report-card')
		.filter({ hasText: /Forecast/i })
		.first()
		.click()

	await expect(page).toHaveURL(/\/forecast/, { timeout: 10000 })
}

// @e2e openspec/changes/forecast-roll-up-and-categories/specs.md#forecast-page
test('Forecast: opens from the reports page and shows the forecast surface', async ({
	page,
}) => {
	const errs = trackPipelinqErrors(page)
	await openForecast(page)

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
	await openForecast(page)

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
