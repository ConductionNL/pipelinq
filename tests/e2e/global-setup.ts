/*
 * SPDX-FileCopyrightText: 2026 Pipelinq Contributors
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Playwright globalSetup — logs into Nextcloud once and persists the
 * resulting cookie jar / localStorage to `tests/e2e/.auth/user.json`.
 * Every spec then reuses that storage state via the `use.storageState`
 * setting in playwright.config.ts, so individual tests start from an
 * authenticated session without each one paying the login cost.
 *
 * Pattern reference: ADR-030 (hydra/openspec/architecture/), mirrored
 * from the canonical journeydoc template in hydra/templates/journeydoc/.
 */

import { chromium, request, type FullConfig } from '@playwright/test'
import { execSync } from 'child_process'
import * as path from 'path'
import * as fs from 'fs'
import { STORAGE_STATE } from './helpers/auth'
import { resolveBaseUrl } from './base-url'

const APP_ROOT = path.resolve(__dirname, '..', '..')
const BUNDLE_PATH = path.join(APP_ROOT, 'js', 'pipelinq-main.js')

/**
 * Ensure the webpack bundle exists before specs hit `/apps/pipelinq`.
 * On a fresh CI VM the shared quality.yml workflow runs `npm ci` +
 * `npx playwright install` but never `npm run build`, so without the
 * bundle the rendered page loads a 404 script tag and the Vue app
 * never mounts — every selector wait then times out.
 */
function ensureBundleBuilt(): void {
	if (fs.existsSync(BUNDLE_PATH)) {
		return
	}
	// eslint-disable-next-line no-console
	console.log(
		`[playwright globalSetup] bundle missing at ${BUNDLE_PATH}; running 'npm run build' once…`,
	)
	execSync('npm run build', { cwd: APP_ROOT, stdio: 'inherit' })
}

async function ensureNextcloudReachable(baseURL: string): Promise<void> {
	const ctx = await request.newContext()
	try {
		const res = await ctx.get(`${baseURL}/status.php`, {
			failOnStatusCode: false,
		})
		if (!res.ok()) {
			throw new Error(
				`Nextcloud status.php returned ${res.status()} at ${baseURL}. `
					+ 'Make sure the docker container is running and reachable.',
			)
		}
		const body = await res.json().catch(() => ({}))
		if (!body || body.installed !== true) {
			throw new Error(
				`Nextcloud at ${baseURL} is not installed (status.php = ${JSON.stringify(body)}).`,
			)
		}
	} finally {
		await ctx.dispose()
	}
}

/**
 * Cheap gate on the expensive step: prove the Vue app actually MOUNTS on two
 * routes before the suite is allowed to run.
 *
 * A whole class of bootstrap failures — a `@nextcloud/*` package left on its
 * Vue-2 major, a lazy chunk answered with `text/html` by NC's PHP router, a
 * frozen-export mutation, a dead router catch-all — produces an EMPTY SHELL on
 * every route while npm, ESLint, webpack and a byte-verified deploy all stay
 * clean. On scholiq that shape cost 37 minutes of e2e running against a bundle
 * that never booted. HTTP 200 is not evidence; only rendered app content is.
 *
 * Two routes, not one, because a router misconfiguration renders the shell fine
 * at `/` and nothing anywhere else.
 *
 * @param page An authenticated page.
 * @throws {Error} When the app fails to mount, with the console errors attached.
 */
async function assertAppBoots(page: import('@playwright/test').Page): Promise<void> {
	const consoleErrors: string[] = []
	page.on('console', (msg) => {
		if (msg.type() === 'error') consoleErrors.push(msg.text())
	})
	page.on('pageerror', (err) => consoleErrors.push(`pageerror: ${err.message}`))

	for (const route of [
		'/index.php/apps/pipelinq/',
		'/index.php/apps/pipelinq/#/clients',
	]) {
		consoleErrors.length = 0
		await page.goto(route, { waitUntil: 'domcontentloaded' })
		try {
			// The app's own mount host, with rendered children. `#pipelinq-app`
			// alone is served by the PHP template even when the bundle is dead —
			// requiring a child element is what makes this a MOUNT assertion
			// rather than an HTTP one.
			await page.waitForSelector('#pipelinq-app > *', { timeout: 45_000 })
		} catch {
			throw new Error(
				`[boot gate] The Pipelinq Vue app did not mount on ${route}. `
					+ 'The bundle loaded but rendered nothing — this is a bootstrap '
					+ 'failure, not a test failure, and running the suite against it '
					+ 'would produce a wall of meaningless red.\n'
					+ (consoleErrors.length
						? `Console errors:\n  ${consoleErrors.slice(0, 10).join('\n  ')}`
						: 'No console errors were captured.'),
			)
		}
	}
}

async function globalSetup(config: FullConfig): Promise<void> {
	// The `?? 'http://localhost:8080'` literal that used to close this chain is
	// gone — it silently pointed the suite at the SHARED dev container whenever
	// the environment was unset. resolveBaseUrl() throws instead.
	const baseURL =
		(config.projects[0]?.use?.baseURL as string | undefined) ?? resolveBaseUrl()
	const user = process.env.ADMIN_USER ?? process.env.NC_ADMIN_USER ?? 'admin'
	const password =
		process.env.ADMIN_PASSWORD ?? process.env.NC_ADMIN_PASS ?? 'admin'

	ensureBundleBuilt()
	await ensureNextcloudReachable(baseURL)
	fs.mkdirSync(path.dirname(STORAGE_STATE), { recursive: true })

	const browser = await chromium.launch()
	const context = await browser.newContext({ baseURL })
	const page = await context.newPage()

	await page.goto('/index.php/login')
	await page.locator('input[name="user"]').fill(user)
	await page.locator('input[name="password"]').fill(password)
	await page.locator('button[type="submit"], input[type="submit"]').first().click()
	// Nextcloud bounces to /apps/dashboard/ on success. Wait for the
	// global header, which only renders on authenticated pages.
	await page.waitForSelector('#header, header.header', { timeout: 30_000 })
	const currentUrl = page.url()
	if (/\/login(\?|$|\/)/.test(currentUrl)) {
		throw new Error(
			`Login appears to have failed — still on ${currentUrl}. `
				+ 'Check ADMIN_USER / ADMIN_PASSWORD (defaults admin/admin).',
		)
	}

	// Stand the non-gating setup wizard down for every spec.
	//
	// CnAppRoot opens it whenever the server reports an OPTIONAL setup step
	// as outstanding, and pipelinq declares five actionable ones (currency,
	// provision, demo-data, organisation, integrations). It renders over the
	// shell, so a click on anything behind it does not fail fast — it waits
	// out the full timeout. That surfaces as scattered 'did not mount' and
	// 'not reachable' failures across unrelated specs rather than one cause.
	//
	// Seeded here so it lands in the persisted storageState every spec
	// reuses, rather than being dismissed reactively per test, which races
	// the dialog's enter transition. The key is versioned
	// (`cn-setup-wizard-dismissed:<appId>:<setup.version>`), so a range is
	// seeded: bumping manifest.setup.version must not silently re-open the
	// wizard across the whole suite.
	await page.evaluate(() => {
		try {
			for (let v = 0; v <= 20; v++) {
				window.localStorage.setItem(
					`cn-setup-wizard-dismissed:pipelinq:${v}`,
					'1',
				)
			}
		} catch (e) {
			/* storage blocked in this context */
		}
	})

	await context.storageState({ path: STORAGE_STATE })
	await assertAppBoots(page)
	await browser.close()
}

export default globalSetup
