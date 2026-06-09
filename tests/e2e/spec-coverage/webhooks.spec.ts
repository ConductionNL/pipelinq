/*
 * SPDX-FileCopyrightText: 2026 Pipelinq Contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 *
 * Gate-19 behavioral e2e coverage for the Webhooks page (/webhooks).
 * Maps to openspec/changes/marketing-segmentation-and-blast-05-jobs-and-webhooks/specs.md.
 */
import { test, expect } from '@playwright/test'
import { openApp, navClick, trackPipelinqErrors, assertNoHardError } from '../helpers/pipelinq'

// @e2e openspec/changes/marketing-segmentation-and-blast-05-jobs-and-webhooks/specs.md#webhooks-page
test('Webhooks: navigates from sidebar and shows the webhooks surface', async ({ page }) => {
	const errs = trackPipelinqErrors(page)
	await openApp(page)
	await navClick(page, 'Webhooks', /\/webhooks/)

	await expect(page.locator('#content-vue').getByRole('heading', { name: 'Webhooks' }).first()).toBeVisible()
	await assertNoHardError(page)
	expect(errs(), `pipelinq console errors: ${errs().join(' || ')}`).toEqual([])
})

// @e2e openspec/changes/marketing-segmentation-and-blast-05-jobs-and-webhooks/specs.md#webhooks-content
test('Webhooks: content area renders below the heading', async ({ page }) => {
	await openApp(page)
	await navClick(page, 'Webhooks', /\/webhooks/)

	const text = (await page.locator('#content-vue').textContent()) || ''
	expect(text.replace(/\s+/g, ' ').trim().length).toBeGreaterThan(20)
})

/*
 * Backend / data-dependent scenarios excluded — covered by PHPUnit / Newman:
 * @e2e exclude webhook-delivery-retry — BackgroundJob; covered by PHPUnit
 * @e2e exclude webhook-signature-verification — server-side; covered by PHPUnit
 * @e2e exclude webhook-create — requires form interaction + endpoint config
 */
