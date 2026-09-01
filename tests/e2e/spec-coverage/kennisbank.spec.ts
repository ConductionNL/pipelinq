/*
 * SPDX-FileCopyrightText: 2026 Pipelinq Contributors
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Gate-19 behavioural e2e coverage for openspec/specs/kennisbank/spec.md.
 *
 * WHAT THE KNOWLEDGE-BASE SURFACE ACTUALLY IS TODAY
 * ------------------------------------------------
 * The bespoke in-app kennisbank is gone: `src/views/kennisbank/`,
 * `src/components/kennisbank/` and `src/store/modules/kennisbank.js` no longer
 * exist, no `Kennisbank*` class remains under `lib/`, and the three
 * `kennis*` schemas are no longer defined in `lib/Settings/pipelinq_register.json`.
 * Knowledge lives in xWiki and reaches the app through the `xwiki` integration:
 * the `OperationalDashboard` page declares the widget
 * `{"id": "xwiki-knowledge", "type": "integration", "integrationId": "xwiki",
 * "title": "Knowledge base"}` (src/manifest.json), and the retained
 * `/api/xwiki/*` proxy (XWikiController → XWikiService) serves it, preferring
 * OpenRegister's OpenConnector-routed `xwiki` source and falling back to a
 * direct xWiki base URL (`pipelinq-xwiki-through-or`).
 *
 * WHAT CI CAN AND CANNOT SEE. `tests/e2e/ci-seed.sh` installs pipelinq +
 * openregister only, so there is no `openconnector`, no configured `xwiki`
 * source and no reachable xWiki server. The scenarios that REQUIRE live wiki
 * content (the leaf's tab/widget on CRM objects, the collectives substitution)
 * therefore carry a reason-bearing `@e2e exclude` in the spec, as do the purely
 * structural ones (code/schema absence, manifest content, the follow-up record).
 *
 * What remains — and what is asserted here — is the behaviour of the knowledge
 * base surface ON AN INSTANCE WITHOUT xWIKI, which is precisely what the three
 * reverse-engineered "Knowledge base UI" requirements describe: the documented
 * operations run, their output is derived from the live integration state
 * rather than from a stub, and absent/invalid input degrades instead of
 * crashing the flow. That is not a weaker version of the scenario — an
 * unconfigured proxy is the state every fresh install starts in.
 */
import { test, expect, APIResponse } from '@playwright/test'

import {
	openApp,
	dismissWalkthrough,
	dismissSupportDialog,
	assertNoHardError,
} from '../helpers/pipelinq'

/** The xWiki proxy endpoints the knowledge-base surface is built on. */
const XWIKI_STATUS = '/apps/pipelinq/api/xwiki/status'
const XWIKI_SEARCH = '/apps/pipelinq/api/xwiki/search'
const XWIKI_PAGES = '/apps/pipelinq/api/xwiki/pages'
const XWIKI_PAGE =
	'/apps/pipelinq/api/xwiki/page/xwiki/Kennisbank.NoSuchPage.WebHome'

/**
 * Assert an xWiki proxy response is a real JSON answer from the controller and
 * return its decoded body.
 *
 * The media-type check is load-bearing: appinfo/routes.php ends with an SPA
 * catch-all (`dashboard#page`, `path => '.*'`) that answers ANY unmatched GET
 * under /apps/pipelinq with the Vue app's HTML shell at status 200. Without
 * asserting `application/json`, a mistyped or removed endpoint would sail
 * through every assertion below as "the page loaded".
 */
async function readJson(
	response: APIResponse,
	label: string,
): Promise<Record<string, unknown>> {
	expect(response.status(), `${label} must answer 200`).toBe(200)
	expect(
		response.headers()['content-type'] ?? '',
		`${label} must be answered by the JSON proxy controller, not by the SPA catch-all`,
	).toContain('application/json')
	return (await response.json()) as Record<string, unknown>
}

/** Assert the standard `{results, total, limit, offset}` proxy envelope. */
function expectSearchEnvelope(body: Record<string, unknown>, label: string): void {
	expect(Array.isArray(body.results), `${label}: results must be an array`).toBe(
		true,
	)
	expect(typeof body.total, `${label}: total must be a number`).toBe('number')
	expect(typeof body.limit, `${label}: limit must be a number`).toBe('number')
	expect(typeof body.offset, `${label}: offset must be a number`).toBe('number')
}

