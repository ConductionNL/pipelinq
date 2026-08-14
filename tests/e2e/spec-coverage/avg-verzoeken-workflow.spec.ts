/*
 * SPDX-FileCopyrightText: 2026 Pipelinq Contributors
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Gate-19 behavioural e2e coverage for
 * openspec/specs/avg-verzoeken-workflow/spec.md.
 *
 * This capability is a HANDOFF spec: pipelinq implements no AVG workflow of its
 * own and delegates the whole DSAR lifecycle to OpenRegister's case subsystem.
 * Almost every scenario is therefore a claim about ANOTHER app's surface, or a
 * claim about what pipelinq no longer contains — and those carry a
 * reason-bearing `@e2e exclude` in the spec.
 *
 * The one scenario that IS observable is also the load-bearing one: REQ-AVG-014
 * says no parallel DSAR engine remains. That is a property of the RUNNING
 * router, not of the repository, and a route can be registered from somewhere a
 * grep misses — so it is asked of the server.
 *
 * WHY THIS DOES NOT ASSERT A 404 (and why a 404 assertion here would be a bug)
 * ---------------------------------------------------------------------------
 * `appinfo/routes.php:550` ends with an SPA catch-all:
 *
 *     ['name' => 'dashboard#page', 'url' => '/{path}', 'verb' => 'GET',
 *      'requirements' => ['path' => '.*'], 'defaults' => ['path' => '']]
 *
 * so ANY unmatched GET under /apps/pipelinq — including one that looks like
 * `/api/…` — is answered 200 with the Vue shell's HTML. A retired API route
 * therefore does NOT 404 on GET, and a test expecting one would fail against a
 * correctly-retired engine. Two signals are used instead, and each is
 * accompanied by a control so it cannot pass vacuously:
 *
 *   * GET  — the response must NOT be a JSON API response. Control: a route
 *            that IS live must answer `application/json`, proving the
 *            discriminator can tell the two apart.
 *   * POST — the catch-all is GET-only, so an unrouted POST is refused by the
 *            router. Control: a live POST route must not be refused.
 */
import { test, expect, Page } from '@playwright/test'

import { openApp } from '../helpers/pipelinq'

const APP = '/index.php/apps/pipelinq'

interface Probe {
	status: number
	type: string
	body: string
}

/** GET/POST a path from inside the authenticated page, reporting status + content-type. */
async function probe(page: Page, method: string, path: string): Promise<Probe> {
	return await page.evaluate(
		async ({ method, path }) => {
			const res = await fetch(path, {
				method,
				headers: {
					'Content-Type': 'application/json',
					// eslint-disable-next-line no-undef
					requesttoken: (window as any).OC?.requestToken || '',
					'OCS-APIREQUEST': 'true',
				},
				body: method === 'POST' ? '{}' : undefined,
			})
			return {
				status: res.status,
				type: res.headers.get('content-type') || '',
				body: (await res.text()).slice(0, 200),
			}
		},
		{ method, path },
	)
}

/* The route families REQ-AVG-014 requires to be gone, in the shapes the retired
 * controllers registered them. `appinfo/routes.php` declares none of them. */
const RETIRED = [
	`${APP}/api/avg-verzoeken`,
	`${APP}/api/avg-verzoeken/00000000-0000-0000-0000-000000000000`,
	`${APP}/api/export-bundles`,
	`${APP}/api/mdm/avg-workflow`,
]

// @e2e openspec/specs/avg-verzoeken-workflow/spec.md#no-parallel-dsar-engine-remains
test('no pipelinq-side DSAR route is served', async ({ page }) => {
	await openApp(page)

	// ---- POSITIVE CONTROLS FIRST -------------------------------------------
	// Without these, "no JSON here" and "POST is refused" would both be
	// satisfied by an instance where nothing works at all.
	const liveGet = await probe(page, 'GET', `${APP}/api/setup/status`)
	expect(
		liveGet.status,
		'control: a live pipelinq GET route must answer 200',
	).toBe(200)
	expect(
		liveGet.type,
		'control: a live pipelinq API route must answer JSON',
	).toContain('application/json')

	// `saveConfig()` iterates the posted params and skips `_route`, so an empty
	// body writes nothing — a routable POST that mutates no state.
	const livePost = await probe(page, 'POST', `${APP}/api/setup/config`)
	expect(
		livePost.status,
		'control: a live pipelinq POST route must be routable',
	).toBeLessThan(400)

	// ---- THE ASSERTION ------------------------------------------------------
	for (const path of RETIRED) {
		const get = await probe(page, 'GET', path)
		expect(
			get.type,
			`${path} still answers a JSON API response (HTTP ${get.status}) — a parallel DSAR engine remains: ${get.body}`,
		).not.toContain('application/json')

		const post = await probe(page, 'POST', path)
		expect(
			post.status,
			`POST ${path} was accepted by the router (HTTP ${post.status}) — a parallel DSAR engine remains: ${post.body}`,
		).toBeGreaterThanOrEqual(400)
	}
})
