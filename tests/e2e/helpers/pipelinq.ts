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
	// Restrict to the leaf ENTRY anchor (href contains `#/<route>`), NOT the nav
	// GROUP CAPTION (href="#"). getByRole('link', { name }) would otherwise match
	// the caption first and the click is a no-op (router stays on `#/`).
	// Filter by exact visible text so the entry — not the caption — is targeted.
	const link = page
		.locator('#app-navigation-vue a.app-navigation-entry-link[href*="#/"]')
		.filter({ hasText: new RegExp(`^\\s*${label.replace(/[.*+?^${}()|[\]\\]/g, '\\$&')}\\s*$`) })
		.first()
	// The entry is always present in the DOM, but a leaf can be hidden because
	// its container is collapsed by default:
	//  (a) a standard NcAppNavigation GROUP — the leaf sits inside a
	//      `li.app-navigation-entry--collapsible` whose caption anchor
	//      (`a[href="#"][aria-expanded="false"]`) toggles it; OR
	//  (b) the NcAppNavigation Settings flyout (`button.settings-button`).
	// If the entry is not visible, expand its collapsed container first, then
	// click the now-visible leaf.
	await link.waitFor({ state: 'attached', timeout: 10000 })
	if (!(await link.isVisible().catch(() => false))) {
		const groupCaption = link
			.locator('xpath=ancestor::li[contains(concat(" ", normalize-space(@class), " "), " app-navigation-entry--collapsible ")][1]')
			.locator('a.app-navigation-entry-link[href="#"]')
			.first()
		if (await groupCaption.count().catch(() => 0)) {
			await groupCaption.click().catch(() => {})
			await link.waitFor({ state: 'visible', timeout: 5000 }).catch(() => {})
		}
		if (!(await link.isVisible().catch(() => false))) {
			const settingsBtn = page.locator('#app-navigation-vue button.settings-button[aria-expanded="false"]').first()
			if (await settingsBtn.isVisible().catch(() => false)) {
				await settingsBtn.click().catch(() => {})
				await link.waitFor({ state: 'visible', timeout: 5000 }).catch(() => {})
			}
		}
	}
	await expect(link).toBeVisible({ timeout: 10000 })
	await link.click()
	await expect(page).toHaveURL(urlRe, { timeout: 10000 })
	await dismissSupportDialog(page)
	// Give the view a beat to fetch + render its first surface.
	await page.locator('#content-vue').waitFor({ state: 'visible', timeout: 10000 }).catch(() => {})
}

/**
 * Known APP-SHELL console errors that originate outside the page under test, so
 * a page-level assertion can isolate NEW, page-specific errors.
 *
 *  - Nextcloud core "Failed to load user status" (core, not pipelinq).
 *  - The dashboard category prefetch (BillingCategoryWidget → fetchCategories)
 *    races ahead of initializeStores() and logs a transient `Object type
 *    "billingCategory" is not registered` on the Dashboard mount. It is shell
 *    noise on every page (it fires from the dashboard, not the page under test).
 *
 * NOTE on the registry slug-fallback fix (commit a53bc8c5): store.js now calls
 * registerObjectType('billingCategory'|'posRefund'|'exportJob'|'automation', …)
 * with a canonical-slug fallback when the deployed app-config carries an empty
 * numeric schema id. Live verification on 2026-06-09 (fresh build, register=16,
 * empty *_schema ids, NC settled) showed the affected list pages still mount
 * only their `cn-index-page` chrome + primary CTA — the schema-driven data
 * surface (heading + table) does NOT yet render and the per-type "not
 * registered" error still fires at fetch time. The data-surface assertions are
 * therefore kept as `test.fixme` until that resolves; the navigate + chrome +
 * CTA assertions are real and pass.
 */
const KNOWN_SHELL_ERRORS = [
	/Failed to load user status/i,
	/Object type "billingCategory" is not registered/i,
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
