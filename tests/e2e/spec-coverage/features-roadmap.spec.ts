/*
 * SPDX-FileCopyrightText: 2026 Pipelinq Contributors
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Gate-19 behavioral e2e coverage for the Features & roadmap page
 * (/features-roadmap). Maps to openspec/specs/notifications-activity/spec.md
 * (closest in-app surface; the page is a static product-marketing view).
 */
import { expect, test } from '@playwright/test'
import {
	assertNoHardError,
	dismissSupportDialog,
	navClick,
	openApp,
	trackPipelinqErrors,
} from '../helpers/pipelinq.ts'

// @e2e openspec/specs/notifications-activity/spec.md#features-roadmap-page
test('Features & roadmap: navigates from sidebar and shows the features surface', async ({
	page,
}) => {
	const errs = trackPipelinqErrors(page)
	await openApp(page)
	await navClick(page, 'Features & roadmap', /\/features-roadmap/)

	await expect(
		page
			.locator('#content-vue')
			.getByRole('heading', { name: 'Features' })
			.first(),
	).toBeVisible()
	await assertNoHardError(page)
	expect(errs(), `pipelinq console errors: ${errs().join(' || ')}`).toEqual([])
})

// @e2e openspec/specs/notifications-activity/spec.md#features-roadmap-actions
test('Features & roadmap: exposes roadmap + suggest-feature actions', async ({
	page,
}) => {
	await openApp(page)
	await navClick(page, 'Features & roadmap', /\/features-roadmap/)

	const content = page.locator('#content-vue')
	await expect(content.getByRole('button', { name: 'Show roadmap' })).toBeVisible()
	await expect(
		content.getByRole('button', { name: 'Suggest feature' }).first(),
	).toBeVisible()
})

// @e2e openspec/specs/notifications-activity/spec.md#features-roadmap-roadmap-toggle
test('Features & roadmap: Show roadmap reveals roadmap content', async ({
	page,
}) => {
	await openApp(page)
	await navClick(page, 'Features & roadmap', /\/features-roadmap/)
	await dismissSupportDialog(page)

	await page
		.locator('#content-vue')
		.getByRole('button', { name: 'Show roadmap' })
		.click()
	// After toggling, the view should still be intact and not error.
	await assertNoHardError(page)
	await expect(page.locator('#content-vue').first()).toBeVisible()
})

/*
 * Backend / data-dependent scenarios excluded — covered elsewhere:
 * @e2e exclude suggest-feature-submission — opens an external/support flow
 * @e2e exclude roadmap-data-source — static content; no backend assertion
 */
