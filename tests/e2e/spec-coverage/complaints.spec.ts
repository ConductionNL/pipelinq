/*
 * SPDX-FileCopyrightText: 2026 Pipelinq Contributors
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Gate-19 behavioral e2e coverage for the Complaints index page (/complaints).
 * Maps to openspec/specs/klachtenregistratie/spec.md.
 */
import { test, expect } from '@playwright/test'
import { openApp, navClick, trackPipelinqErrors, assertNoHardError, dismissSupportDialog } from '../helpers/pipelinq'

// @e2e openspec/specs/klachtenregistratie/spec.md#complaints-index
test('Complaints: navigates from sidebar and shows index surface', async ({ page }) => {
	const errs = trackPipelinqErrors(page)
	await openApp(page)
	await navClick(page, 'Complaints', /\/complaints/)

	await expect(page.locator('#content-vue').getByRole('heading', { name: 'Complaints' }).first()).toBeVisible()
	await expect(page.locator('[data-testid="cn-index-page"]').first()).toBeVisible()
	await assertNoHardError(page)
	expect(errs(), `pipelinq console errors: ${errs().join(' || ')}`).toEqual([])
})

// @e2e openspec/specs/klachtenregistratie/spec.md#complaints-list-table
test('Complaints: list table and primary actions render', async ({ page }) => {
	await openApp(page)
	await navClick(page, 'Complaints', /\/complaints/)

	const content = page.locator('#content-vue')
	await expect(content.getByRole('button', { name: 'Add Complaint' })).toBeVisible()
	await expect(content.locator('table, .cn-data-table, [data-testid="cn-data-table"]').first()).toBeVisible()
})

// @e2e openspec/specs/klachtenregistratie/spec.md#complaints-create-modal
test('Complaints: Add Complaint opens a create modal with a form', async ({ page }) => {
	await openApp(page)
	await navClick(page, 'Complaints', /\/complaints/)
	await dismissSupportDialog(page)

	await page.locator('#content-vue').getByRole('button', { name: 'Add Complaint' }).click()
	const modal = page.locator('.modal-container, [role="dialog"]').first()
	await expect(modal).toBeVisible({ timeout: 10000 })
	await expect(modal.locator('input, .input-field__input, textarea').first()).toBeVisible()
})

/*
 * Backend / data-dependent scenarios excluded — covered by PHPUnit / Newman:
 * @e2e exclude complaint-sla-escalation — BackgroundJob; covered by PHPUnit
 * @e2e exclude complaint-detail-view — requires seeded record
 * @e2e exclude complaint-status-transition — requires existing record
 * @e2e exclude complaint-edit — requires existing record
 * @e2e exclude complaint-delete — requires existing record
 */
