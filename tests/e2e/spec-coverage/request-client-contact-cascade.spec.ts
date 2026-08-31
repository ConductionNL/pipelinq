/*
 * SPDX-FileCopyrightText: 2026 Pipelinq Contributors
 * SPDX-License-Identifier: EUPL-1.2
 *
 * The request form carries the same client-create and contact cascade as the
 * lead form, reached from Customer Support rather than a sales dashboard.
 *
 * The lead side is covered by lead-client-contact-cascade.spec.ts. This exists
 * because the two forms are separate components that happen to share a
 * pattern: a change applied to one and not the other would leave this half
 * broken with every lead-side test still green, which is exactly how the
 * pipeline-select defect survived — it was in BOTH forms and only one was ever
 * looked at.
 */

import { test, expect, type Page } from '@playwright/test'

import { dismissSupportDialog, dismissWalkthrough } from '../helpers/pipelinq'

/** Open the New Request dialog from the Customer Support header action. */
async function openNewRequestDialog(page: Page) {
	await page.goto('/apps/pipelinq/#/werkplek')
	await dismissWalkthrough(page)
	await dismissSupportDialog(page)

	const button = page.locator('[data-testid="cn-action-new-request"]')
	await expect(
		button,
		'New Request must be offered on Customer Support',
	).toBeVisible({ timeout: 20000 })
	await button.click()

	const dialog = page.locator('[data-testid="request-create-dialog"]')
	await expect(dialog).toBeVisible({ timeout: 15000 })
	return dialog
}

/** The search input inside one of the form's pickers. */
function pickerInput(dialog: ReturnType<Page['locator']>, testid: string) {
	return dialog.locator(`[data-testid="${testid}"] input`).first()
}

// @e2e openspec/specs/lead-management/spec.md#requirement-linked-party-selection-on-the-create-form
test('the request client picker can be browsed and can create', async ({ page }) => {
	const dialog = await openNewRequestDialog(page)
	const input = pickerInput(dialog, 'request-form-client')
	await input.click()

	await expect(
		page.locator('.vs__dropdown-menu li').first(),
		'preload must offer clients before anything is typed',
	).toBeVisible({ timeout: 10000 })

	await input.pressSequentially('Zzz Onbekend Bedrijf')
	await expect(
		page.locator('.vs__dropdown-menu li', { hasText: /Create/i }).first(),
		'an unknown name must offer Create rather than dead-ending on no results',
	).toBeVisible({ timeout: 10000 })
})

// @e2e openspec/specs/lead-management/spec.md#requirement-linked-party-selection-on-the-create-form
test('the request contact picker unlocks and scopes to the chosen client', async ({
	page,
}) => {
	const dialog = await openNewRequestDialog(page)
	const contactField = dialog.locator('[data-testid="request-form-contact"]')

	await expect(
		contactField.locator('.vs--disabled'),
		'contact must be disabled until a client is chosen',
	).toHaveCount(1, { timeout: 10000 })

	const clientField = dialog.locator('[data-testid="request-form-client"]')
	const clientInput = pickerInput(dialog, 'request-form-client')
	await clientInput.click()
	await clientInput.pressSequentially('Bakkerij')

	// hasNotText is load-bearing: the synthetic Create option also contains the
	// typed term, so a plain hasText match can open the create dialog instead
	// of selecting the existing client.
	const match = page
		.locator('.vs__dropdown-menu li')
		.filter({ hasText: 'Bakkerij' })
		.filter({ hasNotText: /Create/i })
		.first()
	await expect(match).toBeVisible({ timeout: 10000 })
	await expect(
		page.locator('.vs__dropdown-menu li', { hasText: /Create/i }),
		'wait for the debounced search to settle',
	).toHaveCount(1, { timeout: 10000 })
	await match.click()

	await expect(
		clientField.locator('.vs__selected'),
		'the client must be selected before the cascade can be judged',
	).toContainText('Bakkerij', { timeout: 10000 })

	await expect(
		contactField.locator('.vs--disabled'),
		'choosing a client must enable the contact picker',
	).toHaveCount(0, { timeout: 10000 })

	await pickerInput(dialog, 'request-form-contact').click()
	const options = page.locator('.vs__dropdown-menu li')
	await expect(options.first()).toBeVisible({ timeout: 10000 })

	const shown = (await options.allTextContents()).map((t) => t.trim())
	const real = shown.filter((t) => !/^Create/i.test(t) && t !== 'No results')
	expect(
		real.length,
		`the contact list must be scoped to the chosen client; saw ${JSON.stringify(shown)}`,
	).toBeLessThanOrEqual(3)
})
