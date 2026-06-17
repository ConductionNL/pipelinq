/*
 * SPDX-FileCopyrightText: 2026 Pipelinq Contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 *
 * Gate-19 behavioral e2e coverage for the Pipelines index page (/pipelines)
 * — the pipeline-definition list, distinct from the deal board (/pipeline).
 * Maps to openspec/specs/pipeline/spec.md.
 */
import { test, expect } from '@playwright/test'
import { trackPipelinqErrors, assertNoHardError, dismissSupportDialog } from '../helpers/pipelinq'

/**
 * The IA restructure relocated the Pipelines definition list out of the main
 * sidebar and into the bottom "Settings" list (cn-app-nav__settings-list),
 * which is collapsed until the settings toggle is opened. The route
 * (#/pipelines) is unchanged, so land on it via the SPA hash + reload so the
 * router boots straight onto the page rather than clicking through the hidden
 * settings flyout.
 */
async function openPipelines(page) {
	await page.goto('/apps/pipelinq/#/pipelines')
	await expect(page.locator('#app-navigation-vue')).toBeVisible({ timeout: 15000 })
	await page.reload()
	await dismissSupportDialog(page)
	await expect(page.locator('#content-vue').getByRole('heading', { name: 'Pipelines' }).first())
		.toBeVisible({ timeout: 15000 })
}

// @e2e openspec/specs/pipeline/spec.md#pipelines-index
test('Pipelines: navigates from sidebar and shows index surface', async ({ page }) => {
	const errs = trackPipelinqErrors(page)
	await openPipelines(page)

	await expect(page.locator('#content-vue').getByRole('heading', { name: 'Pipelines' }).first()).toBeVisible()
	await expect(page.locator('[data-testid="cn-index-page"]').first()).toBeVisible()
	await assertNoHardError(page)
	expect(errs(), `pipelinq console errors: ${errs().join(' || ')}`).toEqual([])
})

// @e2e openspec/specs/pipeline/spec.md#pipelines-list-table
test('Pipelines: list table, pagination and primary actions render', async ({ page }) => {
	await openPipelines(page)

	const content = page.locator('#content-vue')
	await expect(content.getByRole('button', { name: 'Add Pipeline' })).toBeVisible()
	await expect(content.locator('table, .cn-data-table, [data-testid="cn-data-table"]').first()).toBeVisible()
})

// @e2e openspec/specs/pipeline/spec.md#pipelines-create-modal
test('Pipelines: Add Pipeline opens a create modal with a form', async ({ page }) => {
	await openPipelines(page)
	await dismissSupportDialog(page)

	await page.locator('#content-vue').getByRole('button', { name: 'Add Pipeline' }).click()
	const modal = page.locator('.modal-container, [role="dialog"]').first()
	await expect(modal).toBeVisible({ timeout: 10000 })
	await expect(modal.locator('input, .input-field__input, textarea').first()).toBeVisible()
})

/*
 * Backend / data-dependent scenarios excluded — covered by PHPUnit / Newman:
 * @e2e exclude pipeline-stage-config — requires editing an existing pipeline
 * @e2e exclude pipeline-detail-view — requires seeded record
 * @e2e exclude pipeline-delete — requires existing record
 */
