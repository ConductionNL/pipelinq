/*
 * SPDX-FileCopyrightText: 2026 Pipelinq Contributors
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Gate-19 e2e coverage for the three `admin-settings` requirements that talk
 * about "the documented operations" of the settings / preferences /
 * configuration-loading layer rather than about a screen:
 *
 *   - Settings and configuration services — documented operations
 *   - Settings and configuration services — results derived from current CRM state
 *   - Settings and configuration services — defensive handling of absent or invalid input
 *
 * WHY A TEST AND NOT AN EXCLUSION
 * -------------------------------
 * The obvious exclusion for these — "covered by PHPUnit" — would have been
 * FALSE. `getPreference` and `setPreference` are the operations the first
 * requirement names, and `grep -rln "getPreference\|setPreference" tests/`
 * returns NOTHING: no test in this repository references either. They are also
 * routed HTTP endpoints (`appinfo/routes.php` → `GET`/`PUT
 * /api/preferences/{key}`), so they are reachable end-to-end and there is no
 * good reason not to reach them. An exclusion naming coverage that does not
 * exist is worth less than the finding it closes.
 *
 * All three requirements are asserted by ONE round trip, deliberately:
 *   - "the operation MUST execute and return a result consistent with the
 *     current implementation" — the write and the read both answer the
 *     documented `{value}` shape;
 *   - "its output MUST change when the underlying data changes" — the same key
 *     is read three times (unset → set → cleared) and answers differently each
 *     time. Reading once and asserting a literal would pass against a
 *     hard-coded response, which is the exact thing that requirement forbids;
 *   - "MUST return a safe default or a validation result rather than crashing"
 *     — a key that sanitises to empty answers 400 with a message, not a 500.
 *
 * ISOLATED COOKIE JAR PER IDENTITY
 * --------------------------------
 * The anonymous assertion is only worth its green if the request really is
 * anonymous. Nextcloud resolves a session cookie BEFORE it looks at an
 * Authorization header, so a context reused after an authenticated call runs as
 * that user and a "401 for anonymous" test silently becomes a 200. Playwright's
 * `request` fixture is one context for the whole test, so it is not used here;
 * each identity gets a fresh jar, and `assertCallerIs()` proves — in the same
 * chain, against `/ocs/v2.php/cloud/user` — who the server thinks is calling.
 *
 * @spec openspec/specs/admin-settings/spec.md
 */

import { test, expect, request as playwrightRequest, type APIRequestContext } from '@playwright/test'

import { resolveBaseUrl } from '../base-url'

const ADMIN_USER = process.env.ADMIN_USER ?? process.env.NC_ADMIN_USER ?? 'admin'
const ADMIN_PASS = process.env.ADMIN_PASSWORD ?? process.env.NC_ADMIN_PASS ?? 'admin'

const API_BASE = '/index.php/apps/pipelinq/api'

/**
 * A fresh APIRequestContext with its own cookie jar — one per identity, never
 * shared. See the header note on why this is load-bearing.
 *
 * @param authenticated Whether to send admin HTTP Basic credentials.
 * @return an isolated request context; the caller disposes it.
 */
async function newIdentity(authenticated: boolean): Promise<APIRequestContext> {
	const headers: Record<string, string> = {
		// Nextcloud's Request::passesCSRFCheck() short-circuits on this header.
		// A Basic-auth request carries no session cookie, so the strict-cookie
		// precondition holds and the PUT below is not rejected as a CSRF failure.
		'OCS-APIRequest': 'true',
		Accept: 'application/json',
	}
	if (authenticated) {
		headers.Authorization = `Basic ${Buffer.from(`${ADMIN_USER}:${ADMIN_PASS}`).toString('base64')}`
	}
	return playwrightRequest.newContext({ baseURL: resolveBaseUrl(), extraHTTPHeaders: headers })
}

/**
 * Prove, in the same request chain, who the server thinks is calling.
 *
 * @param ctx      The context to interrogate.
 * @param expected The uid expected, or null for "must not be authenticated".
 */
