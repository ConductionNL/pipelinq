/*
 * SPDX-FileCopyrightText: 2026 Pipelinq Contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 *
 * Shared helpers for the spec-coverage e2e suite.
 *
 * Pipelinq mounts at #content-vue. Deep-link `page.goto('/apps/pipelinq/<route>')`
 * resets the SPA back to the Dashboard, so navigation between pages MUST go
 * through a sidebar nav-click. A fleet-wide `cn-support-dialog` can overlay the
 * content and intercept clicks — dismiss it before interacting.
 */
import { Page, expect, ConsoleMessage } from '@playwright/test'

/** Open the app at the dashboard and wait for the shell + nav to render. */
export async function openApp(page: Page): Promise<void> {
	await page.goto('/apps/pipelinq/')
	await expect(page.locator('#app-navigation-vue')).toBeVisible({ timeout: 15000 })
	await dismissSupportDialog(page)
}

/** Dismiss the fleet-wide support dialog if it is overlaying the content. */
export async function dismissSupportDialog(page: Page): Promise<void> {
	const dialog = page.locator('.cn-support-dialog, [data-testid="cn-support-dialog"]').first()
	if (await dialog.isVisible().catch(() => false)) {
		const close = dialog.getByRole('button', { name: /close|sluiten|dismiss|got it|×/i }).first()
		if (await close.isVisible().catch(() => false)) {
			await close.click().catch(() => {})
		} else {
			await page.keyboard.press('Escape').catch(() => {})
		}
		await dialog.waitFor({ state: 'hidden', timeout: 3000 }).catch(() => {})
	}
}

/** Click a sidebar nav link by exact label and wait for the URL to settle. */
export async function navClick(page: Page, label: string, urlRe: RegExp): Promise<void> {
	const link = page.locator('#app-navigation-vue').getByRole('link', { name: label, exact: true }).first()
	await expect(link).toBeVisible({ timeout: 10000 })
	await link.click()
	await expect(page).toHaveURL(urlRe, { timeout: 10000 })
	await dismissSupportDialog(page)
	// Give the view a beat to fetch + render its first surface.
	await page.locator('#content-vue').waitFor({ state: 'visible', timeout: 10000 }).catch(() => {})
}

/**
 * Known pre-existing APP-SHELL console errors that fire on every page because
 * they originate in CnAppRoot's mount-time registry validation and the
 * dashboard category prefetch — NOT in the page under test. These are tracked
 * as confirmed bugs (see BUGS in the spec-coverage report) and filtered here so
 * a page-level assertion can isolate NEW, page-specific errors.
 *
 *  1. LeadForecastTab registered with kind "tab" (unrecognised kind).
 *  2. billingCategory / exportJob object types not registered in the store.
 *  3. Nextcloud core "Failed to load user status" (core, not pipelinq).
 */
const KNOWN_SHELL_ERRORS = [
	/RegistryKindError.*LeadForecastTab.*unrecognised kind "tab"/i,
	/Object type "billingCategory" is not registered in the store/i,
	/Error fetching billingCategory collection/i,
	/Failed to load user status/i,
]

/**
 * Collect pipelinq-origin console errors + pageerrors. Resource 404s / favicon
 * noise and the known app-shell baseline errors are filtered. Returns a getter
 * yielding the deduped list of NEW, page-specific errors.
 */
export function trackPipelinqErrors(page: Page): () => string[] {
	const errors: string[] = []
	const noise = /Failed to load resource|favicon|net::ERR|\b404\b|Download the (React|Vue) DevTools|preload/i
	const onConsole = (m: ConsoleMessage) => {
		const t = m.text()
		if (m.type() !== 'error') return
		if (noise.test(t)) return
		if (KNOWN_SHELL_ERRORS.some((re) => re.test(t))) return
		errors.push(t)
	}
	const onError = (e: Error) => {
		if (!KNOWN_SHELL_ERRORS.some((re) => re.test(e.message))) errors.push('PAGEERROR: ' + e.message)
	}
	page.on('console', onConsole)
	page.on('pageerror', onError)
	return () => [...new Set(errors)]
}

/** Assert the page is not showing a hard server / render failure. */
export async function assertNoHardError(page: Page): Promise<void> {
	const body = page.locator('body')
	await expect(body).not.toContainText('Internal Server Error')
	await expect(body).not.toContainText('Uncaught Error')
}
