/*
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Gate-19 behavioural e2e coverage for marketing-search-intelligence, phase 5
 * of the pipelinq marketing programme.
 *
 * WHAT THIS PROVES, AND WHAT IT CANNOT
 * ------------------------------------
 * The CI instance has OpenRegister and Pipelinq and nothing else: no
 * OpenConnector source, no Google credential, no Matomo, no hermiq, and no
 * route to any of them. That leaves six things observable, and they are the
 * six the spec's non-excluded scenarios name:
 *
 *   1. the three pages are reachable from the Marketing group and by their
 *      path-routed deep links;
 *   2. each renders the empty state that says WHY it is empty, rather than an
 *      empty table that reads like "there is nothing to report";
 *   3. the Keywords page issues ONE proposals request, not one per query;
 *   4. reading proposals creates nothing, and a confirmed proposal creates
 *      exactly one keywordTarget carrying its provenance and no volume or
 *      difficulty;
 *   5. the Matomo credential field refuses a value shaped like a raw token and
 *      accepts a reference;
 *   6. the Marketing intelligence settings section renders its fields, and the
 *      one credential field explains that the token lives in the broker.
 *
 * Everything else in the spec carries `@e2e exclude` with the reason, and is
 * asserted in PHPUnit instead. The derivations in particular are arithmetic
 * over Search Console rows this instance cannot obtain.
 *
 * Every settings call rides the real admin session through
 * `page.evaluate(fetch ...)`, as marketing-campaign-attribution.spec.ts does.
 *
 * ⚠️ NOT RUN. This spec was written against the running app's routes and
 * helpers and linted, but Playwright was not executed for it: the dev instance
 * serves a different checkout.
 */
import type { Page } from '@playwright/test'

import { expect, test } from '@playwright/test'
import {
	dismissSupportDialog,
	dismissWalkthrough,
	revealNavEntry,
} from '../helpers/pipelinq.ts'

const APP = '/index.php/apps/pipelinq'
const OR = '/index.php/apps/openregister/api/objects/pipelinq'
const CREDENTIAL_KEY = 'matomo.credential_ref'
const CRAWL_KEY = 'search.crawl_source'

/** One authenticated JSON call issued from inside the logged-in page. */
async function api(
	page: Page,
	method: string,
	path: string,
	body?: unknown,
): Promise<{ status: number; json: any; text: string }> {
	return await page.evaluate(
		async ({ method, path, body }) => {
			const res = await fetch(path, {
				method,
				headers: {
					'Content-Type': 'application/json',

					requesttoken: (window as any).OC?.requestToken || '',
					'OCS-APIREQUEST': 'true',
				},
				body: body === undefined ? undefined : JSON.stringify(body),
			})
			const text = await res.text()
			let json: any = null
			try {
				json = text ? JSON.parse(text) : null
			} catch {
				/* non-JSON */
			}
			return { status: res.status, json, text: text.slice(0, 400) }
		},
		{ method, path, body },
	)
}

/** Land on the app so `OC.requestToken` exists for the fetch helper. */
async function landOnApp(page: Page): Promise<void> {
	await page.goto(`/apps/pipelinq`)
	await expect(page.locator('#content-vue')).toBeVisible({ timeout: 15000 })
	await dismissWalkthrough(page)
	await dismissSupportDialog(page)
}

/**
 * Deep-link to an app route. The app is PATH-routed, so a deep link is
 * /index.php/apps/pipelinq/marketing/keywords and never a hash route.
 */
async function gotoRoute(page: Page, route: string): Promise<void> {
	await page.goto(`/apps/pipelinq${route}`)
	await expect(page.locator('#content-vue')).toBeVisible({ timeout: 15000 })
	await dismissWalkthrough(page)
	await dismissSupportDialog(page)
}

/** How many keywordTarget objects the register holds. */
async function keywordTargetCount(page: Page): Promise<number> {
	const read = await api(page, 'GET', `${OR}/keywordTarget?_limit=200`)
	expect(read.status, read.text).toBeLessThan(300)
	const rows: any[] = read.json?.results ?? read.json?.data ?? read.json ?? []
	return Array.isArray(rows) ? rows.length : 0
}

