/*
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 * SPDX-License-Identifier: EUPL-1.2
 *
 * The bottom-left app chrome, in a browser (ADR-114).
 *
 * gate-107 reads the manifest and can prove the entries are DECLARED. It
 * cannot prove they RENDER, and this programme has already produced three
 * defects of exactly that shape: an icon name that is not registered renders
 * NO glyph (no fallback, no console error, and four apps shipped it), an entry
 * whose `route` names a page the app does not host renders a row that goes
 * nowhere, and `nav.includePersonalSettings: false` silently removed the entry
 * reaching the user's notification preferences in two apps.
 *
 * ⚠️ SCOPE EVERY SELECTOR TO `[data-testid="cn-nav"]`. An unscoped selector
 * also matches Nextcloud's own user menu, which is attached-but-hidden:
 * `waitFor({state:'attached'})` passes on it and the click never becomes
 * actionable, so the spec fails with "Target page has been closed" — a timeout
 * wearing a crash's clothes.
 *
 * ⚠️ SETTINGS ENTRIES ARE ATTACHED, NOT VISIBLE, inside a collapsed foldout.
 */

import { expect, test } from '@playwright/test'

const APP_BASE = '/index.php/apps/pipelinq'

test.describe('app chrome (ADR-114)', () => {
	test.beforeEach(async ({ page }) => {
		await page.goto(`${APP_BASE}/`, { waitUntil: 'domcontentloaded' })
		await expect(page.locator('[data-testid="cn-nav"]')).toBeVisible({
			timeout: 30_000,
		})
	})

	test('the footer reads Documentation, Store, Reports, Features & roadmap, each with a glyph', async ({
		page,
	}) => {
		const footer = page.locator(
			'[data-testid="cn-nav"] .cn-app-nav__footer-list',
		)
		await expect(footer).toBeAttached({ timeout: 15_000 })

		const rows = footer.locator('li')
		const texts = (await rows.allInnerTexts())
			.map((t) => t.trim())
			.filter(Boolean)

		// ORDER is the rule, not the numbers. This app runs its footer at
		// 160/180/200/230 while openregister runs 1/2, and both read correctly:
		// ADR-114 fixes the sequence and leaves the numbers to the app.
		const seen = texts.filter((t) =>
			/Documentation|Store|Reports|roadmap/i.test(t),
		)
		expect(seen.length).toBe(4)
		expect(seen[0]).toMatch(/Documentation/i)
		expect(seen[1]).toMatch(/Store/i)
		expect(seen[2]).toMatch(/Reports/i)
		expect(seen[3]).toMatch(/roadmap/i)

		for (const row of await rows.all()) {
			await expect(
				row.locator('svg, .material-design-icon').first(),
			).toBeAttached()
		}
	})

	test('Reports carries all six reports as cards', async ({ page }) => {
		await page
			.locator(
				'[data-testid="cn-nav"] [data-testid="cn-nav-entry-ReportsMenu"]',
			)
			.click()
		await expect(page).toHaveURL(/\/apps\/pipelinq\/reports(\?|$)/, {
			timeout: 15_000,
		})

		// Named individually rather than counted. A count assertion reds on
		// ADDING a report, passes on a swap, and never names what went missing;
		// this fleet has already been bitten by exactly that.
		//
		// ⚠️ Match the card-text PREFIX: a loose `hasText: 'Reporting'` also
		// matches "Contact reporting" and "Loyalty reporting".
		for (const label of [
			'Reporting',
			'Contact reporting',
			'Channel analytics',
			'Agent performance',
			'Forecast',
			'Loyalty reporting',
		]) {
			await expect(page.getByText(label, { exact: true }).first()).toBeVisible(
				{ timeout: 15_000 },
			)
		}
	})

	test('the report pages behind the cards are still routable', async ({
		page,
	}) => {
		test.slow()

		// Three full SPA loads in one test. The default 30s budget covers one
		// comfortably and three only on a fast instance, so this timed out on a
		// shared container while every page actually rendered — a slow
		// environment wearing a broken-route's clothes.
		test.setTimeout(120_000)

		// Carding a report must not take its route with it: deep links and the
		// dashboard widgets' viewAllRoute address these by path (ADR-044
		// Decision 5).
		for (const path of [
			'/rapportage',
			'/rapportage/channels',
			'/rapportage/agents',
		]) {
			// 🔴 `domcontentloaded`, NOT the default `load`. Nextcloud's
			// notification poll keeps the network busy, so waiting for the load
			// event waits for something that does not settle — the loop dies
			// partway through and names whichever route it was on, which reads
			// as a broken route. The SPA mounts after DOM ready, and the
			// assertions below are what prove the mount.
			await page.goto(`${APP_BASE}${path}`, {
				waitUntil: 'domcontentloaded',
			})
			await expect(page).toHaveURL(new RegExp(`${path}(\\?|$)`), {
				timeout: 15_000,
			})
			await expect(page.locator('[data-testid="cn-nav"]')).toBeVisible()
		}
	})

	test('Store opens the hosted store surface and asks nothing of the network', async ({
		page,
	}) => {
		// The whole fleet's Store is one declarative surface: openregister hosts
		// the plane and each app declares a `type:"store"` page, so no app ships
		// a store controller. ADR-080 Decision 4 requires that with no registry
		// configured it renders the app's built-in items and makes NO network
		// call, and that clause is the reason a Store row is allowed to exist
		// on an instance that has never been pointed at a registry.
		//
		// Asserted here rather than in twelve copies: this is shared code, and
		// a per-app copy of this test would pass or fail identically.
		const calls: string[] = []
		page.on('request', (r) => {
			const u = r.url()
			if (
				!u.startsWith('http://localhost')
				&& !u.startsWith('https://localhost')
			) {
				calls.push(u)
			}
		})

		const footer = page.locator(
			'[data-testid="cn-nav"] .cn-app-nav__footer-list',
		)
		await footer
			.getByRole('link', { name: /^Store$/ })
			.first()
			.click()

		await expect(page).toHaveURL(/\/apps\/pipelinq\/store(\?|$)/, {
			timeout: 15_000,
		})
		await expect(page.locator('[data-testid="cn-nav"]')).toBeVisible()

		// No off-instance request. A store that phoned home on open would be a
		// privacy regression nobody would see in a manifest.
		expect(calls).toEqual([])
	})

	test('the settings foldout carries Personal settings, Admin settings and Flows', async ({
		page,
	}) => {
		const nav = page.locator('[data-testid="cn-nav"]')

		await expect(nav.locator('[data-testid="cn-nav-settings"]')).toBeAttached({
			timeout: 15_000,
		})
		await expect(
			nav.locator('[data-testid="cn-nav-personal-settings"]'),
		).toBeAttached()
		await expect(
			nav.locator('[data-testid="cn-nav-entry-FlowsMenu"]'),
		).toBeAttached()

		// ⚠️ The testid is on the <li> WRAPPER, not the <a>. Asserting href on
		// the wrapper reads back null and fails against a real browser, which
		// is invisible to `playwright test --list`.
		const admin = nav.locator('[data-testid="cn-nav-admin-settings"]')
		await expect(admin).toBeAttached()
		await expect(admin.locator('a').first()).toHaveAttribute(
			'href',
			/\/settings\/admin\/pipelinq$/,
		)
	})

	test('Pipelines and BI export stay in the settings foldout, not the footer', async ({
		page,
	}) => {
		// Both configure how the service runs; neither is a reading of what
		// happened, so ADR-114 keeps them out of the four-item footer. If a
		// later sweep promotes one, the footer assertion above would start
		// failing for a reason that is hard to read — this names it directly.
		const nav = page.locator('[data-testid="cn-nav"]')
		for (const id of ['Pipelines', 'ExportJobs']) {
			await expect(
				nav.locator(`[data-testid="cn-nav-entry-${id}"]`),
			).toBeAttached({ timeout: 15_000 })
		}
		const footer = nav.locator('.cn-app-nav__footer-list')
		await expect(
			footer.getByRole('link', { name: /^(Pipelines|BI export)$/ }),
		).toHaveCount(0)
	})
})
