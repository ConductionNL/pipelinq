/*
 * SPDX-FileCopyrightText: 2026 Pipelinq Contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 *
 * Gate-19 behavioral e2e coverage for the Surveys index page (/surveys).
 * Maps to openspec/changes/reverse-2026-05-26-be-public-survey/specs/.
 */
import { test, expect } from '@playwright/test'
import { openApp, navClick, trackPipelinqErrors, assertNoHardError, dismissSupportDialog } from '../helpers/pipelinq'

// @e2e openspec/changes/reverse-2026-05-26-be-public-survey/specs.md#surveys-index
test('Surveys: navigates from sidebar and shows index surface', async ({ page }) => {
	const errs = trackPipelinqErrors(page)
	await openApp(page)
	await navClick(page, 'Surveys', /\/surveys/)

	await expect(page.locator('#content-vue').getByRole('heading', { name: 'Surveys' }).first()).toBeVisible()
	await expect(page.locator('[data-testid="cn-index-page"]').first()).toBeVisible()
	await assertNoHardError(page)
	expect(errs(), `pipelinq console errors: ${errs().join(' || ')}`).toEqual([])
})

// @e2e openspec/changes/reverse-2026-05-26-be-public-survey/specs.md#surveys-primary-action
test('Surveys: primary "Add Survey" action and index chrome render', async ({ page }) => {
	await openApp(page)
	await navClick(page, 'Surveys', /\/surveys/)

	const content = page.locator('#content-vue')
	await expect(content.getByRole('button', { name: 'Add Survey' })).toBeVisible()
	await expect(content.getByRole('button', { name: 'Actions' }).first()).toBeVisible()
})

// @e2e openspec/changes/reverse-2026-05-26-be-public-survey/specs.md#surveys-create-modal
test('Surveys: Add Survey opens a create modal with a form', async ({ page }) => {
	await openApp(page)
	await navClick(page, 'Surveys', /\/surveys/)
	await dismissSupportDialog(page)

	await page.locator('#content-vue').getByRole('button', { name: 'Add Survey' }).click()
	const modal = page.locator('.modal-container, [role="dialog"]').first()
	await expect(modal).toBeVisible({ timeout: 10000 })
	await expect(modal.locator('input, .input-field__input, textarea').first()).toBeVisible()
})

/*
 * Backend / data-dependent scenarios excluded — covered by PHPUnit / Newman:
 * @e2e exclude public-survey-submission — anonymous public endpoint; covered by Newman
 * @e2e exclude survey-response-aggregation — server-side; covered by PHPUnit
 * @e2e exclude survey-detail-view — requires seeded record
 * @e2e exclude survey-delete — requires existing record
 */
