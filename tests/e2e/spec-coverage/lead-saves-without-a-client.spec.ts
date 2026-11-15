/*
 * SPDX-FileCopyrightText: 2026 Pipelinq Contributors
 * SPDX-License-Identifier: EUPL-1.2
 *
 * A lead belongs to a pipeline. A client is optional (decision 207, 12 October
 * 2026): a lead can exist with no client linked.
 *
 * Why this is worth a browser test rather than a unit test on `errors`:
 * OpenRegister validates `required` on every save and rejects the whole object
 * with "The required property (client) is missing". The form and the schema
 * have to agree, and only a real save shows that they do: the form lets the
 * lead through, and the schema the instance imported accepts it.
 */

import type { Page } from '@playwright/test'

import { expect, test } from '@playwright/test'
import { dismissSupportDialog, dismissWalkthrough } from '../helpers/pipelinq.ts'

test.beforeEach(() => {
	test.slow()
})

/** Open the New Lead dialog from the sales dashboard header action. */
async function openNewLeadDialog(page: Page) {
	await page.goto('/apps/pipelinq/')
	await dismissWalkthrough(page)
	await dismissSupportDialog(page)

	const button = page.locator('[data-testid="cn-action-new-lead"]')
	await expect(
		button,
		'New Lead must be offered on the sales dashboard',
	).toBeVisible({
		timeout: 20000,
	})
	await button.click()

	const dialog = page.locator('[data-testid="lead-create-dialog"]')
	await expect(dialog).toBeVisible({ timeout: 15000 })
	return dialog
}

// @e2e openspec/specs/lead-management/spec.md#create-a-lead-without-a-client
test('a lead is saved with a title and no client', async ({ page }) => {
	const dialog = await openNewLeadDialog(page)

	const title = dialog.locator('input').first()
	await title.click()
	const name = `E2E lead without a client ${Date.now()}`
	await title.fill(name)

	// The pipeline is auto-assigned from the default, so a title is enough.
	const save = dialog
		.locator('button')
		.filter({ hasText: /^(Create|Aanmaken|Save|Opslaan)$/ })
		.first()
	await expect(
		save,
		'a title on the default pipeline must be enough to create a lead',
	).toBeEnabled({ timeout: 10000 })

	// The save answers 2xx: the schema on this instance accepts the lead.
	const answer = page.waitForResponse(
		(response) =>
			response.url().includes('/apps/openregister/api/objects/')
			&& response.request().method() === 'POST',
		{ timeout: 20000 },
	)
	await save.click()
	const response = await answer
	expect(
		response.status(),
		'a lead without a client must be stored, not refused as missing a required property',
	).toBeLessThan(300)

	const stored = await response.json()
	expect(stored.title).toBe(name)
	expect(
		stored.client ?? null,
		'no client may be invented for a lead saved without one',
	).toBeNull()
})

// @e2e openspec/specs/lead-management/spec.md#create-a-lead-without-a-client
test('the form says the client may stay empty', async ({ page }) => {
	const dialog = await openNewLeadDialog(page)

	await expect(
		dialog.locator('[data-testid="lead-form-client-hint"]'),
		'the client field must say it may stay empty',
	).toBeVisible({ timeout: 10000 })
	await expect(
		dialog.locator('[data-testid="lead-form-client"] .field-error'),
		'the client field must never report as missing',
	).toHaveCount(0)

	// Pipeline is auto-assigned from the default (lead-management/spec.md
	// scenario 1), so it must not report as missing either. This fails if the
	// auto-assign regresses.
	await expect(
		dialog.locator('[data-testid="lead-form-pipeline"] .field-error'),
		'pipeline is auto-assigned from the default, so it must NOT report as missing',
	).toBeHidden({ timeout: 10000 })
})
