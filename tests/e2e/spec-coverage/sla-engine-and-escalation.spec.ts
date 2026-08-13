/*
 * SPDX-FileCopyrightText: 2026 Pipelinq Contributors
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Gate-19 behavioural e2e coverage for
 * openspec/specs/sla-engine-and-escalation/spec.md.
 *
 * WHAT OF THIS CAPABILITY IS REACHABLE FROM A BROWSER, AND WHAT IS NOT
 * -------------------------------------------------------------------
 * The SLA engine is overwhelmingly in-process: policy resolution, holiday and
 * business-hours deadline arithmetic, pause/resume timer state and the
 * escalation chain all run inside OpenRegister object-save event listeners and
 * a cron sweep job. None of those has a user-facing trigger, so those scenarios
 * carry a reason-bearing `@e2e exclude` in the spec naming the PHPUnit class
 * that asserts them.
 *
 * TWO surfaces DO cross the HTTP boundary and are asserted here:
 *
 *   1. `GET /api/sla/attainment` (SlaAttainmentController) — the aggregation
 *      endpoint REQ "Attainment reporting" is about, plus the declarative
 *      `type: "dashboard"` page that renders it (src/manifest.d/55-sla-engine.json,
 *      route /sla/attainment).
 *   2. `POST /api/sla/policies` / `PUT /api/sla/policies/{id}`
 *      (SlaPolicyController) — the justification gate on every policy write.
 *
 * WHY THESE ASSERT OVER HTTP RATHER THAN OVER PIXELS. Both are scenarios ABOUT
 * a contract — "the response MUST include attainment/total/met/breached",
 * "the API MUST return HTTP 400 with justificationRequired". There is no pixel
 * that shows a status code. They are still driven end to end: every request is
 * issued from INSIDE the authenticated browser context via
 * `page.evaluate(fetch …)`, so it rides the real session cookie and
 * `OC.requestToken` through the real Nextcloud middleware stack — including the
 * `#[AuthorizedAdminSetting]` guard on the policy writes — exactly as the app's
 * own calls do.
 *
 * WHAT THE CI INSTANCE HAS. `tests/e2e/ci-seed.sh` force-imports the register,
 * which brings in `lib/Settings/register.d/55-sla-engine.json`: the `sla`
 * register, the `slaPolicy` + `slaBreachEvent` schemas and FOUR seeded policies
 * — "Standaard request-SLA" (appliesTo request, tier *, priority 100),
 * "Goud-tier klant-SLA" (request/gold, priority 10), "AVG datalek-klacht SLA"
 * (klacht, priority 5) and "Standaard callback-SLA". Every literal asserted
 * below was read out of that file and re-measured against a live instance, not
 * guessed. There are NO seeded `slaBreachEvent` rows.
 *
 * MEASURED PRODUCT BUGS — reported, not fixed, and the reason the numeric half
 * of the reporting scenarios is not asserted here:
 *
 *   (a) `SlaAttainmentService::loadBreachEventsInRange()` passes `register` and
 *       `schema` at the TOP LEVEL of the OpenRegister `findAll()` config.
 *       `ObjectService::prepareFindAllConfig()` only reads them from
 *       `config['filters']`, so the register/schema context is never set and the
 *       aggregation sees no breach events. Measured on a live instance: with one
 *       `slaBreachEvent` written into the configured register/schema and inside
 *       the requested bucket, the endpoint still answered `total: 0`,
 *       `breached: 0`. Seeding rows from a test would therefore prove nothing,
 *       so these tests assert the ENVELOPE, the bucket window and the accepted
 *       grouping vocabulary — the parts that are genuinely exercised — and the
 *       per-row assertions are written so that a regression which started
 *       emitting malformed or zero-total rows would fail.
 *
 *   (b) No tracked object ever carries an `slaStatus`: both SLA listeners gate
 *       on entity types 'request'/'complaint'/'klacht'/'callback' while
 *       `SchemaMapService::resolveEntityType()` resolves the unified ticket
 *       schema to 'ticket' (and carries no `callback_schema` entry at all).
 *       Measured live: 86 seeded tickets, 0 with an `slaStatus`; creating a
 *       fresh `ticketType: request` object produced `slaStatus: null`.
 *
 * NOTHING HERE WRITES. The suite runs `fullyParallel` and `ticket` declares
 * `x-openregister-archival`, so a ticket created by a test can never be deleted
 * again (measured: DELETE → 403 SCHEMA_ARCHIVAL_IMMUTABLE). Both policy-write
 * tests deliberately exercise only the REJECTED path, which persists nothing.
 */
import { test, expect, Page } from '@playwright/test'

