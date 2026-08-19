/*
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 * SPDX-License-Identifier: EUPL-1.2
 */
import { test, expect } from '@playwright/test'

import { assertAppShellServed, nextcloudErrorPage } from './helpers/pipelinq'

test.describe('Smoke', () => {

	test('app loads without server errors', async ({ page }) => {
		const response = await page.goto('/apps/pipelinq/')
		await expect(page).toHaveURL(/.*pipelinq/)
		await assertAppShellServed(page, response)
	})

	/**
	 * POSITIVE CONTROL for the assertion above.
	 *
	 * `assertAppShellServed` is built from three negative-ish checks, and an
	 * assertion nobody has watched fail is evidence about the assertion, not
	 * about the app. This test drives the SAME helper's building blocks at an
	 * app id that is genuinely not installed and requires them to fire.
	 *
	 * It also pins the exact confusion that made the old `not.toContainText(
	 * 'not installed')` assertion useless: pipelinq's own UI says "… is not
	 * installed or enabled" about optional siblings (Deck, Forms, Time manager,
	 * OpenConnector) while working perfectly. A real not-installed app looks
	 * nothing like that — it is an HTTP 404 carrying NC's "Page not found"
	 * guest chrome and no app shell at all. If NC ever starts serving 200 plus
	 * app chrome for an absent app, this test goes red and the smoke test above
	 * is known to be untrustworthy.
	 */
	test('the not-installed detector actually fires on an absent app', async ({ page }) => {
		const response = await page.goto('/apps/pipelinq-definitely-not-installed/')

		// The app shell must be absent.
		await expect(page.locator('#content-vue')).toHaveCount(0)

		// And the helper the smoke test above relies on must REJECT. Asserting
		// that the helper itself throws — rather than re-asserting its internals
		// here — is what makes this a control on the real thing: it stays valid
		// whichever of its three checks trips first. That matters because the
		// CI runner serves the app through `php -S` and a dev instance through
		// Apache, and the two need not agree on the exact status code for an
		// unknown app; pinning 404 here would make the control brittle in a way
		// that has nothing to do with what it is proving.
		let threw = false
		try {
			await assertAppShellServed(page, response)
		} catch {
			threw = true
		}
		expect(threw, 'assertAppShellServed must reject for an app that is not installed').toBe(true)

		// Belt and braces: on a stock Nextcloud this is the 404 guest chrome.
		// Kept as a non-blocking observation of WHICH failure mode occurred.
		// eslint-disable-next-line no-console
		console.log(`[positive control] status=${response?.status()} ncErrorChrome=${await nextcloudErrorPage(page).count()}`)
	})

	/**
	 * Rewritten for the manifest-driven app shell (was `test.skip` with
	 * TODO(#392)).
	 *
	 * The original assertion was `page.locator('nav').first()`, which is why it
	 * could not simply be un-skipped: the FIRST <nav> in a Nextcloud page is
	 * core's "Applications menu" in the header, not the app sidebar. That
	 * element is present and visible on every NC page including error pages, so
	 * the assertion would have passed without pipelinq rendering anything —
	 * green, but testing core chrome.
	 *
	 * The real contract is the app's own navigation container, populated with
	 * entries from the merged manifest (src/manifest.json + src/manifest.d/**,
	 * arranged by src/menu-layout.json), so an empty shell fails too.
	 */
	test('sidebar navigation is visible', async ({ page }) => {
		await page.goto('/apps/pipelinq/')
		const nav = page.locator('#app-navigation-vue')
		await expect(nav).toBeVisible({ timeout: 15000 })
		await expect(nav.locator('a.app-navigation-entry-link').first()).toBeVisible({ timeout: 15000 })
	})
})