test.describe('Search intelligence pages', () => {
	// @e2e openspec/changes/marketing-search-intelligence/specs/marketing-keyword-intelligence/spec.md#the-page-renders-its-empty-state-before-anything-is-imported
	// @e2e openspec/changes/marketing-search-intelligence/specs/marketing-keyword-intelligence/spec.md#the-buckets-are-shown-on-the-keywords-page
	test('the Keywords page is reachable and says why it is empty', async ({
		page,
	}) => {
		await landOnApp(page)

		// The Marketing group renders COLLAPSED, and `nav, [role=navigation]`
		// would match Nextcloud's own app menu first, so the entry is reached
		// through the helper rather than by a raw locator.
		const entry = await revealNavEntry(page, 'Keywords')
		await expect(entry).toBeVisible({ timeout: 15000 })

		// One proposals request serves the whole page: four sections, one read.
		const requests: string[] = []
		page.on('request', (request) => {
			if (request.url().includes('/api/marketing/keyword-proposals')) {
				requests.push(request.url())
			}
		})

		await entry.click()
		await expect(page.getByRole('heading', { name: 'Keywords' })).toBeVisible({
			timeout: 15000,
		})

		const empty = page.getByTestId('keyword-intel-empty')
		await expect(empty).toBeVisible({ timeout: 15000 })
		await expect(empty).toContainText('No search data yet')
		await expect(empty).toContainText('Search Console')

		expect(
			requests.length,
			'the page must issue one proposals request, not one per query',
		).toBe(1)
	})

	// @e2e openspec/changes/marketing-search-intelligence/specs/marketing-keyword-intelligence/spec.md#no-crawl-reports-itself-as-no-crawl
	test('with no crawl source the proposals read says the gap check did not run', async ({
		page,
	}) => {
		await landOnApp(page)

		const cleared = await api(page, 'PUT', `${APP}/api/settings`, {
			[CRAWL_KEY]: '',
		})
		expect(cleared.status, cleared.text).toBe(200)

		const proposals = await api(
			page,
			'GET',
			`${APP}/api/marketing/keyword-proposals`,
		)
		expect(proposals.status, proposals.text).toBe(200)
		expect(proposals.json?.crawl?.crawled).toBe(false)
		expect(proposals.json?.crawl?.failure).toBe('not_configured')
		expect(proposals.json?.crawl?.reason).toBeTruthy()
		expect(proposals.json?.gaps).toEqual([])
		expect(proposals.json?.buckets?.length).toBe(4)
		expect(proposals.json?.window).toBeUndefined()
		expect(proposals.json?.from).toMatch(/^\d{4}-\d{2}-\d{2}$/)
	})

	// @e2e openspec/changes/marketing-search-intelligence/specs/marketing-competitor-watches/spec.md#the-page-renders-its-empty-state-and-names-the-setting
	test('the Competitors page is reachable and names the setting that is missing', async ({
		page,
	}) => {
		await gotoRoute(page, '/marketing/competitors')
		await expect(page.getByRole('heading', { name: 'Competitors' })).toBeVisible(
			{ timeout: 15000 },
		)

		// No egress source is configured on this instance, so the page must
		// say so rather than render an empty list of events.
		await expect(page.getByTestId('competitors-unconfigured')).toContainText(
			'Marketing intelligence',
			{ timeout: 15000 },
		)
	})

	// @e2e openspec/changes/marketing-search-intelligence/specs/marketing-analytics-connectors/spec.md#the-page-renders-its-empty-state-and-issues-one-read
	test('the Connection audit page is reachable and issues one read', async ({
		page,
	}) => {
		await landOnApp(page)

		const requests: string[] = []
		page.on('request', (request) => {
			if (request.url().includes('/api/marketing/connection-audit')) {
				requests.push(request.url())
			}
		})

		await gotoRoute(page, '/marketing/connection-audit')
		await expect(
			page.getByRole('heading', { name: 'Connection audit' }),
		).toBeVisible({ timeout: 15000 })

		const empty = page.getByTestId('connection-audit-empty')
		await expect(empty).toBeVisible({ timeout: 15000 })
		await expect(empty).toContainText('Nothing to compare yet')
		await expect(empty).toContainText('Mastodon')

		expect(
			requests.length,
			'the audit must be read once, not once per client',
		).toBe(1)
	})
})

