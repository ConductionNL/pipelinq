/*
 * SPDX-FileCopyrightText: 2026 Pipelinq Contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 *
 * Gate-19 behavioral e2e coverage for the Analytics dashboard page
 * (/analytics). Maps to openspec/specs/pipeline-insights/spec.md.
 */
import { test, expect } from '@playwright/test'
import { openApp, navById, trackPipelinqErrors, assertNoHardError } from '../helpers/pipelinq'

// The Analytics report moved under the collapsible "Analytics" nav group in the
// IA restructure; its stable testid is cn-nav-entry-Analytics (route #/analytics).
// A sibling "Pipeline Analytics" entry shares the substring, so navigate by the
// exact entry id rather than by label.

// @e2e openspec/specs/pipeline-insights/spec.md#analytics-dashboard
test('Analytics: navigates from sidebar and shows the analytics surface', async ({ page }) => {
	const errs = trackPipelinqErrors(page)
	await openApp(page)
	await navById(page, 'Analytics', /#\/analytics$/)

	await expect(page.locator('#content-vue').getByRole('heading', { name: 'Analytics' }).first()).toBeVisible()
	await assertNoHardError(page)
	expect(errs(), `pipelinq console errors: ${errs().join(' || ')}`).toEqual([])
})

// @e2e openspec/specs/pipeline-insights/spec.md#analytics-actions
test('Analytics: exposes an Actions menu on the report', async ({ page }) => {
	await openApp(page)
	await navById(page, 'Analytics', /#\/analytics$/)

	await expect(page.locator('#content-vue').getByRole('button', { name: 'Actions' }).first()).toBeVisible()
})

/*
 * Backend / data-dependent scenarios excluded — covered by PHPUnit / Newman:
 * @e2e exclude analytics-metric-aggregation — server-side; covered by PHPUnit
 * @e2e exclude analytics-time-range-filter — requires seeded time-series data
 * @e2e exclude analytics-export — file content asserted in service unit test
 */
