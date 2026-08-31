/*
 * SPDX-FileCopyrightText: 2026 Pipelinq Contributors
 * SPDX-License-Identifier: EUPL-1.2
 *
 * The contactmoment quick-log carries the same client → contact cascade as the
 * lead and request forms, through the shared linkedPartyCascadeMixin.
 *
 * It is covered separately for the reason the mixin exists: three components
 * share these methods, and a change that reaches two of them leaves the third
 * broken with every other cascade test still green.
 *
 * The quick-log lives on the Client detail page with `clientId` pre-bound, so
 * the substantive half here is the CONTACT picker — logging who you actually
 * spoke to at that client, which the form could not express at all before.
 */

import { test, expect, type Page } from '@playwright/test'

import { dismissSupportDialog, dismissWalkthrough } from '../helpers/pipelinq'

// The Client detail page loads a header, several object lists, a bookings
// timeline and this form before the pickers are usable. Reaching it and then
// driving a debounced picker does not reliably fit the default 30s — the same
// ceiling the request-side cascade spec hit.
test.beforeEach(() => {
	test.slow()
})

/** Open the first client's detail page and return the quick-log form. */
async function openQuickLog(page: Page) {
	await page.goto('/apps/pipelinq/#/clients')
	await dismissWalkthrough(page)
	await dismissSupportDialog(page)

	// Hash routes, not paths. `/apps/pipelinq/clients/<id>` does not 404 — it
	// silently serves the app shell, which boots to the Sales dashboard, so the
	// failure reads as "the quick-log is missing" rather than "you are on the
	// wrong page".
	//
	// Read the id off the row and navigate, rather than clicking it. The client
	// index renders inside a widget whose sticky header overlays the top rows,
	// so a row click is intercepted rather than slow — it retried for the full
	// 90s and reported as a missing form.
	const row = page.locator('[data-testid="cn-object-row"]').first()
	await expect(row, 'the client list must have a row to open').toBeVisible({
		timeout: 20000,
	})
	const id = await row.getAttribute('data-testid-row-id')
	expect(id, 'the client row must carry its id').toBeTruthy()
	await page.goto(`/apps/pipelinq/#/clients/${id}`)

	const client = page.locator('[data-testid="contactmoment-form-client"]')
	await expect(
		client,
		'the contactmoment quick-log must be on the client detail page',
	).toBeVisible({ timeout: 20000 })
	return client
}

// @e2e openspec/specs/lead-management/spec.md#requirement-linked-party-selection-on-the-create-form
test('the quick-log contact picker unlocks once a client is set', async ({
	page,
}) => {
	await openQuickLog(page)
	const contactField = page.locator('[data-testid="contactmoment-form-contact"]')
	await expect(contactField).toBeVisible({ timeout: 10000 })

	// The page pre-binds clientId, so the contact picker must already be
	// enabled here. Asserting it is NOT disabled is the whole point: the
	// pre-bind reaches form.client, and if it did not, the cascade would leave
	// this picker permanently locked with no way for the user to unlock it.
	await expect(
		contactField.locator('.vs--disabled'),
		'a pre-bound client must leave the contact picker usable',
	).toHaveCount(0, { timeout: 10000 })

	await contactField.locator('input').first().click()
	await expect(
		page.locator('.vs__dropdown-menu li').first(),
		'the contact picker must offer options rather than dead-ending',
	).toBeVisible({ timeout: 10000 })
})

// @e2e openspec/specs/lead-management/spec.md#requirement-linked-party-selection-on-the-create-form
test('the quick-log client picker searches the server and can create', async ({
	page,
}) => {
	const client = await openQuickLog(page)
	const input = client.locator('input').first()
	await input.click()
	await input.pressSequentially('Zzz Onbekend Bedrijf')

	// This is the half the old preloaded picker could not do: it held one page
	// of clients and offered nothing beyond it, with no way to tell from the UI
	// that the list had been cut off.
	await expect(
		page.locator('.vs__dropdown-menu li', { hasText: /Create/i }).first(),
		'an unknown name must offer Create rather than showing no results',
	).toBeVisible({ timeout: 10000 })
})
