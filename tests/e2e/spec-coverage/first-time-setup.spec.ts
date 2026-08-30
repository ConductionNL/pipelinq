/*
 * SPDX-FileCopyrightText: 2026 Pipelinq Contributors
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Gate-19 behavioural e2e coverage for openspec/specs/first-time-setup/spec.md.
 *
 * WHY THIS DRIVES ENDPOINTS RATHER THAN THE WIZARD UI
 * ---------------------------------------------------
 * The wizard is not on screen in CI, and that is correct: `tests/e2e/ci-seed.sh`
 * deliberately completes the required currency step and records the demo-data
 * decision precisely so `CnAppRoot` stops covering the whole app with
 * `CnSetupWizard` in every test (its header explains this at length). So the
 * observable surface for this capability is the contract the wizard calls —
 * `GET /api/setup/status`, `POST /api/setup/config`,
 * `POST /api/setup/action/{id}` — and that is what is exercised here, from
 * inside the authenticated admin page so every call carries the real session
 * and `OC.requestToken` through Nextcloud's `AuthorizedAdminSetting` middleware.
 * A unit test with a mocked IAppConfig cannot show that middleware admitting the
 * request; this can.
 *
 * These tests WRITE app-config. They run serial and restore every key they
 * touch, and no other spec in the suite reads `receipt_company_*` (verified by
 * grep across tests/e2e).
 */
import { test, expect, Page } from '@playwright/test'

import { openApp } from '../helpers/pipelinq'

const APP = '/index.php/apps/pipelinq'

/** One authenticated JSON call issued from inside the logged-in admin page. */
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