test.describe('Keyword targets', () => {
	// @e2e openspec/changes/marketing-search-intelligence/specs/marketing-keyword-intelligence/spec.md#reading-proposals-creates-nothing
	// @e2e openspec/changes/marketing-search-intelligence/specs/marketing-keyword-intelligence/spec.md#confirming-a-proposal-creates-one-target-with-its-provenance
	test('reading proposals creates nothing, and confirming one creates a single target', async ({
		page,
	}) => {
		await landOnApp(page)

		const before = await keywordTargetCount(page)

		const first = await api(
			page,
			'GET',
			`${APP}/api/marketing/keyword-proposals`,
		)
		expect(first.status, first.text).toBe(200)
		const second = await api(
			page,
			'GET',
			`${APP}/api/marketing/keyword-proposals`,
		)
		expect(second.status, second.text).toBe(200)
		expect(
			await keywordTargetCount(page),
			'reading proposals must create nothing',
		).toBe(before)

		const stamp = Date.now()
		const created = await api(
			page,
			'POST',
			`${APP}/api/marketing/keyword-targets`,
			{
				term: `e2e woo verzoek ${stamp}`,
				status: 'use-more',
				proposalKind: 'striking-distance',
				intent: 'informational',
				notes: 'Created by the gate-19 spec.',
			},
		)
		expect(created.status, created.text).toBe(201)
		expect(created.json?.term).toBe(`e2e woo verzoek ${stamp}`)
		expect(created.json?.status).toBe('use-more')
		expect(created.json?.proposalKind).toBe('striking-distance')
		expect(created.json?.createdBy).toBeTruthy()

		// Nothing measures these two, so a zero would read as a measurement.
		expect(created.json?.volume ?? null).toBeNull()
		expect(created.json?.difficulty ?? null).toBeNull()

		expect(await keywordTargetCount(page)).toBe(before + 1)

		const listed = await api(page, 'GET', `${APP}/api/marketing/keyword-targets`)
		expect(listed.status, listed.text).toBe(200)
		expect(
			(listed.json?.targets ?? []).some(
				(target: any) => target.term === `e2e woo verzoek ${stamp}`,
			),
		).toBe(true)
	})

	// @e2e openspec/changes/marketing-search-intelligence/specs/marketing-keyword-intelligence/spec.md#an-unprivileged-session-is-refused
	test('a term outside the status vocabulary is refused and creates nothing', async ({
		page,
	}) => {
		await landOnApp(page)
		const before = await keywordTargetCount(page)

		const refused = await api(
			page,
			'POST',
			`${APP}/api/marketing/keyword-targets`,
			{
				term: 'e2e refused term',
				status: 'gebruik-meer',
			},
		)
		expect(refused.status, refused.text).toBe(400)
		expect(await keywordTargetCount(page)).toBe(before)

		const noTerm = await api(
			page,
			'POST',
			`${APP}/api/marketing/keyword-targets`,
			{
				term: '   ',
				status: 'watch',
			},
		)
		expect(noTerm.status, noTerm.text).toBe(400)
	})
})

test.describe('Marketing intelligence settings', () => {
	test.afterEach(async ({ page }) => {
		// Leave the instance as found.
		await landOnApp(page)
		await api(page, 'PUT', `${APP}/api/settings`, {
			[CREDENTIAL_KEY]: '',
			[CRAWL_KEY]: '',
		})
	})

	// @e2e openspec/changes/marketing-search-intelligence/specs/marketing-analytics-connectors/spec.md#the-section-shows-the-fields-and-no-secret-input
	test('the admin settings show the Marketing intelligence section', async ({
		page,
	}) => {
		await page.goto('/settings/admin/pipelinq')
		await expect(page.getByTestId('marketing-intel-crawl-source')).toBeVisible({
			timeout: 20000,
		})
		await expect(page.getByTestId('marketing-intel-matomo-source')).toBeVisible()
		await expect(
			page.getByTestId('marketing-intel-competitor-source'),
		).toBeVisible()
		await expect(page.getByTestId('marketing-intel-save')).toBeVisible()

		// The one credential field says where the token belongs.
		await expect(
			page.getByTestId('marketing-intel-matomo-credential'),
		).toBeVisible()
		await expect(page.locator('body')).toContainText('broker')
	})

	// @e2e openspec/changes/marketing-search-intelligence/specs/marketing-analytics-connectors/spec.md#a-pasted-token-is-refused-at-the-settings-write
	// @e2e openspec/changes/marketing-search-intelligence/specs/marketing-analytics-connectors/spec.md#a-reference-is-accepted
	test('a raw Matomo token is refused in the credential field and a reference is accepted', async ({
		page,
	}) => {
		await landOnApp(page)

		const reference = 'b7f4a9c1-2d3e-4f56-8a90-1b2c3d4e5f60'
		const accepted = await api(page, 'PUT', `${APP}/api/settings`, {
			[CREDENTIAL_KEY]: reference,
		})
		expect(accepted.status, accepted.text).toBe(200)
		expect(accepted.json?.config?.[CREDENTIAL_KEY]).toBe(reference)

		// Matomo's token_auth is 32 hexadecimal characters. A value of that
		// shape is a secret, and a secret never lives in a setting (ADR-064).
		const refused = await api(page, 'PUT', `${APP}/api/settings`, {
			[CREDENTIAL_KEY]: 'a1'.repeat(16),
		})
		expect(refused.status, refused.text).toBe(400)
		expect(refused.json?.error).toContain('broker')

		// The refused write left the stored reference alone.
		const after = await api(page, 'GET', `${APP}/api/settings`)
		expect(after.status, after.text).toBe(200)
		expect(after.json?.config?.[CREDENTIAL_KEY]).toBe(reference)
		expect(after.text).not.toContain('a1a1a1a1')
	})
})
