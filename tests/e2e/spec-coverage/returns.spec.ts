/*
 * SPDX-FileCopyrightText: 2026 Pipelinq Contributors
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Gate-19 behavioral e2e coverage for the Returns / refunds page (/pos/refunds).
 * Maps to openspec/specs/pos-refund-return/spec.md.
 *
 * LIVE STATE (verified 2026-06-09 against the deployed bundle): the page mounts
 * its `cn-index-page` chrome and the primary "Add Item" CTA. The store.js
 * slug-fallback registration (commit a53bc8c5) registers `posRefund` against the
 * canonical OR schema slug when the app-config numeric id is empty (register=16,
 * empty *_schema), so the schema-driven data surface now loads and the per-type
 * "not registered" error no longer fires on this page.
 */
import { expect, test } from '@playwright/test'
import {
	assertNoHardError,
	navClick,
	openActionsOverflow,
	openApp,
	trackPipelinqErrors,
} from '../helpers/pipelinq.ts'

// @e2e openspec/specs/pos-refund-return/spec.md#returns-page
test('Returns: navigates from sidebar and mounts the index chrome', async ({
	page,
}) => {
	await openApp(page)
	await navClick(page, 'Returns', /\/pos\/refunds/)

	await assertNoHardError(page)
	await expect(page.locator('[data-testid="cn-index-page"]').first()).toBeVisible()
})

// @e2e openspec/specs/pos-refund-return/spec.md#returns-add-item
test('Returns: the create entry point is reachable from the Actions menu', async ({
	page,
}) => {
	await openApp(page)
	await navClick(page, 'Returns', /\/pos\/refunds/)

	// CORRECTED 2026-08-06. This asserted a visible "Add Item" button. There is
	// none, and there is not meant to be: the PosRefunds page sets
	// `showAdd: false` in src/manifest.json — which is the ONLY condition under
	// which CnActionsBar emits the primary CTA (`data-testid="cn-cta-primary"`)
	// — and declares its create entry point as a `headerActions[]` entry labelled
	// "Nieuwe retour". CnActionsBar renders manifest headerActions as
	// NcActionButtons INSIDE the overflow "Actions" menu ("Page-level header
	// actions rendered inside CnActionsBar's overflow dropdown", verified in
	// @conduction/nextcloud-vue 2.2.0-vue3.3 dist/). So the control exists, one
	// click deeper, under its Dutch label.
	//
	// ⚠️ PRODUCT QUESTION (reported, not silently encoded): burying the primary
	// create action of a ledger page in a ⋯ menu is a UX regression against every
	// other index in this app, which shows it as a primary button. If that is
	// decided to be wrong, the fix is `showAdd: true` in the manifest and this
	// test should go back to asserting `cn-cta-primary`.
	// The label is asserted as it RENDERS, not as the manifest spells it.
	// @conduction/nextcloud-vue 2.12 (#734) routes manifest-authored page
	// chrome through the host translate function, so a headerActions label is
	// now a translation key rather than literal output. This app's l10n/en.json
	// maps "Nieuwe retour" -> "New refund", and the e2e suite runs in English,
	// so the rendered label is the English one. Asserting the Dutch source here
	// would pass only while the string was NOT being translated.
	await openActionsOverflow(page)
	await expect(page.getByText('New refund').first()).toBeVisible({
		timeout: 10000,
	})
})

// @e2e openspec/specs/pos-refund-return/spec.md#returns-list
test('Returns: refund list data surface renders without a registration error', async ({
	page,
}) => {
	// store.js slug-fallback registration (commit a53bc8c5) registers "posRefund"
	// against the canonical OR schema slug when the app-config numeric id is empty,
	// so the collection fetch resolves: the index renders its schema-driven data
	// surface (the Cards/Table view toggle + a populated table, or — register 16
	// holds the schema but no seeded refunds — the genuine "No items found" empty
	// state) rather than the broken "not registered" failure state.
	const errors = trackPipelinqErrors(page)
	await openApp(page)
	await navClick(page, 'Returns', /\/pos\/refunds/)
	const content = page.locator('#content-vue')
	// The Cards/Table view switch is a SEGMENTED CONTROL, not a radiogroup:
	// CnActionsBar renders `<div role="group" aria-label="View mode">` containing
	// plain `<button type="button" :aria-pressed="…">` segments
	// (@conduction/nextcloud-vue, CnActionsBar.vue lines 22-55). There is no
	// `role="radio"` anywhere in that component, so `getByRole('radio')` could
	// never match and this assertion failed against every render of the page —
	// including the correct one. Asserting the segment by its real role keeps the
	// same claim ("the view toggle is on screen") against the contract the
	// component actually implements.
	await expect(content.getByRole('button', { name: 'Table' })).toBeVisible()
	await expect(
		content
			.locator('table, .cn-data-table')
			.first()
			.or(content.getByText(/No items found/i).first()),
	).toBeVisible()
	expect(errors().filter((e) => /posRefund.*not registered/i.test(e))).toEqual([])
})

/*
 * Backend / data-dependent scenarios excluded — covered by PHPUnit / Newman:
 * @e2e exclude refund-against-original-transaction — server-side; covered by PHPUnit
 * @e2e exclude refund-restock-inventory — server-side; covered by PHPUnit
 * @e2e exclude refund-detail-view — requires seeded record + store registration
 */
