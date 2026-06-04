/*
 * SPDX-FileCopyrightText: 2026 Pipelinq Contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
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
	console.log(`[playwright globalSetup] bundle missing at ${BUNDLE_PATH}; running 'npm run build' once…`)
	execSync('npm run build', { cwd: APP_ROOT, stdio: 'inherit' })
}

async function ensureNextcloudReachable(baseURL: string): Promise<void> {
	const ctx = await request.newContext()
	try {
		const res = await ctx.get(`${baseURL}/status.php`, { failOnStatusCode: false })
		if (!res.ok()) {
			throw new Error(
				`Nextcloud status.php returned ${res.status()} at ${baseURL}. ` +
				'Make sure the docker container is running and reachable.',
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

async function globalSetup(config: FullConfig): Promise<void> {
	const baseURL = (config.projects[0]?.use?.baseURL as string | undefined)
		?? process.env.NEXTCLOUD_URL
		?? process.env.NC_BASE_URL
		?? 'http://localhost:8080'
	const user = process.env.ADMIN_USER ?? process.env.NC_ADMIN_USER ?? 'admin'
	const password = process.env.ADMIN_PASSWORD ?? process.env.NC_ADMIN_PASS ?? 'admin'

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
			`Login appears to have failed — still on ${currentUrl}. ` +
			'Check ADMIN_USER / ADMIN_PASSWORD (defaults admin/admin).',
		)
	}

	await context.storageState({ path: STORAGE_STATE })
	await browser.close()
}

export default globalSetup
