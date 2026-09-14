// SPDX-License-Identifier: EUPL-1.2
// Copyright (C) 2026 Conduction B.V.

import type { APIRequestContext, Page } from '@playwright/test'

import { expect, test } from '@playwright/test'
import {
	appUrl,
	clickHeaderAction,
	dismissSupportDialog,
	dismissWalkthrough,
	gotoAppRoute,
	openApp,
	revealNavEntryByTestId,
} from './helpers/pipelinq.ts'

/**
 * The Integrations page over integriq's connection registry
 * (adopt-connection-registry, pipelinq#1939).
 *
 * WHERE THE ROWS COME FROM. The rows are integriq's `app_connection` objects,
 * synced from `lib/Settings/connections.json`, with `app` equal to `pipelinq`.
 * Pipelinq writes no row: a check sends integriq a report and integriq decides
 * the status (hydra connection-registry D4). So this spec needs integriq
 * installed and synced, and reads the rows from
 * `/apps/openregister/api/objects/integriq/app_connection?app=pipelinq`.
 *
 * NOT RUN when written. It was authored beside the change and has not yet run
 * against an instance with integriq's registry installed (tasks.md 4.1).
 *
 * Locale: nothing forces the language of the E2E instance, so no assertion
 * reads an English label. Status is read back over the API, and the page is
 * addressed by route, by nav test id and by each connection's own title,
 * which connections.json declares and nothing translates.
 */

/** The keys connections.json declares, in page order. */
const DECLARED_KEYS = [
	'cti',
	'social-mastodon',
	'social-bluesky',
	'social-linkedin',
	'social-x',
	'social-facebook',
	'social-instagram',
	'social-threads',
	'berichtenbox',
	'mail-provider',
]

/** Integriq's objects endpoint for the connection rows. */
const CONNECTIONS_API = '/index.php/apps/openregister/api/objects/integriq/app_connection'

/** The four app-config keys the Berichtenbox row waits for. */
const BERICHTENBOX_KEYS = ['logius_client_id', 'logius_client_secret', 'pki_cert', 'pki_key']

/**
 * Pipelinq's rows in integriq's registry, keyed by connection key.
 *
 * `app` is a BARE filter key: the objects endpoint reads `filter[app]` as a
 * filter on nothing and answers the empty set without an error.
 *
 * @param request The authenticated request context.
 */
async function connectionsByKey(request: APIRequestContext): Promise<Record<string, any>> {
	const res = await request.get(`${CONNECTIONS_API}?app=pipelinq&_limit=200`)
	expect(res.ok(), `list integriq/app_connection -> ${res.status()}`).toBeTruthy()
	const body = await res.json()
	const byKey: Record<string, any> = {}
	for (const row of body.results ?? []) {
		// A row from another app here means the filter was dropped, not that
		// pipelinq owns it.
		expect(String(row.app), 'a connection row from another app').toBe('pipelinq')
		byKey[String(row.key)] = row
	}
	return byKey
}

/**
 * Open the Integrations page the way the menu entry does, with its preset.
 *
 * @param page The Playwright page.
 */
async function openIntegrations(page: Page): Promise<void> {
	await gotoAppRoute(page, '/settings/integrations?app=pipelinq')
	await expect(page.locator('.cn-index-page')).toBeVisible({ timeout: 30_000 })
}

/**
 * Call a pipelinq endpoint from the page, so the session's request token
 * travels with it.
 *
 * @param page The Playwright page, already on a pipelinq route.
 * @param url The API path.
 */
async function getFromPage(page: Page, url: string): Promise<{ status: number, body: any }> {
	return page.evaluate(async (target) => {
		const res = await fetch(target, {
			headers: { requesttoken: (window as any).OC?.requestToken || '' },
		})
		const text = await res.text()
		return { status: res.status, body: text ? JSON.parse(text) : null }
	}, url)
}

