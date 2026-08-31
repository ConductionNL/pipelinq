/*
 * SPDX-FileCopyrightText: 2026 Pipelinq Contributors
 * SPDX-License-Identifier: EUPL-1.2
 *
 * A lead belongs to a pipeline and to a client. Both are required by the
 * schema, so the form has to say so BEFORE the save is attempted.
 *
 * Why this is worth a browser test rather than a unit test on `errors`:
 * OpenRegister validates `required` on every save and rejects the whole object
 * with "The required property (client) is missing". If the form let that
 * through, the user would get a 400 naming a property, not a field they can
 * see. What matters is that Save is unreachable until both are set, and that
 * the reason is on screen.
 */

import { test, expect, type Page } from '@playwright/test'

import { dismissSupportDialog, dismissWalkthrough } from '../helpers/pipelinq'

test.beforeEach(() => {
	test.slow()
})

/** Open the New Lead dialog from the sales dashboard header action. */
async function openNewLeadDialog(page: Page) {
	await page.goto('/apps/pipelinq/#/')
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

// @e2e openspec/specs/lead-management/spec.md#requirement-lead-crud-mvp
test('a lead cannot be saved without a pipeline and a client', async ({ page }) => {
	const dialog = await openNewLeadDialog(page)

	// Give it only a title: the field that used to be the ONLY requirement.
	const title = dialog.locator('input').first()
	await title.click()
	await title.fill('E2E requires pipeline and client')

	const save = dialog
		.locator('button')
		.filter({ hasText: /^(Create|Aanmaken|Save|Opslaan)$/ })
		.first()

	await expect(
		save,
		'a title alone must no longer be enough to create a lead',
	).toBeDisabled({ timeout: 10000 })
})

// @e2e openspec/specs/lead-management/spec.md#requirement-lead-crud-mvp
test('the form names which relationship is missing', async ({ page }) => {
	const dialog = await openNewLeadDialog(page)

	// Both errors are rendered from the same `errors` computed the Save button
	// reads, so seeing them proves the user is told WHY rather than just being
	// faced with a dead button.
	await expect(
		dialog.locator('[data-testid="lead-form-client"] .field-error'),
		'the client field must state that it is required',
	).toBeVisible({ timeout: 10000 })

	await expect(
		dialog.locator('[data-testid="lead-form-pipeline"] .field-error'),
		'the pipeline field must state that it is required',
	).toBeVisible({ timeout: 10000 })
})
