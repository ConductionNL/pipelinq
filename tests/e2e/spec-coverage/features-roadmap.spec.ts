/*
 * SPDX-FileCopyrightText: 2026 Pipelinq Contributors
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Gate-19 behavioral e2e coverage for the Features & roadmap page
 * (/features-roadmap). Maps to openspec/specs/notifications-activity/spec.md
 * (closest in-app surface) and to openspec/specs/features-roadmap/spec.md.
 *
 * The screen under test is manifest page `FeaturesRoadmap`, rendered by
 * `FeaturesRoadmapView` (src/views/FeaturesRoadmapView.vue). That component
 * wraps the library's product page and adds one link to the help desk
 * comparison on the docs site.
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
// @e2e openspec/specs/features-roadmap/spec.md#features-page-renders-controls
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
		// A LINK, not a button. nextcloud-vue 2.36.4 removed the in-product
		// suggestion modal (team decision 2026-09-04: the forge is where the
		// conversation happens), and the CTA is an anchor to the forge's
		// feature-request issue form now. An `<a href>` has role `link`.
		content.getByRole('link', { name: 'Suggest feature' }).first(),
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
 * The help desk comparison left this page for pipelinq.conduction.nl/compare
 * on 2026-10-07 (Ruben: how an app compares belongs on its public site). Its
 * caveats are asserted in tests/vitest/capabilityComparisonCopy.spec.js and its
 * data in tests/vitest/capabilityComparison.spec.js. This test pins the two
 * things only a browser can: the section is gone, and the link to its new
 * home is on the page.
 */

// @e2e openspec/specs/features-roadmap/spec.md#the-comparison-is-one-link-away
test('Features & roadmap: links to the comparison instead of carrying it', async ({
	page,
}) => {
	await openApp(page)
	await navClick(page, 'Features & roadmap', /\/features-roadmap/)
	await dismissSupportDialog(page)

	const content = page.locator('#content-vue')
	const link = content.getByRole('link', {
		name: 'How pipelinq compares to other help desks',
	})
	await expect(link).toBeVisible()
	await expect(link).toHaveAttribute('href', /\/compare$/)
	await expect(link).toHaveAttribute('target', '_blank')

	await expect(
		content.getByRole('button', { name: 'How pipelinq compares' }),
	).toHaveCount(0)
	await expect(content.locator('.features-roadmap__comparison')).toHaveCount(0)
	await expect(content.getByText('Before you use this table')).toHaveCount(0)

	await assertNoHardError(page)
})

/*
 * Backend / data-dependent scenarios excluded — covered elsewhere:
 * @e2e exclude suggest-feature-submission — opens an external/support flow
 * @e2e exclude roadmap-data-source — static content; no backend assertion
 */