test.describe('Integrations', () => {
	// @e2e admin-settings::the-declaration-names-ten-connections-in-page-order
	test('lists the ten declared connections in declared order', async ({ page, request }) => {
		const byKey = await connectionsByKey(request)
		expect(Object.keys(byKey).sort()).toEqual([...DECLARED_KEYS].sort())

		const ordered = [...DECLARED_KEYS].sort((a, b) => byKey[a].order - byKey[b].order)
		expect(ordered).toEqual(DECLARED_KEYS)

		await openIntegrations(page)
		for (const key of DECLARED_KEYS) {
			await expect(
				page.getByRole('row', { name: new RegExp(byKey[key].title, 'i') }).first(),
				`row for ${key}`,
			).toBeVisible({ timeout: 15_000 })
		}
	})

	// @e2e admin-settings::the-menu-opens-the-page-on-pipelinqs-own-rows
	test('the settings entry opens the page preset to pipelinq', async ({ page }) => {
		await openApp(page)
		const entry = await revealNavEntryByTestId(page, 'ConnectionsMenu')
		await Promise.all([
			page.waitForURL(/\/settings\/integrations\?app=pipelinq$/, { timeout: 30_000 }),
			entry.click(),
		])
		await dismissWalkthrough(page)
		await dismissSupportDialog(page)
		await expect(page.locator('.cn-index-page')).toBeVisible({ timeout: 30_000 })
	})

	// @e2e admin-settings::berichtenbox-names-the-keys-it-waits-for
	test('Berichtenbox reads not configured and names the keys it waits for', async ({ page, request }) => {
		const row = (await connectionsByKey(request)).berichtenbox
		expect(row, 'no declared row for berichtenbox').toBeTruthy()

		// The instance may carry Logius credentials. Only an instance without
		// them can show the unconfigured claim, and then the claim must be exact.
		test.skip(row.status === 'configured', 'this instance has the four Berichtenbox keys set')

		expect(row.status).toBe('unconfigured')
		for (const key of BERICHTENBOX_KEYS) {
			expect(String(row.statusMessage)).toContain(key)
		}
		expect(String(row.statusMessage)).toContain('occ config:app:set')
		expect(row.settingsUrl || '').toBe('')

		await openIntegrations(page)
		const cells = page.getByRole('row', { name: /Berichtenbox/ }).first()
		await expect(cells).toBeVisible({ timeout: 15_000 })
		await expect(cells.getByRole('link')).toHaveCount(0)
	})

	// @e2e admin-settings::add-integration-goes-to-integriq-not-to-a-form
	test('sends Add integration to integriq instead of offering a form', async ({ page }) => {
		await openIntegrations(page)

		// No generic Add button: a row nothing declared has nothing to check.
		await expect(page.locator('[data-testid="cn-cta-primary"]')).toHaveCount(0)

		await Promise.all([
			page.waitForURL(/\/apps\/integriq\/connections\?app=pipelinq&link=1$/, { timeout: 30_000 }),
			clickHeaderAction(page, /Add integration|Integratie toevoegen/i),
		])
	})

	// @e2e admin-settings::a-cti-test-reaches-the-row
	test('a CTI Test connection reaches the cti row', async ({ page, request }) => {
		await gotoAppRoute(page, '/')
		const result = await getFromPage(page, appUrl('/api/cti/test-connection'))
		expect(result.status, 'CTI test-connection').toBe(200)

		const cti = (await connectionsByKey(request)).cti
		// Integriq takes the newer of report and probe (D4 rule 4), and the cti
		// row has no linked source, so the report is the row.
		if (result.body.ok === true) {
			expect(cti.status).toBe('configured')
		} else if (String(result.body.platform ?? '') === '') {
			expect(cti.status).toBe('unconfigured')
		} else {
			expect(cti.status).toBe('error')
		}
		expect(String(cti.checkedAt || '')).not.toBe('')
	})

	// @e2e admin-settings::the-social-readiness-reaches-all-seven-rows
	test('opening Social accounts reports every network', async ({ page, request }) => {
		await gotoAppRoute(page, '/social-accounts')
		const listed = await getFromPage(page, appUrl('/api/social-accounts'))
		expect(listed.status, 'social-accounts list').toBe(200)
		const readiness = listed.body.readiness ?? {}

		const expected: Record<string, string> = {
			ready: 'configured',
			preview: 'limited',
			not_configured: 'unconfigured',
		}
		const byKey = await connectionsByKey(request)
		for (const [network, entry] of Object.entries<any>(readiness)) {
			const row = byKey[`social-${network}`]
			expect(row, `no declared row for social-${network}`).toBeTruthy()
			expect(row.status, `social-${network}`).toBe(expected[entry.state])
		}
	})
})