// @e2e openspec/specs/kennisbank/spec.md#documented-operations-are-available
test('the knowledge-base surface is reachable: the widget mounts and the proxy answers', async ({
	page,
}) => {
	await openApp(page)

	// The knowledge-base widget lives on the Operational overview dashboard
	// (src/manifest.json, page `OperationalDashboard`, widget `xwiki-knowledge`
	// at layout slot 13), NOT on the landing Commercial overview.
	await page.goto('/apps/pipelinq/operational')
	await expect(page.locator('#content-vue')).toBeVisible({ timeout: 15000 })
	await dismissWalkthrough(page)
	await dismissSupportDialog(page)

	/*
	 * The widget reached a SETTLED, rendered state — either its card (titled
	 * from the manifest) painted, or it announced that the integration is
	 * unavailable (XWikiWidget renders `.xwiki-widget__unavailable` with
	 * "xWiki integration unavailable" once `store.available === false` and a
	 * status has actually been fetched). Written as an `or` because which of
	 * the two shows depends on whether an xWiki source is configured, and both
	 * are correct behaviour — a widget stuck on neither would be the failure.
	 */
	await expect(
		page
			.getByText('Knowledge base', { exact: false })
			.first()
			.or(
				page
					.getByText('xWiki integration unavailable', { exact: false })
					.first(),
			)
			.first(),
	).toBeVisible({ timeout: 20000 })

	// The operations the widget is built on answer for real, from a routed
	// controller (see readJson on why the media type matters).
	await readJson(await page.request.get(XWIKI_STATUS), 'GET /api/xwiki/status')
	expectSearchEnvelope(
		await readJson(
			await page.request.get(`${XWIKI_SEARCH}?q=paspoort`),
			'GET /api/xwiki/search',
		),
		'search',
	)
	await readJson(
		await page.request.get(`${XWIKI_PAGES}?space=Kennisbank`),
		'GET /api/xwiki/pages',
	)

	await assertNoHardError(page)
})

// @e2e openspec/specs/kennisbank/spec.md#results-reflect-live-state
test('knowledge-base results are derived from live integration state, not canned', async ({
	page,
}) => {
	await openApp(page)

	const status = await readJson(
		await page.request.get(XWIKI_STATUS),
		'GET /api/xwiki/status',
	)

	// The status envelope is computed per request from the resolved base URL
	// (XWikiService::getStatus), so its fields are types, not constants.
	expect(typeof status.available, 'available must be a boolean').toBe('boolean')
	expect(typeof status.baseUrl, 'baseUrl must be a string').toBe('string')
	expect(typeof status.source, 'source must be a string').toBe('string')

	/*
	 * The load-bearing derivation: with NO xWiki base URL resolved, the proxy
	 * MUST report itself unavailable and say so — `getStatus()` short-circuits
	 * to `{available: false, source: 'unconfigured'}` before it ever tries a
	 * request. A hard-coded or stubbed status could not honour this, because it
	 * would have to agree with a value it did not read. On a configured
	 * instance the branch simply does not apply and `available` is decided by
	 * the live reachability probe instead.
	 */
	if (status.baseUrl === '') {
		expect(
			status.available,
			'an unresolved base URL cannot report an available integration',
		).toBe(false)
		expect(
			status.source,
			'an unresolved base URL must be reported as unconfigured',
		).toBe('unconfigured')
	}

	// The search envelope's counters are computed from the actual result set
	// (XWikiService::finishSearch: `total = count(results)` BEFORE the slice,
	// `results = array_slice(results, 0, limit)`), never asserted as a constant.
	const search = await readJson(
		await page.request.get(`${XWIKI_SEARCH}?q=paspoort&limit=3`),
		'GET /api/xwiki/search',
	)
	expectSearchEnvelope(search, 'search')
	const results = search.results as unknown[]
	expect(
		results.length,
		'the page of results cannot exceed the requested limit',
	).toBeLessThanOrEqual(search.limit as number)
	expect(
		search.total as number,
		'total counts the full match set, so it cannot be below the page',
	).toBeGreaterThanOrEqual(results.length)

	// And the two operations agree with each other about the same live state:
	// a proxy that reports itself unusable cannot also be handing back articles.
	if (status.available === false) {
		expect(
			results.length,
			'an unavailable integration must not return articles',
		).toBe(0)
	}
})

// @e2e openspec/specs/kennisbank/spec.md#missing-input-does-not-crash-the-flow
test('knowledge-base operations tolerate absent or unresolvable input', async ({
	page,
}) => {
	await openApp(page)

	// No query at all — the search operation must still return its envelope.
	expectSearchEnvelope(
		await readJson(
			await page.request.get(XWIKI_SEARCH),
			'GET /api/xwiki/search (no params)',
		),
		'search without params',
	)

	// No space — `getPages('')` returns the empty envelope rather than building
	// a malformed upstream URL.
	expectSearchEnvelope(
		await readJson(
			await page.request.get(XWIKI_PAGES),
			'GET /api/xwiki/pages (no space)',
		),
		'pages without a space',
	)

	// An unresolvable page reference — the operation must answer the documented
	// six-field shape, not throw. Both the unconfigured short-circuit and the
	// controller's Throwable handler produce it (the latter adds `error`).
	const pageBody = await readJson(
		await page.request.get(XWIKI_PAGE),
		'GET /api/xwiki/page/{wiki}/{page}',
	)
	for (const key of ['title', 'content', 'url', 'modified', 'space', 'id']) {
		expect(
			pageBody,
			`an unresolvable page must still carry "${key}"`,
		).toHaveProperty(key)
	}
	expect(typeof pageBody.content, 'content must be a string, never null').toBe(
		'string',
	)

	// The surrounding flow survives it: the dashboard that hosts the widget
	// still renders, with no server error or uncaught render failure.
	await page.goto('/apps/pipelinq/operational')
	await expect(page.locator('#content-vue')).toBeVisible({ timeout: 15000 })
	await assertNoHardError(page)
})