import {
	openApp,
	assertNoHardError,
	nextcloudErrorPage,
	dismissWalkthrough,
	dismissSupportDialog,
} from '../helpers/pipelinq'

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
					// eslint-disable-next-line no-undef
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

const APP = '/index.php/apps/pipelinq'
/** The SLA register is its own OpenRegister register (slug `sla`). */
const SLA_OBJECTS = '/index.php/apps/openregister/api/objects/sla'

/** Read the seeded SLA policies through the OR object API the app itself uses. */
async function seededPolicies(page: Page): Promise<any[]> {
	const res = await api(page, 'GET', `${SLA_OBJECTS}/slaPolicy?_limit=100`)
	expect(res.status, `the seeded sla register must be readable: ${res.text}`).toBe(
		200,
	)
	return res.json?.results ?? res.json ?? []
}

/**
 * Deep-link to a hash route and let the view settle.
 *
 * Deliberately NOT `assertAppShellServed()`: this navigation only changes the
 * fragment of a document the page is already on, so Playwright performs no
 * request and `page.goto()` returns null. The shell is asserted directly
 * instead — `#content-vue` mounted, and Nextcloud's own error chrome absent.
 */
async function gotoHash(page: Page, hash: string): Promise<void> {
	await page.goto(`/apps/pipelinq/#${hash}`)
	await expect(page.locator('#content-vue')).toBeVisible({ timeout: 15000 })
	await expect(nextcloudErrorPage(page)).toHaveCount(0)
	await dismissWalkthrough(page)
	await dismissSupportDialog(page)
}

/**
 * Assert the documented attainment envelope. Every key named here is emitted
 * unconditionally by SlaAttainmentService::compute(), so a dropped field fails
 * this regardless of how much data the instance carries.
 */
function expectAttainmentEnvelope(payload: any): void {
	for (const key of [
		'attainment',
		'attainmentPercent',
		'total',
		'met',
		'breached',
		'inFlightBreached',
		'closedBreached',
	]) {
		expect(
			typeof payload?.[key],
			`attainment envelope key "${key}" must be numeric`,
		).toBe('number')
	}
	expect(payload.attainment, 'attainment is a ratio').toBeGreaterThanOrEqual(0)
	expect(payload.attainment).toBeLessThanOrEqual(1)
	// The percent twin the dashboard stat widget binds to is the same number,
	// rounded to one decimal (so the tolerance is that rounding step, not a
	// `toBeCloseTo` digit count that rejects an exact .x5 half-up rounding).
	expect(
		Math.abs(payload.attainmentPercent - payload.attainment * 100),
		'attainmentPercent must be attainment × 100, rounded to one decimal',
	).toBeLessThanOrEqual(0.0501)
	expect(payload.details, 'the envelope carries a details object').toBeTruthy()
	expect(payload.details).toHaveProperty('byTarget')
	expect(payload.details).toHaveProperty('byGroup')
	expect(Array.isArray(payload.details.byGroup), 'byGroup is a list of rows').toBe(
		true,
	)
}

/* ══════════════════════════════════════════════════════════════════════════
 * REQ "Attainment reporting" — GET /api/sla/attainment.
 * ══════════════════════════════════════════════════════════════════════════ */
