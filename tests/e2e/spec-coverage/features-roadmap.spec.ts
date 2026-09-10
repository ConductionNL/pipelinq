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
 * wraps the library's product page and owns the capability comparison beside
 * it, so both sections are driven here.
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
 * The capability comparison. The page carries a second section beside the
 * product one, and everything below drives it in the browser. The four caveats
 * are ALSO asserted against the mounted component in
 * tests/vitest/capabilityComparison.spec.js, which runs in seconds on a laptop;
 * these tests prove the section is reachable and populated on a real instance,
 * which a mounted component cannot.
 */

// @e2e openspec/specs/features-roadmap/spec.md#areas-summarise-before-they-expand
test('Features & roadmap: the comparison lists areas with a score, rows collapsed', async ({
	page,
}) => {
	await openApp(page)
	await navClick(page, 'Features & roadmap', /\/features-roadmap/)
	await dismissSupportDialog(page)

	const content = page.locator('#content-vue')
	await content.getByRole('button', { name: 'How pipelinq compares' }).click()

	// Every area states its size and our score before it is opened.
	const areas = content.locator('.features-roadmap__area')
	await expect(areas.first()).toBeVisible()
	await expect(areas.first().locator('summary')).toContainText('Pipelinq has')

	// Closed means closed: no capability row is on the screen yet.
	await expect(areas.first().locator('.features-roadmap__cap')).toHaveCount(0)

	await areas.first().locator('summary').click()
	await expect(
		areas.first().locator('.features-roadmap__cap').first(),
	).toBeVisible()

	await assertNoHardError(page)
})

// @e2e openspec/specs/features-roadmap/spec.md#the-panel-advises-the-reader-to-test-for-themselves
// @e2e openspec/specs/features-roadmap/spec.md#a-reader-can-date-the-claim
// @e2e openspec/specs/features-roadmap/spec.md#the-panel-says-only-our-own-column-is-corrected
test('Features & roadmap: the comparison states its limits before its scores', async ({
	page,
}) => {
	await openApp(page)
	await navClick(page, 'Features & roadmap', /\/features-roadmap/)
	await dismissSupportDialog(page)

	const content = page.locator('#content-vue')
	await content.getByRole('button', { name: 'How pipelinq compares' }).click()

	const panel = content.locator('.features-roadmap__comparison')
	await expect(panel).toContainText('run your own evaluation')
	await expect(panel).toContainText(
		'does not replace testing against your own requirements',
	)
	await expect(panel).toContainText('already out of date')
	await expect(panel).toContainText('September 9, 2026')
	await expect(panel).toContainText('we correct our own column only')

	await assertNoHardError(page)
})

/*
 * Backend / data-dependent scenarios excluded — covered elsewhere:
 * @e2e exclude suggest-feature-submission — opens an external/support flow
 * @e2e exclude roadmap-data-source — static content; no backend assertion
 */