test.describe('First-time setup contract', () => {
	// Serial: two of these tests write app-config keys that `status()` reads, so
	// interleaving them across workers would let one test observe another's
	// half-applied state.
	test.describe.configure({ mode: 'serial' })

	/*
	 * REQ-SETUP-PIP-007. The load-bearing part is the SHAPE: every step the
	 * manifest declares must be reported, because a step the server can never
	 * report done is what auto-opens the wizard as a modal mask over the app on
	 * every fresh browser profile — the failure this endpoint's own docblock
	 * records having shipped once already.
	 */
	// @e2e openspec/specs/first-time-setup/spec.md#optional-steps-report-done-without-gating
	test('setup status reports every step, and only currency gates completion', async ({
		page,
	}) => {
		await openApp(page)

		const res = await api(page, 'GET', `${APP}/api/setup/status`)
		expect(res.status, res.text).toBe(200)

		// Currency is set by ci-seed.sh, so the app is complete…
		expect(
			res.json?.steps?.currency?.done,
			'currency is the required step',
		).toBe(true)
		expect(res.json?.completed, 'currency alone decides completion').toBe(true)

		// …and every declared step is reported with a boolean, none omitted.
		for (const id of [
			'welcome',
			'currency',
			'provision',
			'demo-data',
			'organisation',
			'integrations',
			'done',
		]) {
			expect(
				res.json?.steps?.[id],
				`step "${id}" is not reported at all`,
			).toBeTruthy()
			expect(
				typeof res.json.steps[id].done,
				`step "${id}" done flag is not a boolean`,
			).toBe('boolean')
		}

		// The optional steps reflect their OWN state rather than the app's,
		// and completion is true regardless — which is the whole scenario:
		// only currency gates completion.
		expect(
			res.json.steps.provision.done,
			'ci-seed reimported the register',
		).toBe(true)
		expect(
			res.json.steps['demo-data'].done,
			'ci-seed recorded the demo-data decision',
		).toBe(true)
		// `organisation.done` is deliberately NOT pinned to a value.
		//
		// It used to assert `false` on the stated grounds that "no organisation
		// name is configured in CI". ADR-111 demo-data generation (#1480) now
		// seeds one, so the flag flipped to true and this went red — while the
		// behaviour under test never changed.
		//
		// Pinning it either way re-creates the same brittleness in the other
		// direction: the next change to what ci-seed provisions would break it
		// again. What this scenario actually claims is that an OPTIONAL step
		// does not gate completion, whichever way it happens to land, and that
		// is what is asserted now.
		expect(
			typeof res.json.steps.organisation.done,
			'the organisation step reports a boolean either way',
		).toBe('boolean')
		expect(
			res.json.completed,
			'completion is gated by currency alone, never by an optional step',
		).toBe(true)
	})

	/*
	 * REQ-SETUP-PIP-004. ci-seed.sh has already run the import once, so calling
	 * the action here is by construction the "run it again" half — and the
	 * duplicate check is made against real object counts on both sides.
	 */
	// @e2e openspec/specs/first-time-setup/spec.md#provision-on-demand-after-enabling-openregister-later
	test('provision-register is idempotent and leaves the register populated', async ({
		page,
	}) => {
		test.setTimeout(120000)
		await openApp(page)

		const OR = '/index.php/apps/openregister/api/objects/pipelinq'
		const countPipelines = async (): Promise<number> => {
			const r = await api(page, 'GET', `${OR}/pipeline?_limit=200`)
			expect(r.status, r.text).toBe(200)
			const rows: any[] = r.json?.results ?? r.json ?? []
			return rows.length
		}

		const before = await countPipelines()
		expect(
			before,
			'provisioning must have created the default pipelines',
		).toBeGreaterThan(0)

		const first = await api(
			page,
			'POST',
			`${APP}/api/setup/action/provision-register`,
		)
		expect(first.status, first.text).toBe(200)
		expect(first.json?.success, first.text).toBe(true)

		const middle = await countPipelines()

		// Running it AGAIN must succeed and must not duplicate.
		const second = await api(
			page,
			'POST',
			`${APP}/api/setup/action/provision-register`,
		)
		expect(second.status, second.text).toBe(200)
		expect(second.json?.success, second.text).toBe(true)

		const after = await countPipelines()
		expect(
			after,
			'a second provision run duplicated the default pipelines',
		).toBe(middle)
		expect(after).toBeGreaterThan(0)

		// And the step still reports done.
		const status = await api(page, 'GET', `${APP}/api/setup/status`)
		expect(status.json?.steps?.provision?.done).toBe(true)
	})

	// @e2e openspec/specs/first-time-setup/spec.md#organisation-details-persist
	test('organisation details persist and flip the optional step to done', async ({
		page,
	}) => {
		await openApp(page)

		// Precondition: unset, so the flip below is caused by this test.
		//
		// ESTABLISHED, not asserted. This used to read the status and require
		// `done === false`, which silently depended on nothing else having set
		// an organisation name first. Since the demo-data step began seeding on
		// install, `receipt_company_name` is populated before this spec runs and
		// the precondition failed — the test reported a product defect when the
		// only thing wrong was an inherited fixture.
		//
		// Clearing first is the same call the `finally` below already makes, so
		// the mechanism is proven in this file. The assertion that follows the
		// clear is what keeps this honest: if the reset does not take, this
		// still fails loudly rather than testing a flip that already happened.
		await api(page, 'POST', `${APP}/api/setup/config`, {
			receipt_company_name: '',
			receipt_company_vat: '',
			receipt_company_kvk: '',
		})
		const before = await api(page, 'GET', `${APP}/api/setup/status`)
		expect(
			before.json?.steps?.organisation?.done,
			'could not clear the organisation step, so the flip below would prove nothing',
		).toBe(false)

		try {
			const saved = await api(page, 'POST', `${APP}/api/setup/config`, {
				receipt_company_name: 'E2E Gate-19 Organisatie BV',
				receipt_company_vat: 'NL001234567B01',
				receipt_company_kvk: '12345678',
			})
			expect(saved.status, saved.text).toBe(200)
			expect(saved.json?.success).toBe(true)

			// The values are readable back through the state the wizard consults:
			// `organisation.done` is derived from `receipt_company_name` being
			// non-empty, so a true here is that key holding the entered value.
			const after = await api(page, 'GET', `${APP}/api/setup/status`)
			expect(after.status, after.text).toBe(200)
			expect(
				after.json?.steps?.organisation?.done,
				'the organisation step did not record the entered name',
			).toBe(true)
			// Still not gating: completion is unchanged by an optional step.
			expect(after.json?.completed).toBe(true)
		} finally {
			// Leave the instance as ci-seed left it — which is NOT empty.
			//
			// This block used to clear the name, described as "leave the
			// instance exactly as found". It was the opposite of that.
			// ci-seed.sh step 3b sets `receipt_company_name` precisely so that
			// no optional step is outstanding, and says why in its own error
			// message: "An unmet optional step makes CnAppRoot cover the shell
			// with the wizard in every fresh browser context."
			//
			// So clearing it here handed that modal mask to every spec that
			// ran afterwards. In the run this test last failed in,
			// dashboard.spec.ts was flaky three ways — renders the page,
			// renders the KPI and chart widgets, offers the quick-create
			// actions — which is exactly what a modal over the shell produces.
			//
			// The literal is the value ci-seed.sh writes. There is no GET for
			// the setup config (only /status, which returns booleans), so the
			// original cannot be read back and restored dynamically; if the
			// seed's value changes, change it here too.
			await api(page, 'POST', `${APP}/api/setup/config`, {
				receipt_company_name: 'CI Test Organisation',
				receipt_company_vat: '',
				receipt_company_kvk: '',
			})
			const restored = await api(page, 'GET', `${APP}/api/setup/status`)
			expect(
				restored.json?.steps?.organisation?.done,
				'cleanup must leave the organisation step DONE, as ci-seed did',
			).toBe(true)
		}
	})

	/*
	 * REQ-SETUP-PIP-008. ci-seed.sh already invoked `seed-demo-data` once, so
	 * both calls below are re-runs — which is exactly what the idempotency
	 * scenario asks for, and the object counts prove it rather than the message.
	 */
	// @e2e openspec/specs/first-time-setup/spec.md#seed-on-a-clean-install
	// @e2e openspec/specs/first-time-setup/spec.md#idempotent-re-run
	// @e2e openspec/specs/first-time-setup/spec.md#offered-as-an-optional-wizard-step
	test('the demo seed is an optional action, and re-running it creates no duplicates', async ({
		page,
	}) => {
		test.setTimeout(120000)
		await openApp(page)

		const OR = '/index.php/apps/openregister/api/objects/pipelinq'
		const count = async (schema: string): Promise<number> => {
			const r = await api(page, 'GET', `${OR}/${schema}?_limit=300`)
			expect(r.status, `${schema}: ${r.text}`).toBe(200)
			const rows: any[] = r.json?.results ?? r.json ?? []
			return rows.length
		}

		// The linked dataset the scenario names exists — this is what makes the
		// lists, dashboards and the 360 view render populated.
		const before = {
			client: await count('client'),
			lead: await count('lead'),
			ticket: await count('ticket'),
		}
		for (const [schema, n] of Object.entries(before)) {
			expect(n, `the demo seed produced no ${schema} objects`).toBeGreaterThan(
				0,
			)
		}

		// Re-run through the same optional wizard action the occ command shares.
		const rerun = await api(
			page,
			'POST',
			`${APP}/api/setup/action/seed-demo-data`,
		)
		expect(rerun.status, rerun.text).toBe(200)
		expect(rerun.json?.success, rerun.text).toBe(true)

		const after = {
			client: await count('client'),
			lead: await count('lead'),
			ticket: await count('ticket'),
		}
		expect(after, 're-running the demo seed duplicated objects').toEqual(before)

		// It is OPTIONAL and does not gate: the app is complete either way, and
		// the step is reported so the wizard can offer it.
		const status = await api(page, 'GET', `${APP}/api/setup/status`)
		expect(
			status.json?.steps?.['demo-data'],
			'the demo-data step must be offered',
		).toBeTruthy()
		expect(
			status.json?.completed,
			'the demo-data step must not gate completion',
		).toBe(true)
	})
})
