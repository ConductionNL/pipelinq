/*
 * SPDX-FileCopyrightText: 2026 Pipelinq Contributors
 * SPDX-License-Identifier: EUPL-1.2
 *
 * The client picker creates, and the contact picker cascades off it.
 *
 * Three behaviours that only exist end to end:
 *  - the client list is browsable without typing (`preload`), so a user who
 *    does not know the name can still find one;
 *  - an unknown name offers Create, rather than dead-ending on "no results";
 *  - the contact list is SCOPED to the chosen client. Scoping is the half a
 *    unit test cannot see: the filter is applied by the shared component
 *    against a live query, so "shows the right contacts" and "shows every
 *    contact in the system" are indistinguishable without real data behind it.
 *
 * Addressed by `data-testid`, never by label text: the dev instance renders
 * Dutch and CI renders English.
 */

import type { Page } from '@playwright/test'

import { expect, test } from '@playwright/test'
import { dismissSupportDialog, dismissWalkthrough } from '../helpers/pipelinq.ts'

/** Open the New Lead dialog from the dashboard's declarative header action. */
async function openNewLeadDialog(page: Page) {
	await page.goto('/apps/pipelinq/')
	await dismissWalkthrough(page)
	await dismissSupportDialog(page)

	const button = page.locator('[data-testid="cn-action-new-lead"]')
	await expect(button).toBeVisible({ timeout: 20000 })
	await button.click()

	const dialog = page.locator('[data-testid="lead-create-dialog"]')
	await expect(dialog).toBeVisible({ timeout: 15000 })
	return dialog
}

/** The search input inside one of the form's pickers. */
function pickerInput(dialog: ReturnType<Page['locator']>, testid: string) {
	return dialog.locator(`[data-testid="${testid}"] input`).first()
}

// @e2e openspec/specs/lead-management/spec.md#add-tags-to-a-lead
test('the client picker can be browsed without typing', async ({ page }) => {
	const dialog = await openNewLeadDialog(page)
	await pickerInput(dialog, 'lead-form-client').click()

	await expect(
		page.locator('.vs__dropdown-menu li').first(),
		'preload must offer clients before anything is typed',
	).toBeVisible({ timeout: 10000 })
})

// @e2e openspec/specs/lead-management/spec.md#add-tags-to-a-lead
test('an unknown client name offers to create one', async ({ page }) => {
	const dialog = await openNewLeadDialog(page)
	const input = pickerInput(dialog, 'lead-form-client')
	await input.click()
	await input.pressSequentially('Zzz Onbekend Bedrijf')

	await expect(
		page.locator('.vs__dropdown-menu li', { hasText: /Create/i }).first(),
		'an unknown name must offer Create rather than dead-ending on no results',
	).toBeVisible({ timeout: 10000 })
})

// @e2e openspec/specs/lead-management/spec.md#add-tags-to-a-lead
test('the contact picker unlocks and scopes to the chosen client', async ({
	page,
}) => {
	const dialog = await openNewLeadDialog(page)
	const contactField = dialog.locator('[data-testid="lead-form-contact"]')

	// Resting state: no client, so no contact to pick.
	await expect(
		contactField.locator('.vs--disabled'),
		'contact must be disabled until a client is chosen',
	).toHaveCount(1, { timeout: 10000 })

	// Choose the seeded client that has contact persons.
	//
	// The option is clicked only once the search has SETTLED. Typing kicks off
	// a debounced query, and clicking the first match that appears can land on
	// a preload option that the arriving results then replace, so the click
	// hits a detached node and the selection silently does not take.
	const clientField = dialog.locator('[data-testid="lead-form-client"]')
	const clientInput = pickerInput(dialog, 'lead-form-client')
	await clientInput.click()
	await clientInput.pressSequentially('Bakkerij')

	// hasNotText is load-bearing: the synthetic Create option ALSO contains the
	// typed term, so a plain hasText match can select "Create \"Bakkerij\"" and
	// open the create dialog instead of choosing the existing client.
	const match = page
		.locator('.vs__dropdown-menu li')
		.filter({ hasText: 'Bakkerij' })
		.filter({ hasNotText: /Create/i })
		.first()
	await expect(match).toBeVisible({ timeout: 10000 })
	// The synthetic Create option is recomputed from the SETTLED result set, so
	// its presence is the signal that the debounced query has landed. Counting
	// options would be wrong: "Bakkerij" is not an exact match, so Create is
	// listed alongside the real one.
	await expect(
		page.locator('.vs__dropdown-menu li', { hasText: /Create/i }),
		'wait for the debounced search to settle',
	).toHaveCount(1, { timeout: 10000 })
	await match.click()

	// Assert the selection actually took before testing what it unlocks.
	await expect(
		clientField.locator('.vs__selected'),
		'the client must be selected before the cascade can be judged',
	).toContainText('Bakkerij', { timeout: 10000 })

	// The cascade opens.
	await expect(
		contactField.locator('.vs--disabled'),
		'choosing a client must enable the contact picker',
	).toHaveCount(0, { timeout: 10000 })

	// And it is scoped: only that client's contacts, not every contact seeded.
	await pickerInput(dialog, 'lead-form-contact').click()
	const options = page.locator('.vs__dropdown-menu li')
	await expect(options.first()).toBeVisible({ timeout: 10000 })

	const shown = (await options.allTextContents()).map((t) => t.trim())
	const real = shown.filter((t) => !/^Create/i.test(t) && t !== 'No results')
	expect(
		real.length,
		`the contact list must be scoped to the chosen client; saw ${JSON.stringify(shown)}`,
	).toBeLessThanOrEqual(3)
})
