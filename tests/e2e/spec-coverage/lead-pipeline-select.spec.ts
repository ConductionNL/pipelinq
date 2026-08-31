/*
 * SPDX-FileCopyrightText: 2026 Pipelinq Contributors
 * SPDX-License-Identifier: EUPL-1.2
 *
 * The New Lead form must OFFER a pipeline.
 *
 * Both create forms filtered the pipeline list on `pipeline.entityType`, a
 * field the schema never defined and nothing writes, so every pipeline failed
 * the filter: the dropdown listed nothing and no default could be
 * auto-assigned. tests/vitest/pipelineScope.spec.js pins the filter predicate;
 * these pin what a user actually sees, because the predicate was only half the
 * bug — `propertyMappings` was also undeclared on the schema, so the stored
 * data the predicate reads was discarded on write. Only an end-to-end check
 * spans both halves.
 *
 * Fields are addressed by `data-testid`, never by label text: the dev instance
 * runs Dutch ("Pijplijn" / "Fase") and CI runs English, so a label selector
 * passes in one and fails in the other for no product reason.
 */

import { test, expect, type Page } from '@playwright/test'

import { dismissSupportDialog, dismissWalkthrough } from '../helpers/pipelinq'

/** Open the New Lead dialog from the dashboard header actions. */
async function openNewLeadDialog(page: Page) {
	await page.goto('/apps/pipelinq/')
	await dismissWalkthrough(page)
	await dismissSupportDialog(page)

	// The quick-create actions are English even on a Dutch instance ONLY when
	// the app's own catalogue supplies them, which is not guaranteed here — so
	// match either spelling rather than pinning one.
	const button = page
		.getByRole('button', { name: /^(New Lead|Nieuwe lead)$/i })
		.first()
	await expect(button).toBeVisible({ timeout: 20000 })
	await button.click()

	const dialog = page.locator('[data-testid="lead-create-dialog"]')
	await expect(dialog).toBeVisible({ timeout: 15000 })
	return dialog
}

// @e2e openspec/specs/lead-management/spec.md#pipeline-value-summary-by-stage
test('the New Lead form offers a pipeline rather than an empty list', async ({
	page,
}) => {
	const dialog = await openNewLeadDialog(page)
	const pipelineField = dialog.locator('[data-testid="lead-form-pipeline"]')
	await expect(pipelineField).toBeVisible({ timeout: 10000 })

	// A default pipeline is auto-assigned when one is marked default, so the
	// field usually arrives already filled. Either way the list behind it must
	// not be empty — that emptiness IS the regression.
	await pipelineField
		.locator('input[role="combobox"], .vs__search')
		.first()
		.click()

	const options = page.locator('.vs__dropdown-menu li')
	await expect(
		options.first(),
		'the pipeline dropdown must offer at least one pipeline; an empty list is the entityType regression',
	).toBeVisible({ timeout: 10000 })
})

// @e2e openspec/specs/lead-management/spec.md#pipeline-value-summary-by-stage
test('a chosen pipeline unlocks its stages', async ({ page }) => {
	const dialog = await openNewLeadDialog(page)
	const pipelineField = dialog.locator('[data-testid="lead-form-pipeline"]')
	const stageField = dialog.locator('[data-testid="lead-form-stage"]')
	await expect(stageField).toBeVisible({ timeout: 10000 })

	// Ensure a pipeline is selected — pick the first option when nothing was
	// auto-assigned, so the assertion below holds on either resting state.
	const selected = pipelineField.locator('.vs__selected')
	if ((await selected.count()) === 0) {
		await pipelineField
			.locator('input[role="combobox"], .vs__search')
			.first()
			.click()
		await page.locator('.vs__dropdown-menu li').first().click()
	}
	await expect(selected).toHaveCount(1, { timeout: 10000 })

	// With a pipeline chosen the stage select must be enabled and carry that
	// pipeline's stages.
	await expect(
		stageField.locator('.vs--disabled'),
		'the stage select must not stay disabled once a pipeline is chosen',
	).toHaveCount(0, { timeout: 10000 })

	await stageField.locator('input[role="combobox"], .vs__search').first().click()
	await expect(
		page.locator('.vs__dropdown-menu li').first(),
		"the stage dropdown must list the selected pipeline's stages",
	).toBeVisible({ timeout: 10000 })
})
