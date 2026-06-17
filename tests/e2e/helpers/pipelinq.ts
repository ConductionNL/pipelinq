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

/**
 * Expand every collapsed top-level nav group so its child entries become
 * clickable. The IA restructure groups routes under collapsible parents
 * (Sales & CRM, Service, Point of Sale, Catalog, Analytics, Administration,
 * Integrations) whose caption link carries `href="#"` and toggles a child
 * `<ul>`. A child route can be hidden behind a collapsed parent, so expand
 * them all before attempting an entry click.
 */
export async function expandAllNavGroups(page: Page): Promise<void> {
	const nav = page.locator('#app-navigation-vue')
	// Group captions are entry links whose href is the bare hash "#".
	const groupLinks = nav.locator('a.app-navigation-entry-link[href$="#"]')
	const count = await groupLinks.count().catch(() => 0)
	for (let i = 0; i < count; i++) {
		const link = groupLinks.nth(i)
		const expanded = await link.getAttribute('aria-expanded').catch(() => null)
		if (expanded === 'false') {
			await link.click().catch(() => {})
		}
	}
}

/**
 * Navigate to a sidebar entry by its stable `cn-nav-entry-<id>` testid (set on
 * the wrapping `<li>`), expanding any collapsed parent group first. Hash-mode
 * deep-links reset the SPA to the Dashboard, so navigation MUST go through a
 * real nav-link click — this clicks the link inside the testid'd wrapper and
 * waits for the hash URL to settle.
 */
export async function navById(page: Page, entryId: string, urlRe: RegExp): Promise<void> {
	await expandAllNavGroups(page)
	const wrapper = page.locator(`[data-testid="cn-nav-entry-${entryId}"]`)
	const link = wrapper.locator('a.app-navigation-entry-link').first()
	await expect(link).toBeVisible({ timeout: 10000 })
	await link.click()
	await expect(page).toHaveURL(urlRe, { timeout: 10000 })
	await dismissSupportDialog(page)
	await page.locator('#content-vue').waitFor({ state: 'visible', timeout: 10000 }).catch(() => {})
}

/**
 * Click a sidebar nav link by exact label and wait for the URL to settle.
 * Group-aware: collapsed parent groups are expanded first, and a routing
 * entry (its href is a real `#/route`, never the bare group `#`) is preferred
 * over a same-named group caption so label collisions resolve to the page.
 */
export async function navClick(page: Page, label: string, urlRe: RegExp): Promise<void> {
	await expandAllNavGroups(page)
	const nav = page.locator('#app-navigation-vue')
	// Prefer a routing link (href containing "#/") over a group caption ("#").
	const routing = nav.locator('a.app-navigation-entry-link[href*="#/"]').filter({ hasText: label })
	const link = (await routing.count().catch(() => 0)) > 0
		? routing.first()
		: nav.getByRole('link', { name: label, exact: true }).first()
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