test.describe('SLA attainment reporting', () => {
	// @e2e openspec/specs/sla-engine-and-escalation/spec.md#quarterly-attainment-with-breach-breakdown
	// @e2e openspec/specs/sla-engine-and-escalation/spec.md#per-target-accounting
	test('a quarter-bucket report answers the documented envelope for one policy', async ({
		page,
	}) => {
		await openApp(page)

		const policies = await seededPolicies(page)
		const baseline = policies.find(
			(p: any) => p.name === 'Standaard request-SLA',
		)
		expect(
			baseline,
			'the seeded baseline policy must be importable from register.d/55-sla-engine.json',
		).toBeTruthy()
		const policyId = String(baseline.id || baseline['@self']?.id)

		const res = await api(
			page,
			'GET',
			`${APP}/api/sla/attainment?policy=${policyId}&bucket=quarter&quarter=2026-Q2`,
		)
		expect(res.status, res.text).toBe(200)
		expectAttainmentEnvelope(res.json)

		// The quarter is resolved server-side to a real window — Q2 2026 is
		// [Apr 1, Jul 1). This is what makes `bucket` more than a passthrough.
		expect(res.json.range?.start, 'Q2 starts on 1 April').toMatch(
			/^2026-04-01T00:00:00/,
		)
		expect(res.json.range?.end, 'Q2 ends exclusive on 1 July').toMatch(
			/^2026-07-01T00:00:00/,
		)

		// PER-TARGET ACCOUNTING: attainment is not all-or-nothing at object level
		// — `details.byTarget` is keyed by target kind and each entry carries its
		// OWN attainment/met/breached triple, computed from that kind's counts.
		const byTarget = res.json.details.byTarget
		expect(typeof byTarget, 'byTarget is a per-target-kind container').toBe(
			'object',
		)
		for (const [kind, counts] of Object.entries<any>(byTarget ?? {})) {
			expect(
				['acknowledgement', 'firstResponse', 'resolution', 'callback'],
				`byTarget key "${kind}" is not a declared target kind`,
			).toContain(kind)
			expect(typeof counts.met).toBe('number')
			expect(typeof counts.breached).toBe('number')
			const denominator = counts.met + counts.breached
			expect(
				counts.attainment,
				`byTarget.${kind}.attainment must be met/(met+breached)`,
			).toBeCloseTo(denominator > 0 ? counts.met / denominator : 0, 3)
		}

		// A bucket outside the declared vocabulary is refused rather than
		// silently defaulted — otherwise "quarter" could be a typo that reported
		// a month and nobody would notice.
		const bad = await api(
			page,
			'GET',
			`${APP}/api/sla/attainment?bucket=fortnight`,
		)
		expect(bad.status, bad.text).toBe(400)
		expect(bad.json?.error).toBe('invalidBucket')
	})

	// @e2e openspec/specs/sla-engine-and-escalation/spec.md#breakdown-by-customer-tier
	// @e2e openspec/specs/sla-engine-and-escalation/spec.md#breakdown-by-assignee-team
	test('the report breaks down by every declared dimension and refuses unknown ones', async ({
		page,
	}) => {
		await openApp(page)

		// `tier` and `team` are the two dimensions these scenarios name; the other
		// three are asserted alongside so a regression that quietly narrowed the
		// vocabulary is caught here too.
		for (const [groupBy, bucket] of [
			['tier', 'month'],
			['team', 'week'],
			['policy', 'month'],
			['target', 'month'],
			['customer', 'month'],
		]) {
			const res = await api(
				page,
				'GET',
				`${APP}/api/sla/attainment?groupBy=${groupBy}&bucket=${bucket}`,
			)
			expect(res.status, `groupBy=${groupBy}: ${res.text}`).toBe(200)
			expectAttainmentEnvelope(res.json)

			for (const row of res.json.details.byGroup) {
				// The five columns the breakdown table (SlaAttainmentBreakdownSection)
				// renders. A row missing any of them would paint an empty cell.
				for (const column of [
					'groupKey',
					'groupName',
					'attainment',
					'total',
					'met',
					'breached',
				]) {
					expect(
						row,
						`a byGroup row is missing "${column}"`,
					).toHaveProperty(column)
				}
				// "groups with zero objects in the period MUST be omitted" — an
				// emitted row always stands for at least one counted object.
				expect(
					row.total,
					`byGroup row "${row.groupKey}" was emitted with a zero total`,
				).toBeGreaterThan(0)
				expect(
					row.met + row.breached,
					`byGroup row "${row.groupKey}" does not add up`,
				).toBeLessThanOrEqual(row.total)
			}
		}

		const bad = await api(
			page,
			'GET',
			`${APP}/api/sla/attainment?groupBy=byFavouriteColour`,
		)
		expect(bad.status, bad.text).toBe(400)
		expect(bad.json?.error).toBe('invalidGroupBy')
	})

	// @e2e openspec/specs/sla-engine-and-escalation/spec.md#in-flight-vs-closed-breach-distinction
	test('breached objects are reported in two separate buckets, in-flight and closed', async ({
		page,
	}) => {
		await openApp(page)

		const res = await api(
			page,
			'GET',
			`${APP}/api/sla/attainment?bucket=month&groupBy=policy`,
		)
		expect(res.status, res.text).toBe(200)
		expectAttainmentEnvelope(res.json)

		// The operator-facing distinction: `breached` is not one undifferentiated
		// number — it is partitioned into the still-open objects to act on now and
		// the closed ones that are history. Both counters are separate top-level
		// fields, and every breach lands in exactly one of them.
		expect(res.json).toHaveProperty('inFlightBreached')
		expect(res.json).toHaveProperty('closedBreached')
		expect(res.json.inFlightBreached).toBeGreaterThanOrEqual(0)
		expect(res.json.closedBreached).toBeGreaterThanOrEqual(0)
		expect(
			res.json.inFlightBreached + res.json.closedBreached,
			'in-flight + closed must partition the breached total',
		).toBe(res.json.breached)
	})

	/*
	 * The reporting surface an operator actually opens: the declarative
	 * `type: "dashboard"` page from src/manifest.d/55-sla-engine.json, whose four
	 * stat widgets are endpoint-bound to the same GET /api/sla/attainment.
	 * All four layout slots set `showTitle: false`, so what renders is each
	 * widget's `content.label`, not its `title` — the same distinction
	 * spec-coverage/commercial-dashboard.spec.ts documents.
	 */
	// @e2e openspec/specs/sla-engine-and-escalation/spec.md#quarterly-attainment-with-breach-breakdown
	test('the SLA attainment dashboard page mounts its KPI surface', async ({
		page,
	}) => {
		await openApp(page)
		await gotoHash(page, '/sla/attainment')

		const content = page.locator('#content-vue')
		// Either rendering of the manifest page is proof it mounted: the KPI
		// tile label, or the page title. Written as an `or` so the assertion does
		// not pin one shared-component layout revision.
		// `.first()` must wrap the WHOLE `.or(...)`, not each side of it. Applying
		// it per-branch leaves the union itself with two members, and Playwright
		// raised exactly that: "strict mode violation: … resolved to 2 elements".
		// Measured in run 31473685688 — both the KPI label and the heading render,
		// so the union genuinely matches twice and either one proves the mount.
		await expect(
			content
				.getByText('Overall attainment')
				.or(content.getByRole('heading', { name: 'SLA attainment' }))
				.first(),
		).toBeVisible({ timeout: 20000 })

		await assertNoHardError(page)
	})
})

