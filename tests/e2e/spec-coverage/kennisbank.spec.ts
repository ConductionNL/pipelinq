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
 * Knowledge lives in xWiki and reaches the app through OpenRegister's `xwiki`
 * integration only: the `OperationalDashboard` page declares a `kb-search`
 * widget (`xwiki-knowledge`) and the client page a Knowledge base sidebar tab
 * with the `xwiki` integration. Both read OpenRegister's
 * `/api/integrations/xwiki/*` endpoints; pipelinq's own `/api/xwiki/*` proxy is
 * retired (retire-xwiki-proxy).
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
 * crashing the flow. That is not a weaker version of the scenario: an
 * unconfigured source is the state every fresh install starts in.
 */
import type { APIResponse } from '@playwright/test'

import { expect, test } from '@playwright/test'
import { assertNoHardError, gotoAppRoute, openApp } from '../helpers/pipelinq.ts'

/** OpenRegister's xWiki integration search, which kb-search reads. */
const XWIKI_SEARCH = '/apps/openregister/api/integrations/xwiki/search'

/**
 * Read an answer of OpenRegister's xWiki search: the `{results, total, limit,
 * offset}` envelope (200) or the unavailable answer with its cause (503).
 *
 * The media-type check is load-bearing: an unmatched route would be answered
 * by an HTML shell, which must never pass as "the search answered".
 */
async function readSearch(
	response: APIResponse,
	label: string,
): Promise<{ status: number, body: Record<string, unknown> }> {
	expect([200, 503], `${label} must answer 200 or the documented 503`).toContain(
		response.status(),
	)
	expect(
		response.headers()['content-type'] ?? '',
		`${label} must be answered by the JSON controller`,
	).toContain('application/json')
	const body = (await response.json()) as Record<string, unknown>
	if (response.status() === 503) {
		const details = body.details as Record<string, unknown> | undefined
		expect(typeof details?.cause, `${label}: an unavailable source names its cause`).toBe(
			'string',
		)
	} else {
		expect(Array.isArray(body.results), `${label}: results must be an array`).toBe(true)
		expect(typeof body.total, `${label}: total must be a number`).toBe('number')
	}
	return { status: response.status(), body }
}

// @e2e openspec/specs/kennisbank/spec.md#documented-operations-are-available
test('the knowledge-base surface is reachable: the widget mounts and the search answers', async ({
	page,
}) => {
	// The knowledge-base widget lives on the Operational overview dashboard.
	await gotoAppRoute(page, '/operational')

	await expect(
		page.getByText('Knowledge base', { exact: false }).first(),
	).toBeVisible({ timeout: 20000 })

	await readSearch(
		await page.request.get(`${XWIKI_SEARCH}?q=paspoort`),
		'GET /api/integrations/xwiki/search',
	)

	await assertNoHardError(page)
})

// @e2e openspec/specs/kennisbank/spec.md#results-reflect-live-state
test('knowledge-base results are derived from live integration state, not canned', async ({
	page,
}) => {
	await openApp(page)

	const { status, body } = await readSearch(
		await page.request.get(`${XWIKI_SEARCH}?q=paspoort&limit=3`),
		'GET /api/integrations/xwiki/search',
	)

	if (status === 503) {
		// An unconfigured or unreachable source says so instead of handing
		// back articles: a canned answer could not know the source is gone.
		expect(body.results, 'an unavailable source returns no articles').toBeUndefined()
		return
	}

	// The counters are computed from the actual result set.
	const results = body.results as unknown[]
	expect(
		results.length,
		'the page of results cannot exceed the requested limit',
	).toBeLessThanOrEqual(body.limit as number)
	expect(
		body.total as number,
		'total counts the full match set, so it cannot be below the page',
	).toBeGreaterThanOrEqual(results.length)
})

// @e2e openspec/specs/kennisbank/spec.md#missing-input-does-not-crash-the-flow
test('knowledge-base operations tolerate absent or unresolvable input', async ({
	page,
}) => {
	await openApp(page)

	// No query at all: the search still answers its envelope or its 503.
	await readSearch(
		await page.request.get(XWIKI_SEARCH),
		'GET /api/integrations/xwiki/search (no params)',
	)

	// The surrounding flow survives it: the dashboard that hosts the widget
	// still renders, with no server error or uncaught render failure.
	await page.goto('/apps/pipelinq/operational')
	await expect(page.locator('#content-vue')).toBeVisible({ timeout: 15000 })
	await assertNoHardError(page)
})
