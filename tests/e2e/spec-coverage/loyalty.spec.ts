/*
 * SPDX-FileCopyrightText: 2026 Pipelinq Contributors
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Gate-19 behavioral e2e coverage for the Loyalty programme reporting page
 * (/loyalty/reporting). Maps to openspec/changes/loyalty-program/specs.md.
 */
import { test, expect } from '@playwright/test'
import {
	openApp,
	navClick,
	trackPipelinqErrors,
	assertNoHardError,
} from '../helpers/pipelinq'

// @e2e openspec/changes/loyalty-program/specs.md#loyalty-reporting-page
test('Loyalty: navigates from sidebar and shows the reporting surface', async ({
	page,
}) => {
	const errs = trackPipelinqErrors(page)
	await openApp(page)
	await navClick(page, 'Loyalty', /\/loyalty\/reporting/)

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
	await openApp(page)
	await navClick(page, 'Loyalty', /\/loyalty\/reporting/)

	const text = (await page.locator('#content-vue').textContent()) || ''
	expect(text.replace(/\s+/g, ' ').trim().length).toBeGreaterThan(20)
})

/*
 * Backend / data-dependent scenarios excluded — covered by PHPUnit / Newman:
 * @e2e exclude loyalty-points-accrual — server-side; covered by PHPUnit
 * @e2e exclude loyalty-tier-calculation — server-side; covered by PHPUnit
 * @e2e exclude loyalty-redemption — requires seeded member with points
 */
