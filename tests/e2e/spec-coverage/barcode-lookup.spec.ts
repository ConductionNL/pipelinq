/*
 * SPDX-FileCopyrightText: 2026 Pipelinq Contributors
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Gate-19 behavioral e2e coverage for the Barcode lookup page
 * (/products-barcode). Maps to openspec/specs/pos-barcode-scan/spec.md.
 */
import { test, expect } from '@playwright/test'
import { openApp, navClick, trackPipelinqErrors, assertNoHardError } from '../helpers/pipelinq'

// @e2e openspec/specs/pos-barcode-scan/spec.md#barcode-lookup-page
test('Barcode lookup: navigates from sidebar and shows the lookup surface', async ({ page }) => {
	const errs = trackPipelinqErrors(page)
	await openApp(page)
	await navClick(page, 'Barcode lookup', /\/products-barcode/)

	await expect(page.locator('#content-vue').getByRole('heading', { name: 'Barcode lookup' }).first()).toBeVisible()
	await assertNoHardError(page)
	expect(errs(), `pipelinq console errors: ${errs().join(' || ')}`).toEqual([])
})

// @e2e openspec/specs/pos-barcode-scan/spec.md#barcode-lookup-input
test('Barcode lookup: exposes a barcode entry input', async ({ page }) => {
	await openApp(page)
	await navClick(page, 'Barcode lookup', /\/products-barcode/)

	// The lookup view renders a scan/search input for the barcode value.
	await expect(page.locator('#content-vue input, #content-vue .input-field__input').first()).toBeVisible()
})

/*
 * Backend / data-dependent scenarios excluded — covered by PHPUnit / Newman:
 * @e2e exclude barcode-resolves-to-product — requires seeded product with barcode
 * @e2e exclude barcode-not-found-handling — requires lookup against seeded catalog
 * @e2e exclude barcode-checkdigit-validation — server-side; covered by PHPUnit
 */
