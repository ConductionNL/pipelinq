/*
 * SPDX-FileCopyrightText: 2026 Pipelinq Contributors
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Gate-19 behavioral e2e coverage for the Contacts index page (/contacts).
 * Maps to openspec/specs/contacts-sync/spec.md.
 *
 * Drives the page through the real UI: sidebar nav-click, index surface,
 * primary action button, table/search/columns chrome, and the "Add Contact"
 * modal. Asserts no pipelinq-origin console error and no hard server error.
 */
import { test, expect } from '@playwright/test'
import { openApp, navClick, trackPipelinqErrors, assertNoHardError, dismissSupportDialog } from '../helpers/pipelinq'

// @e2e openspec/specs/contacts-sync/spec.md#contacts-index
test('Contacts: navigates from sidebar and shows index surface', async ({ page }) => {
	const errs = trackPipelinqErrors(page)
	await openApp(page)
	await navClick(page, 'Contacts', /\/contacts/)

	await expect(page.locator('#content-vue').getByRole('heading', { name: 'Contacts' }).first()).toBeVisible()
	await expect(page.locator('[data-testid="cn-index-page"]').first()).toBeVisible()
	await assertNoHardError(page)
	expect(errs(), `pipelinq console errors: ${errs().join(' || ')}`).toEqual([])
})

// @e2e openspec/specs/contacts-sync/spec.md#contacts-list-table
test('Contacts: list table and primary actions render', async ({ page }) => {
	await openApp(page)
	await navClick(page, 'Contacts', /\/contacts/)

	const content = page.locator('#content-vue')
	await expect(content.getByRole('button', { name: 'Add Contact' })).toBeVisible()
	await expect(content.locator('table, .cn-data-table, [data-testid="cn-data-table"]').first()).toBeVisible()
	await expect(content.getByRole('button', { name: 'Actions' }).first()).toBeVisible()
})

// @e2e openspec/specs/contacts-sync/spec.md#contacts-create-modal
test('Contacts: Add Contact opens a create modal with a form', async ({ page }) => {
	await openApp(page)
	await navClick(page, 'Contacts', /\/contacts/)
	await dismissSupportDialog(page)

	await page.locator('#content-vue').getByRole('button', { name: 'Add Contact' }).click()
	const modal = page.locator('.modal-container, [role="dialog"]').first()
	await expect(modal).toBeVisible({ timeout: 10000 })
	await expect(modal.locator('input, .input-field__input, textarea').first()).toBeVisible()
})

/*
 * Backend / data-dependent scenarios excluded — covered by PHPUnit / Newman:
 * @e2e exclude contacts-sync-from-addressbook — OCP\Contacts integration; covered by PHPUnit ContactSyncServiceTest
 * @e2e exclude contacts-detail-view — requires seeded contact record
 * @e2e exclude contacts-edit — requires existing record
 * @e2e exclude contacts-delete — requires existing record
 * @e2e exclude contacts-search-filter — requires seeded data
 */
