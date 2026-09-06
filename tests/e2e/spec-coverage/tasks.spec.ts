/*
 * SPDX-FileCopyrightText: 2026 Pipelinq Contributors
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Gate-19 behavioral e2e coverage for the Tasks index page (/tasks).
 * Maps to openspec/specs/task-background-jobs/spec.md.
 */
import { expect, test } from '@playwright/test'
import {
	assertNoHardError,
	dismissSupportDialog,
	navClick,
	openApp,
	trackPipelinqErrors,
} from '../helpers/pipelinq.ts'

// @e2e openspec/specs/task-background-jobs/spec.md#tasks-index
test('Tasks: navigates from sidebar and shows index surface', async ({ page }) => {
	const errs = trackPipelinqErrors(page)
	await openApp(page)
	await navClick(page, 'Tasks', /\/tasks/)

	await expect(
		page.locator('#content-vue').getByRole('heading', { name: 'Tasks' }).first(),
	).toBeVisible()
	await expect(page.locator('[data-testid="cn-index-page"]').first()).toBeVisible()
	await assertNoHardError(page)
	expect(errs(), `pipelinq console errors: ${errs().join(' || ')}`).toEqual([])
})

// @e2e openspec/specs/task-background-jobs/spec.md#tasks-list-table
test('Tasks: list table and primary actions render', async ({ page }) => {
	await openApp(page)
	await navClick(page, 'Tasks', /\/tasks/)

	const content = page.locator('#content-vue')
	await expect(content.getByRole('button', { name: 'Add Task' })).toBeVisible()
	await expect(
		content
			.locator('table, .cn-data-table, [data-testid="cn-data-table"]')
			.first(),
	).toBeVisible()
})

// @e2e openspec/specs/task-background-jobs/spec.md#tasks-create-modal
test('Tasks: Add Task opens a create modal with a form', async ({ page }) => {
	await openApp(page)
	await navClick(page, 'Tasks', /\/tasks/)
	await dismissSupportDialog(page)

	await page
		.locator('#content-vue')
		.getByRole('button', { name: 'Add Task' })
		.click()
	const modal = page.locator('.modal-container, [role="dialog"]').first()
	await expect(modal).toBeVisible({ timeout: 10000 })
	await expect(
		modal.locator('input, .input-field__input, textarea').first(),
	).toBeVisible()
})

/*
 * Backend / data-dependent scenarios excluded — covered by PHPUnit / Newman:
 * @e2e exclude task-background-job-fires — BackgroundJob; covered by PHPUnit
 * @e2e exclude task-detail-view — requires seeded record
 * @e2e exclude task-edit — requires existing record
 * @e2e exclude task-delete — requires existing record
 * @e2e exclude task-status-transition — requires existing record
 */
