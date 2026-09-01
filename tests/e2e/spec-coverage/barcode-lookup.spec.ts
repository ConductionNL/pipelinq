/*
 * SPDX-FileCopyrightText: 2026 Pipelinq Contributors
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Gate-19 behavioral e2e coverage for pos-barcode-scan
 * (openspec/specs/pos-barcode-scan/spec.md).
 *
 * RETARGETED 2026-08-06 — the dedicated "Barcode lookup" page (/products-barcode)
 * was retired by the `nav-ia-cleanup` IA revision. src/manifest.json records the
 * decision on the Products index itself:
 *
 *   "The dedicated 'Barcode lookup' page is gone (nav-ia-cleanup): this index's
 *    search already matches on barcode … `barcode` is a column so the value is
 *    visible on the row you land on, not just the key you searched by."
 *
 * So the surviving browser-observable contract is: the Products index carries
 * `barcode` as a column and shows the real EAN value on the row. Both tests
 * assert that against the seeded catalogue rather than against a page that no
 * longer exists — the previous spec waited 10s for a sidebar entry labelled
 * "Barcode lookup" and failed on the navigation helper.
 *
 * NOTE for whoever picks up the search half of that claim: the Products index
 * declares no `showSidebarToggle`, so whether a search box is reachable from
 * this page at all is UNVERIFIED here and deliberately not asserted — writing
 * an assertion against a control I could not locate would have produced exactly
 * the kind of green that says nothing.
 */
import { expect, test } from '@playwright/test'
import {
	assertNoHardError,
	navClick,
	openApp,
	trackPipelinqErrors,
} from '../helpers/pipelinq.ts'

/** EAN-13 carried by the seeded "[Demo] Koffie zwart" product. */
const SEEDED_BARCODE = '8714100000017'

// @e2e openspec/specs/pos-barcode-scan/spec.md#barcode-lookup-page
test('Barcode lookup: the Products index is the barcode surface and declares the column', async ({
	page,
}) => {
	const errs = trackPipelinqErrors(page)
	await openApp(page)
	await navClick(page, 'Products', /\/products/)

	await expect(
		page
			.locator('#content-vue')
			.getByRole('heading', { name: 'Products' })
			.first(),
	).toBeVisible()
	// `barcode` is the second declared column on the Products index.
	await expect(
		page
			.locator('#content-vue')
			.getByRole('columnheader', { name: /barcode/i })
			.first(),
	).toBeVisible({ timeout: 15000 })

	await assertNoHardError(page)
	expect(errs(), `pipelinq console errors: ${errs().join(' || ')}`).toEqual([])
})

// @e2e openspec/specs/pos-barcode-scan/spec.md#barcode-lookup-input
test('Barcode lookup: a seeded product shows its EAN on the row', async ({
	page,
}) => {
	await openApp(page)
	await navClick(page, 'Products', /\/products/)

	// The whole point of promoting barcode to a column: the value is READABLE on
	// the row, not merely a key you can search by. A missing/blank barcode cell
	// fails here even though the table itself renders.
	await expect(page.locator('#content-vue table tbody')).toContainText(
		SEEDED_BARCODE,
		{ timeout: 15000 },
	)
})

/*
 * Backend / data-dependent scenarios excluded — covered by PHPUnit / Newman:
 * @e2e exclude barcode-not-found-handling — server-side lookup contract; covered by PHPUnit
 * @e2e exclude barcode-checkdigit-validation — server-side; covered by PHPUnit
 */
