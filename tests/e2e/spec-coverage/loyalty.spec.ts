/*
 * SPDX-FileCopyrightText: 2026 Pipelinq Contributors
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Gate-19 behavioral e2e coverage for the Loyalty programme reporting page
 * (/loyalty/reporting). Maps to openspec/changes/loyalty-program/specs.md.
 *
 * THE ROUTE IN CHANGED, THE CLAIM DID NOT. Loyalty used to be a sidebar leaf
 * under Sales, so both tests reached it with `navClick(page, 'Loyalty', ...)`.
 * It is a report, and ADR-112 puts every report on one Reports page of cards,
 * so `src/menu-layout.json#removals` retires the sidebar entry and the card is
 * the way in. What these tests assert is unchanged and must stay unchanged: a
 * user who has not memorised a URL can still open the loyalty report.
 */
import type { Page } from '@playwright/test'

import { expect, test } from '@playwright/test'
import {
	assertNoHardError,
	gotoAppRoute,
	trackPipelinqErrors,
} from '../helpers/pipelinq.ts'

// See the same note in forecast.spec.ts. The raise was taken for openApp()
// plus the lazily-chunked Reports page; the openApp() load is gone, so what is
// left is headroom. Kept rather than tuned, for want of a measurement.
test.describe.configure({ timeout: 180_000 })

/**
 * Open the loyalty report the way a user does: through the Reports page.
 *
 * One load, not two. openApp() used to stand here and the next line navigated
 * straight off the Dashboard it had just booted; the Dashboard is never
 * asserted against in this file.
 *
 * @param page The page under test.
 */
async function openLoyaltyReporting(page: Page) {
	await gotoAppRoute(page, '/reports')

	await page
		.getByTestId('cn-report-card')
		.filter({ hasText: /Loyalty reporting/i })
		.first()
		.click()

	await expect(page).toHaveURL(/\/loyalty\/reporting/, { timeout: 10000 })
}

// @e2e openspec/changes/loyalty-program/specs.md#loyalty-reporting-page
test('Loyalty: opens from the reports page and shows the reporting surface', async ({
	page,
}) => {
	const errs = trackPipelinqErrors(page)
	await openLoyaltyReporting(page)

	await expect(
		page
			.locator('#content-vue')
			.getByRole('heading', { name: 'Loyalty programme reporting' })
			.first(),
	).toBeVisible()
	await assertNoHardError(page)
	expect(errs(), `pipelinq console errors: ${errs().join(' || ')}`).toEqual([])
})

// @e2e openspec/changes/loyalty-program/specs.md#loyalty-reporting-content
test('Loyalty: reporting content area renders below the heading', async ({
	page,
}) => {
	await openLoyaltyReporting(page)

	const text = (await page.locator('#content-vue').textContent()) || ''
	expect(text.replace(/\s+/g, ' ').trim().length).toBeGreaterThan(20)
})

/*
 * Backend / data-dependent scenarios excluded — covered by PHPUnit / Newman:
 * @e2e exclude loyalty-points-accrual — server-side; covered by PHPUnit
 * @e2e exclude loyalty-tier-calculation — server-side; covered by PHPUnit
 * @e2e exclude loyalty-redemption — requires seeded member with points
 */
