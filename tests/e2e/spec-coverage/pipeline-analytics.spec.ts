/*
 * SPDX-FileCopyrightText: 2026 Pipelinq Contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 *
 * Gate-19 behavioral e2e coverage for the Pipeline Analytics page
 * (/pipeline-analytics) — distinct from the deal-board insights covered by
 * pipeline-insights.spec.ts. Maps to openspec/specs/pipeline-insights/spec.md.
 */
import { test, expect } from '@playwright/test'
import { openApp, navClick, trackPipelinqErrors, assertNoHardError } from '../helpers/pipelinq'

// @e2e openspec/specs/pipeline-insights/spec.md#pipeline-analytics-page
test('Pipeline Analytics: navigates from sidebar and shows the analytics surface', async ({ page }) => {
	const errs = trackPipelinqErrors(page)
	await openApp(page)
	await navClick(page, 'Pipeline Analytics', /\/pipeline-analytics/)

	await expect(page.locator('#content-vue').getByRole('heading', { name: 'Pipeline Analytics' }).first()).toBeVisible()
	await assertNoHardError(page)
	expect(errs(), `pipelinq console errors: ${errs().join(' || ')}`).toEqual([])
})

// @e2e openspec/specs/pipeline-insights/spec.md#pipeline-analytics-content
test('Pipeline Analytics: main content area renders below the heading', async ({ page }) => {
	await openApp(page)
	await navClick(page, 'Pipeline Analytics', /\/pipeline-analytics/)

	await expect(page.locator('#content-vue').first()).toBeVisible()
	// Body has substantive report content (not a blank/error shell).
	const text = (await page.locator('#content-vue').textContent()) || ''
	expect(text.replace(/\s+/g, ' ').trim().length).toBeGreaterThan(20)
})

/*
 * Backend / data-dependent scenarios excluded — covered by PHPUnit / Newman:
 * @e2e exclude pipeline-conversion-rates — server-side aggregation; covered by PHPUnit
 * @e2e exclude pipeline-stage-velocity — requires seeded historical deals
 * @e2e exclude pipeline-win-loss-ratio — requires seeded closed deals
 */
