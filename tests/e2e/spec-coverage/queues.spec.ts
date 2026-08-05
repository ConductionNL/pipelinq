/*
 * SPDX-FileCopyrightText: 2026 Pipelinq Contributors
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Gate-19 behavioral e2e coverage for the Queues index page (/queues).
 * Maps to openspec/specs/queue-management/spec.md.
 */
import { test, expect } from '@playwright/test'
import { openApp, navClick, trackPipelinqErrors, assertNoHardError, dismissSupportDialog } from '../helpers/pipelinq'

// @e2e openspec/specs/queue-management/spec.md#queues-index
test('Queues: navigates from sidebar and shows index surface', async ({ page }) => {
	const errs = trackPipelinqErrors(page)
	await openApp(page)
	await navClick(page, 'Queues', /\/queues/)

	await expect(page.locator('#content-vue').getByRole('heading', { name: 'Queues' }).first()).toBeVisible()
	await expect(page.locator('[data-testid="cn-index-page"]').first()).toBeVisible()
	await assertNoHardError(page)
	expect(errs(), `pipelinq console errors: ${errs().join(' || ')}`).toEqual([])
})

// @e2e openspec/specs/queue-management/spec.md#queues-list-table
test('Queues: list table with pagination and primary actions render', async ({ page }) => {
	await openApp(page)
	await navClick(page, 'Queues', /\/queues/)

	const content = page.locator('#content-vue')
	await expect(content.getByRole('button', { name: 'Add Queue' })).toBeVisible()
	await expect(content.locator('table, .cn-data-table, [data-testid="cn-data-table"]').first()).toBeVisible()
	// Queues has seeded data → a pagination control is present.
	await expect(content.getByRole('button', { name: 'Next' }).first()).toBeVisible()
})

// @e2e openspec/specs/queue-management/spec.md#queues-create-modal
test('Queues: Add Queue opens a create modal with a form', async ({ page }) => {
	await openApp(page)
	await navClick(page, 'Queues', /\/queues/)
	await dismissSupportDialog(page)

	await page.locator('#content-vue').getByRole('button', { name: 'Add Queue' }).click()
	const modal = page.locator('.modal-container, [role="dialog"]').first()
	await expect(modal).toBeVisible({ timeout: 10000 })
	await expect(modal.locator('input, .input-field__input, textarea').first()).toBeVisible()
})

/*
 * Backend / data-dependent scenarios excluded — covered by PHPUnit / Newman:
 * @e2e exclude queue-routing-rules — server-side; covered by PHPUnit
 * @e2e exclude queue-detail-view — requires seeded record
 * @e2e exclude queue-walk-in-ticket — requires KCC werkplek flow
 * @e2e exclude queue-delete — requires existing record
 */