async function assertCallerIs(ctx: APIRequestContext, expected: string | null): Promise<void> {
	const res = await ctx.get('/ocs/v2.php/cloud/user?format=json')
	const body = await res.json().catch(() => null)
	// The OCS layer signals "not logged in" as HTTP 200 with
	// `ocs.meta.statuscode: 997`, NOT as a 401 — so the HTTP status is the
	// wrong channel to read here. An earlier revision of this helper asserted
	// `status !== 200` and failed on CI against a correctly-anonymous context,
	// which is the guard working and reporting the wrong reason. Read the
	// resolved identity itself: absent uid means nobody is authenticated,
	// whichever way this Nextcloud version chooses to say so.
	const uid = body?.ocs?.data?.id ?? null

	if (expected === null) {
		expect(
			uid,
			'this context must NOT resolve to a Nextcloud user — if it does, a cookie leaked in from another identity and the refusal assertion below proves nothing',
		).toBeNull()
		return
	}
	expect(
		uid,
		`this context must be acting as ${expected}; a different uid (or none) means the jar is shared or the credentials did not authenticate, and the assertions below measure the wrong caller`,
	).toBe(expected)
}

test.describe('settings/preferences documented operations', () => {

	// @e2e openspec/specs/admin-settings/spec.md#documented-operations-are-available
	// @e2e openspec/specs/admin-settings/spec.md#results-reflect-live-state
	test('a preference round-trips, and the read changes when the stored value changes', async () => {
		const ctx = await newIdentity(true)
		try {
			await assertCallerIs(ctx, ADMIN_USER)

			// Namespaced per run so a re-run never reads a previous run's value —
			// and lowercase/hyphen only, because sanitizeKey() strips everything
			// outside [a-z0-9-] and a key that silently changed shape would make
			// the read below miss for the wrong reason.
			const key = `e2e-pref-${Date.now()}`
			const value = `v-${Date.now()}`

			// 1. Unset — the documented default is null, not an error and not ''.
			const before = await ctx.get(`${API_BASE}/preferences/${key}`)
			expect(before.status(), 'reading an unset preference is a normal read').toBe(200)
			expect((await before.json()).value, 'an unset preference reads back as null').toBeNull()

			// 2. Written.
			const write = await ctx.put(`${API_BASE}/preferences/${key}`, { data: { value } })
			expect(write.status()).toBe(200)
			expect((await write.json()).value).toBe(value)

			// 3. Read back through a FRESH request, so this asserts persisted
			//    state rather than the echo of the write.
			const after = await ctx.get(`${API_BASE}/preferences/${key}`)
			expect(after.status()).toBe(200)
			expect(
				(await after.json()).value,
				'the read must be derived from stored state — this is the "no hard-coded or stubbed responses" requirement',
			).toBe(value)

			// 4. Cleared. An empty value CLEARS rather than storing '', so a
			//    cleared preference and a never-set one must read identically.
			const clear = await ctx.put(`${API_BASE}/preferences/${key}`, { data: { value: '' } })
			expect(clear.status()).toBe(200)
			const afterClear = await ctx.get(`${API_BASE}/preferences/${key}`)
			expect(
				(await afterClear.json()).value,
				'a cleared preference must read back as null, identical to one that was never set',
			).toBeNull()
		} finally {
			await ctx.dispose()
		}
	})

	// @e2e openspec/specs/admin-settings/spec.md#missing-input-does-not-crash-the-flow
	test('an unusable key is a validation result, not a crash, and anonymous is refused', async () => {
		const admin = await newIdentity(true)
		const anon = await newIdentity(false)
		try {
			await assertCallerIs(admin, ADMIN_USER)
			await assertCallerIs(anon, null)

			// `sanitizeKey()` strips everything outside [a-z0-9-], so a key made
			// entirely of punctuation sanitises to the empty string — the
			// "absent or invalid input" case. It must be answered, not thrown on.
			const unusable = encodeURIComponent('!!!')
			for (const res of [
				await admin.get(`${API_BASE}/preferences/${unusable}`),
				await admin.put(`${API_BASE}/preferences/${unusable}`, { data: { value: 'x' } }),
			]) {
				expect(res.status(), 'an unusable key is a validation outcome (400), never a 500').toBe(400)
				expect(res.status(), 'the surrounding flow must not crash').toBeLessThan(500)
				expect((await res.json()).message).toBeTruthy()
			}

			// The endpoints are @NoAdminRequired, which means "any logged-in
			// user" — not "anyone". Asserted from a jar that has never
			// authenticated.
			const res = await anon.get(`${API_BASE}/preferences/e2e-anon-probe`)
			expect(res.status(), 'a per-user preference must not be readable without a session').not.toBe(200)
		} finally {
			await admin.dispose()
			await anon.dispose()
		}
	})
})
