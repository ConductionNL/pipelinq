/*
 * SPDX-FileCopyrightText: 2026 Pipelinq Contributors
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Gate-19 behavioral e2e coverage for the Products index page (/products).
 * Maps to openspec/specs/product-service-catalog/spec.md.
 */
import { test, expect } from '@playwright/test'
import { openApp, navClick, trackPipelinqErrors, assertNoHardError, dismissSupportDialog } from '../helpers/pipelinq'

// @e2e openspec/specs/product-service-catalog/spec.md#products-index
test('Products: navigates from sidebar and shows index surface', async ({ page }) => {
	const errs = trackPipelinqErrors(page)
	await openApp(page)
	await navClick(page, 'Products', /\/products/)

	await expect(page.locator('#content-vue').getByRole('heading', { name: 'Products' }).first()).toBeVisible()
	await expect(page.locator('[data-testid="cn-index-page"]').first()).toBeVisible()
	await assertNoHardError(page)
	expect(errs(), `pipelinq console errors: ${errs().join(' || ')}`).toEqual([])
})

// @e2e openspec/specs/product-service-catalog/spec.md#products-list-table
test('Products: list table and primary actions render', async ({ page }) => {
	await openApp(page)
	await navClick(page, 'Products', /\/products/)

	const content = page.locator('#content-vue')
	await expect(content.getByRole('button', { name: 'Add Product' })).toBeVisible()
	await expect(content.locator('table, .cn-data-table, [data-testid="cn-data-table"]').first()).toBeVisible()
})

// @e2e openspec/specs/product-service-catalog/spec.md#products-create-modal
test('Products: Add Product opens a create modal with a form', async ({ page }) => {
	await openApp(page)
	await navClick(page, 'Products', /\/products/)
	await dismissSupportDialog(page)

	await page.locator('#content-vue').getByRole('button', { name: 'Add Product' }).click()
	const modal = page.locator('.modal-container, [role="dialog"]').first()
	await expect(modal).toBeVisible({ timeout: 10000 })
	await expect(modal.locator('input, .input-field__input, textarea').first()).toBeVisible()
})

/*
 * Backend / data-dependent scenarios excluded — covered by PHPUnit / Newman:
 * @e2e exclude product-price-calculation — server-side; covered by PHPUnit
 * @e2e exclude product-detail-view — requires seeded record
 * @e2e exclude product-edit — requires existing record
 * @e2e exclude product-delete — requires existing record
 */