/* ══════════════════════════════════════════════════════════════════════════
 * REQ "Audit trail of policy changes" — the justification gate.
 *
 * Both routes are `#[AuthorizedAdminSetting(pipelinq)]`, so reaching the 400 at
 * all proves the request passed the admin guard on the real session before the
 * controller's own check ran.
 * ══════════════════════════════════════════════════════════════════════════ */
// @e2e openspec/specs/sla-engine-and-escalation/spec.md#justification-required-for-policy-save
test('a policy write without a justification is refused and persists nothing', async ({
	page,
}) => {
	await openApp(page)

	const REJECTED_NAME = 'E2E gate-19 policy that must never be saved'

	const before = await seededPolicies(page)
	const names = before.map((p: any) => p.name)
	// The seed this capability ships, reached through the real API.
	for (const expected of [
		'Standaard request-SLA',
		'Goud-tier klant-SLA',
		'AVG datalek-klacht SLA',
		'Standaard callback-SLA',
	]) {
		expect(names, `seeded SLA policy "${expected}" is missing`).toContain(
			expected,
		)
	}

	// CREATE without justification — a fully valid policy body otherwise.
	const created = await api(page, 'POST', `${APP}/api/sla/policies`, {
		name: REJECTED_NAME,
		appliesTo: 'request',
		customerTier: 'bronze',
		targets: [
			{ kind: 'resolution', duration: 'P1W', calendar: 'business-hours' },
		],
	})
	expect(created.status, created.text).toBe(400)
	expect(created.json?.error).toBe('justificationRequired')
	expect(String(created.json?.message)).toMatch(/justification is required/i)

	// EDIT without justification — the scenario's own wording ("tighten the
	// resolution target from P3W to P1W"), against the seeded baseline policy.
	const baseline = before.find((p: any) => p.name === 'Standaard request-SLA')
	const baselineId = String(baseline.id || baseline['@self']?.id)
	const edited = await api(page, 'PUT', `${APP}/api/sla/policies/${baselineId}`, {
		name: 'Standaard request-SLA',
		targets: [
			{ kind: 'resolution', duration: 'P1W', calendar: 'business-hours' },
		],
	})
	expect(edited.status, edited.text).toBe(400)
	expect(edited.json?.error).toBe('justificationRequired')

	// "the policy MUST NOT be persisted" — asserted on BOTH halves: no new
	// policy appeared, and the edited one still carries its original P3W
	// resolution target.
	const after = await seededPolicies(page)
	expect(
		after.map((p: any) => p.name),
		'the rejected policy was persisted anyway',
	).not.toContain(REJECTED_NAME)
	expect(after.length, 'the rejected create must not have added a policy').toBe(
		before.length,
	)

	const baselineAfter = after.find((p: any) => p.name === 'Standaard request-SLA')
	const resolution = (baselineAfter?.targets ?? []).find(
		(t: any) => t.kind === 'resolution',
	)
	expect(
		resolution?.duration,
		'the rejected edit must not have tightened the seeded target',
	).toBe('P3W')
})
