/*
 * SPDX-FileCopyrightText: 2026 Pipelinq Contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 *
 * Gate-19 behavioral e2e coverage for the Forms (intake forms) index page
 * (/forms). Maps to openspec/specs/public-intake-forms/spec.md.
 */
import { test, expect } from '@playwright/test'
import { openApp, navClick, trackPipelinqErrors, assertNoHardError, dismissSupportDialog } from '../helpers/pipelinq'

// @e2e openspec/specs/public-intake-forms/spec.md#forms-index
test('Forms: navigates from sidebar and shows index surface', async ({ page }) => {
	const errs = trackPipelinqErrors(page)
	await openApp(page)
	await navClick(page, 'Forms', /\/forms/)

	await expect(page.locator('#content-vue').getByRole('heading', { name: 'Forms' }).first()).toBeVisible()
	await expect(page.locator('[data-testid="cn-index-page"]').first()).toBeVisible()
	await assertNoHardError(page)
	expect(errs(), `pipelinq console errors: ${errs().join(' || ')}`).toEqual([])
})

// @e2e openspec/specs/public-intake-forms/spec.md#forms-primary-action
test('Forms: primary "Add Intake Form" action and index chrome render', async ({ page }) => {
	await openApp(page)
	await navClick(page, 'Forms', /\/forms/)

	const content = page.locator('#content-vue')
	await expect(content.getByRole('button', { name: 'Add Intake Form' })).toBeVisible()
	await expect(content.getByRole('button', { name: 'Actions' }).first()).toBeVisible()
})

// @e2e openspec/specs/public-intake-forms/spec.md#forms-create-modal
test('Forms: Add Intake Form opens a create modal with a form', async ({ page }) => {
	await openApp(page)
	await navClick(page, 'Forms', /\/forms/)
	await dismissSupportDialog(page)

	await page.locator('#content-vue').getByRole('button', { name: 'Add Intake Form' }).click()
	const modal = page.locator('.modal-container, [role="dialog"]').first()
	await expect(modal).toBeVisible({ timeout: 10000 })
	await expect(modal.locator('input, .input-field__input, textarea').first()).toBeVisible()
})

/*
 * Backend / data-dependent scenarios excluded — covered by PHPUnit / Newman:
 * @e2e exclude public-form-submission — anonymous public endpoint; covered by Newman
 * @e2e exclude form-to-request-conversion — server-side; covered by PHPUnit
 * @e2e exclude form-detail-view — requires seeded record
 * @e2e exclude form-delete — requires existing record
 */
