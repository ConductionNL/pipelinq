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
import { test, expect, Page } from '@playwright/test'

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
		await page.goto('/apps/pipelinq/operational', {
			waitUntil: 'domcontentloaded',
		})
		await expect(page.locator('body')).not.toContainText('Internal Server Error')
		await page.reload({ waitUntil: 'domcontentloaded' })
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
		await page.goto('/apps/pipelinq/')

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
		await page.goto('/apps/pipelinq/clients')
		await page.reload()

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
