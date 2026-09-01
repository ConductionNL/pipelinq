/*
 * SPDX-FileCopyrightText: 2026 Pipelinq Contributors
 * SPDX-License-Identifier: EUPL-1.2
 *
 * A prospect is a client without a contract, not a separate data model. The
 * row action therefore creates a CLIENT, and the old "Convert to lead" path is
 * gone.
 *
 * That old path never worked: the service built a client and lead payload, the
 * controller returned them as JSON, and the frontend discarded the result. No
 * object was ever written. So there is no regression to protect here, only the
 * removal to confirm and the new label to check.
 *
 * Discovery needs a configured ICP and a KVK API key, both external. Rather
 * than skip silently when they are absent — which cannot tell "not configured"
 * from "broken" — the row test asserts the page reached one of its two known
 * states and only then inspects rows.
 */

import { test, expect, type Page } from '@playwright/test'

import { dismissSupportDialog, dismissWalkthrough } from '../helpers/pipelinq'

test.beforeEach(() => {
	test.slow()
})

/** Open the Prospects page. */
async function openProspects(page: Page) {
	await page.goto('/apps/pipelinq/prospects')
	await dismissWalkthrough(page)
	await dismissSupportDialog(page)
	await expect(page.locator('.prospects-view')).toBeVisible({ timeout: 20000 })
}

// @e2e openspec/specs/prospect-discovery/spec.md#requirement-prospect-to-client-conversion
test('the retired convert-to-lead endpoint is gone', async ({ request }) => {
	const response = await request.post(
		'/index.php/apps/pipelinq/api/prospects/create-lead',
		{ data: { tradeName: 'E2E probe' }, failOnStatusCode: false },
	)

	// Nextcloud answers 405 rather than 404 here: `/api/prospects` still exists
	// for GET, so the router matches the prefix and rejects the method. Both
	// mean the same thing — the route is gone — so the assertion is that it no
	// longer SUCCEEDS. Pinning it to 404 failed for the wrong reason.
	//
	// A 5xx also fails here, deliberately: it means the instance is broken and
	// this test proved nothing, which should never read as a pass.
	expect(
		response.status(),
		'POST /api/prospects/create-lead must no longer be routed',
	).toBeGreaterThanOrEqual(400)
	expect(
		response.status(),
		'a 5xx means the instance is broken, not that the route is gone',
	).toBeLessThan(500)
})

// @e2e openspec/specs/prospect-discovery/spec.md#requirement-prospect-to-client-conversion
test('prospect rows offer Add as client, never Convert to lead', async ({
	page,
}) => {
	await openProspects(page)

	const rows = page.locator('[data-testid^="prospect-row-"]')
	const empty = page.locator('.empty-content, .prospects-view .empty-content')

	// One of the two must be true, and asserting it means an unexpected third
	// state (a spinner that never resolves, a crash) fails rather than skips.
	await expect
		.poll(async () => (await rows.count()) > 0 || (await empty.count()) > 0, {
			message: 'the page must resolve to either rows or an empty state',
		})
		.toBe(true)

	if ((await rows.count()) === 0) {
		// Discovery is unconfigured on this instance. The label claim is still
		// checked, negatively: the retired wording must not appear anywhere.
		await expect(
			page.getByText(/Convert to lead/i),
			'the retired action label must be gone from the page',
		).toHaveCount(0)
		return
	}

	await expect(
		page.locator('[data-testid^="prospect-add-"]').first(),
		'each prospect row must offer Add as client',
	).toBeVisible({ timeout: 10000 })

	await expect(page.getByText(/Convert to lead/i)).toHaveCount(0)
})
