// SPDX-License-Identifier: EUPL-1.2
// Copyright (C) 2026 Conduction B.V.
//
// align-claims-and-first-hour UI coverage:
// - The default Operational dashboard renders no permanently-null
//   Customer Satisfaction tile and the KPI row reflows without a hole.
// - The optional demo-data seed is exposed as a setup-wizard action backed
//   by POST /api/setup/action/seed-demo-data (idempotent; same write path
//   as `occ pipelinq:demo:seed`). Full wizard walking (gating) requires an
//   unconfigured install, so the gated-wizard leg is covered by the manual
//   verification recorded in the change tasks + the Newman setup folder;
//   here we assert the action endpoint behaves through the app session.
//
// @spec openspec/changes/align-claims-and-first-hour/specs/dashboard/spec.md#requirement-no-permanently-null-default-widgets
// @spec openspec/changes/align-claims-and-first-hour/specs/first-time-setup/spec.md#requirement-req-setup-pip-008--optional-demo-data-seed
import type { Page } from '@playwright/test'

import { expect, test } from '@playwright/test'
import { APP_LOAD_BUDGET_MS } from '../helpers/pipelinq.ts'

/**
 * Auto-dismiss the getting-started walkthrough tour whenever it overlays the
 * page — its `.cn-walkthrough__dim` intercepts pointer events and the tour
 * mounts asynchronously (and re-mounts on fresh storage states), so a
 * reactive locator handler beats a one-shot dismissal.
 */
async function autoDismissWalkthrough(page: Page): Promise<void> {
	// "Skip" advances one step at a time (the tour only closes after the
	// last step), so the handler must fire repeatedly — noWaitAfter keeps
	// Playwright from expecting the overlay to disappear after one click.
	await page.addLocatorHandler(
		page.locator('.cn-walkthrough'),
		async () => {
			await page
				.locator('.cn-walkthrough')
				.getByRole('button', { name: /Skip/i })
				.click()
		},
		{ noWaitAfter: true, times: 15 },
	)
}

test.describe('Operational dashboard — no permanently-null widgets', () => {
	test.beforeEach(async ({ page }) => {
		// The shared dev instance can be slow to fire `load`; DOMContentLoaded
		// is enough — the assertions below wait for the widgets themselves.
		//
		// One load, not two. The `reload()` that used to follow this goto was
		// left over from hash routing, where the goto did not remount the view.
		// The shell has routed on HISTORY since #1684, so this goto IS a full
		// document load onto `/operational` and the reload only re-rendered
		// what was already on screen — at 13 to 23 s a load on the CI runner
		// (6 workers, one `php -S`), out of a 60 s budget.
		await page.goto('/apps/pipelinq/operational', {
			waitUntil: 'domcontentloaded',
			timeout: APP_LOAD_BUDGET_MS,
		})
		await expect(page.locator('body')).not.toContainText('Internal Server Error')
	})

	/**
	 * Scenario: Operational dashboard renders no empty satisfaction tile.
	 * Scenario: Layout reflows without a hole.
	 *
	 * @spec openspec/changes/align-claims-and-first-hour/specs/dashboard/spec.md#requirement-no-permanently-null-default-widgets
	 */
	test('renders KPI row without the Customer Satisfaction tile', async ({
		page,
	}) => {
		await expect(
			page.getByRole('heading', { name: /Operational overview/i }),
		).toBeVisible({ timeout: 15000 })

		// The live KPI widgets are present…
		await expect(page.getByText('Lead Conversion Rate').first()).toBeVisible({
			timeout: 15000,
		})
		await expect(page.getByText(/Avg Request Resol/).first()).toBeVisible()

		// …but no Customer Satisfaction widget renders anywhere on the page.
		await expect(page.getByText(/Customer Satisfaction/i)).toHaveCount(0)
	})
})

test.describe('Demo-data seed setup action', () => {
	/**
	 * Scenario: Offered as an optional wizard step (action surface).
	 * Scenario: Idempotent re-run.
	 *
	 * The wizard exposes manifest setup step `demo-data` (run-action
	 * `seed-demo-data`); the action invokes DemoSeedService — the same
	 * write path as `occ pipelinq:demo:seed`. Because the gating wizard
	 * only appears on an unconfigured install, this test exercises the
	 * action through the authenticated app session.
	 *
	 * @spec openspec/changes/align-claims-and-first-hour/specs/first-time-setup/spec.md#requirement-req-setup-pip-008--optional-demo-data-seed
	 */
	test('seed-demo-data action succeeds and is idempotent', async ({ page }) => {
		// The idempotency pass scans every seed schema server-side, which can
		// take ~20s per run on a data-heavy instance — two runs need headroom.
		test.setTimeout(120000)
		await page.goto('/apps/pipelinq/', { timeout: APP_LOAD_BUDGET_MS })

		const run = async () =>
			await page.evaluate(async () => {
				const response = await fetch(
					'/index.php/apps/pipelinq/api/setup/action/seed-demo-data',
					{
						method: 'POST',
						headers: {
							'Content-Type': 'application/json',
							requesttoken: (
								window as unknown as { OC: { requestToken: string } }
							).OC.requestToken,
						},
						body: '{}',
					},
				)
				return { status: response.status, body: await response.json() }
			})

		const first = await run()
		expect(first.status).toBe(200)
		expect(first.body.success).toBe(true)

		// Idempotency: a second run creates nothing new ("0 demo object(s)"
		// seeded when everything is already present).
		const second = await run()
		expect(second.status).toBe(200)
		expect(second.body.success).toBe(true)
		expect(String(second.body.message)).toMatch(/Seeded 0 demo object/)
	})

	/**
	 * The demo dataset renders in the UI: seeded clients appear in the
	 * Clients list with the [Demo] marker.
	 *
	 * @spec openspec/changes/align-claims-and-first-hour/specs/first-time-setup/spec.md#requirement-req-setup-pip-008--optional-demo-data-seed
	 */
	test('seeded demo clients render in the Clients list', async ({ page }) => {
		test.setTimeout(90000)
		await autoDismissWalkthrough(page)
		// One load, not two: the same stale post-goto reload as above.
		await page.goto('/apps/pipelinq/clients', { timeout: APP_LOAD_BUDGET_MS })

		// Wait for the table to load rows.
		await expect(page.locator('table tbody tr').first()).toBeVisible({
			timeout: 20000,
		})

		// The list paginates (20/page); walk pages until the seeded demo
		// client shows (page 1 on a clean install; later pages on a
		// data-heavy dev instance). Bounded to 10 pages.
		const target = page.getByText('[Demo] Gemeente Zonnedael').first()
		let found = await target.isVisible().catch(() => false)
		for (let i = 0; i < 10 && !found; i++) {
			// Scope to the table pagination so the tour's own Next never matches.
			const next = page
				.locator('[class*="pagination"]')
				.getByRole('button', { name: 'Next' })
				.first()
			if (!(await next.isEnabled().catch(() => false))) break
			await next.click()
			await page.waitForTimeout(1500)
			found = await target.isVisible().catch(() => false)
		}

		expect(found).toBe(true)
	})
})
