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
import { test, expect } from '@playwright/test'
import { openApp, navClick, assertNoHardError, trackPipelinqErrors } from '../helpers/pipelinq'

// @e2e openspec/specs/pos-refund-return/spec.md#returns-page
test('Returns: navigates from sidebar and mounts the index chrome', async ({ page }) => {
	await openApp(page)
	await navClick(page, 'Returns', /\/pos\/refunds/)

	await assertNoHardError(page)
	await expect(page.locator('[data-testid="cn-index-page"]').first()).toBeVisible()
})

// @e2e openspec/specs/pos-refund-return/spec.md#returns-add-item
test('Returns: primary "Add Item" action is present', async ({ page }) => {
	await openApp(page)
	await navClick(page, 'Returns', /\/pos\/refunds/)

	await expect(page.locator('#content-vue').getByRole('button', { name: 'Add Item' })).toBeVisible()
})

// @e2e openspec/specs/pos-refund-return/spec.md#returns-list
test('Returns: refund list data surface renders without a registration error', async ({ page }) => {
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
		content.locator('table, .cn-data-table').first()
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
