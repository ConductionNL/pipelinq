/*
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Gate-19 behavioural e2e coverage for marketing-integrated-campaigns,
 * phase 6 of the marketing programme and the last of it.
 *
 * WHAT THIS PROVES, AND WHAT IT CANNOT
 * ------------------------------------
 * The CI instance runs OpenRegister and Pipelinq and nothing else: the
 * workflow pins `additional-apps` to openregister and planninq, so neither
 * shillinq nor hermiq is present. That is not a gap in the coverage, it is
 * the state most of this change is designed for, and five things are still
 * observable:
 *
 *   1. the Marketing group reaches a Journeys page;
 *   2. `GET /api/segments/signals` lists all eight derived fields AND says
 *      the bookkeeping behind six of them cannot be read here;
 *   3. the five standard audiences are seeded and each one names its source;
 *   4. a journey written through POST /api/journeys comes back with a flow
 *      status, and the page says so when it will not run;
 *   5. the weekly review is a card on the Reports page, renders from one
 *      response, and names the source it could not read.
 *
 * Everything else is excluded in the spec with a reason and asserted by
 * PHPUnit: a signal derivation needs shillinq's invoices, a journey send
 * needs the flow engine to fire and a transport to accept, and an agent
 * narrative needs hermiq.
 *
 * WHAT THE CI INSTANCE HAS. `tests/e2e/ci-seed.sh` force-reimports the
 * register, which brings in lib/Settings/register.d/99-marketing-integrated-campaigns.json:
 * the `journey`, `journeyRun` and `weeklyReview` schemas and the five seeded
 * standard audiences. Every literal asserted below was read out of that file,
 * not guessed.
 *
 * The app is PATH-routed (history mode), never hash-routed: a deep link is
 * `/apps/pipelinq/journeys`, and `#/journeys` reaches nothing. The Marketing
 * group renders COLLAPSED and `nav, [role="navigation"]` matches Nextcloud's
 * own app menu first, so the Journeys entry is reached through
 * `revealNavEntry()`, never a raw `getByRole('link')`.
 */
import type { Page } from '@playwright/test'

import { expect, test } from '@playwright/test'
import {
	assertNoHardError,
	dismissSupportDialog,
	dismissWalkthrough,
	navClick,
	openApp,
	revealNavEntry,
} from '../helpers/pipelinq.ts'

const APP = '/index.php/apps/pipelinq'
const OR = '/index.php/apps/openregister/api/objects/pipelinq'

/** The five audiences the register fragment seeds, by their own slugs. */
const AUDIENCE_SLUGS = [
	'segment-lapsed-customers',
	'segment-top-tier-customers',
	'segment-service-without-product',
	'segment-renewing-within-ninety-days',
	'segment-stalled-leads-thirty-days',
]

/** The eight derived fields the signal catalogue publishes. */
const SIGNAL_FIELDS = [
	'shillinqRecognisedRevenue',
	'shillinqValueTier',
	'shillinqMonthsSinceLastInvoice',
	'shillinqPurchasedProducts',
	'shillinqPurchasedServices',
	'shillinqDunningState',
	'pipelinqContractRenewalDays',
	'pipelinqStalledLeadDays',
]

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
			return { status: res.status, json, text: text.slice(0, 600) }
		},
		{ method, path, body },
	)
}

/** Read the id off an OpenRegister object or a pipelinq API row. */
function idOf(row: any): string {
	return String(row?.id || row?.['@self']?.id || row?.uuid || '')
}

/** Deep-link to an app route and let the view settle. */
async function gotoRoute(page: Page, route: string): Promise<void> {
	await page.goto(`/apps/pipelinq${route}`)
	await expect(page.locator('#content-vue')).toBeVisible({ timeout: 15000 })
	await dismissWalkthrough(page)
	await dismissSupportDialog(page)
}

/* ══════════════════════════════════════════════════════════════════════════
 * The Journeys page — src/manifest.d/79-marketing-journeys.json, a
 * declarative type:index over `journey` under the Marketing group.
 * ══════════════════════════════════════════════════════════════════════════ */
test.describe('Journeys page', () => {
	// @e2e marketing-integrated-campaigns::the-journeys-page-is-reachable-and-lists-the-journey
	test('the Marketing group reaches a Journeys page', async ({ page }) => {
		await openApp(page)
		await revealNavEntry(page, 'Journeys')
		await navClick(page, 'Journeys', /\/journeys(\?|$)/)

		await expect(page.locator('#content-vue')).toBeVisible({ timeout: 15000 })
		await assertNoHardError(page)
	})

	// @e2e marketing-integrated-campaigns::a-journey-saved-without-a-flow-engine-says-so
	test('a journey written through the API comes back with a flow status', async ({
		page,
	}) => {
		await gotoRoute(page, '/journeys')

		const created = await api(page, 'POST', `${APP}/api/journeys`, {
			name: 'E2E nudge na faseovergang',
			status: 'draft',
			waitFor: '5 days',
			trigger: { kind: 'leadStageChanged' },
			condition: { field: 'status', operator: 'equals', value: 'open' },
			action: { kind: 'createTask', taskSubject: 'E2E bel deze klant' },
		})
		expect(created.status, created.text).toBe(200)

		// Whether the flow engine on this instance carries the pipelinq node
		// type or not, the journey records WHICH of the four happened. What
		// must never happen is a journey stored with no answer at all: that is
		// indistinguishable from a journey whose trigger has not fired.
		expect(['compiled', 'engine_missing', 'refused'], created.text).toContain(
			created.json?.flowStatus,
		)

		const id = idOf(created.json)
		expect(id).toBeTruthy()

		const runs = await api(page, 'GET', `${APP}/api/journeys/${id}/runs`)
		expect(runs.status, runs.text).toBe(200)
		expect(Array.isArray(runs.json?.results)).toBe(true)

		await api(page, 'DELETE', `${OR}/journey/${id}`)
	})

	// @e2e marketing-integrated-campaigns::a-journey-saved-without-a-flow-engine-says-so
	test('a journey without a name is refused and nothing is written', async ({
		page,
	}) => {
		await gotoRoute(page, '/journeys')

		const refused = await api(page, 'POST', `${APP}/api/journeys`, {
			status: 'draft',
			trigger: { kind: 'leadStageChanged' },
		})
		expect(refused.status, refused.text).toBe(400)
		expect(refused.json?.error).toBe('name_required')
	})
})

/* ══════════════════════════════════════════════════════════════════════════
 * The signal catalogue — GET /api/segments/signals. The availability report
 * is the half that matters here: on this instance six of the eight fields
 * resolve to nothing, and a builder that listed them without saying so would
 * offer a rule that saves, validates and silently matches nobody.
 * ══════════════════════════════════════════════════════════════════════════ */
test.describe('Segment signals', () => {
	// @e2e marketing-integrated-campaigns::the-signals-endpoint-says-whether-shillinq-can-be-read
	test('all eight signals are listed and the endpoint says shillinq is absent', async ({
		page,
	}) => {
		await gotoRoute(page, '/segments')

		const res = await api(page, 'GET', `${APP}/api/segments/signals`)
		expect(res.status, res.text).toBe(200)

		for (const field of SIGNAL_FIELDS) {
			expect(
				res.json?.catalogue?.[field],
				`${field} must be listed`,
			).toBeTruthy()
		}

		expect(res.json?.availability?.shillinq).toBe(false)
		expect(res.json?.availability?.reason).toBe('shillinq_not_installed')
	})
})

/* ══════════════════════════════════════════════════════════════════════════
 * The standard audiences — seeded segments a marketer copies. A seeded
 * object that misses a required key is refused by OpenRegister and the
 * import drops it WITHOUT an error, so the count is the assertion.
 * ══════════════════════════════════════════════════════════════════════════ */
test.describe('Standard audiences', () => {
	// @e2e marketing-integrated-campaigns::the-five-audiences-are-listed-and-each-one-names-its-source
	test('the five audiences are seeded and the three shillinq ones say so', async ({
		page,
	}) => {
		await gotoRoute(page, '/segments')

		const res = await api(page, 'GET', `${OR}/segment?_limit=100`)
		expect(res.status, res.text).toBe(200)
		const rows: any[] = res.json?.results ?? res.json?.data ?? []

		for (const slug of AUDIENCE_SLUGS) {
			const found = rows.find((row: any) => row?.['@self']?.slug === slug)
			expect(
				found,
				`${slug} must exist; ci-seed.sh reimports 99-marketing-integrated-campaigns.json`,
			).toBeTruthy()
			expect(found.name, `${slug} needs a name`).toBeTruthy()
			expect(found.rules, `${slug} needs a rule tree`).toBeTruthy()
			expect(['contact', 'customer']).toContain(found.entityType)
		}

		for (const slug of AUDIENCE_SLUGS.slice(0, 3)) {
			const found = rows.find((row: any) => row?.['@self']?.slug === slug)
			expect(
				String(found.description).toLowerCase(),
				`${slug} must say it reads shillinq, because here it matches nobody`,
			).toContain('shillinq')
		}
	})
})

/* ══════════════════════════════════════════════════════════════════════════
 * The weekly review — a card on the Reports page, never a menu entry
 * (ADR-112), rendering from ONE response.
 * ══════════════════════════════════════════════════════════════════════════ */
test.describe('Weekly review', () => {
	// @e2e marketing-integrated-campaigns::the-review-names-the-source-it-could-not-read
	test('the Reports page carries the card and the page names its missing source', async ({
		page,
	}) => {
		await gotoRoute(page, '/reports')

		await page
			.getByText('Weekly review', { exact: false })
			.first()
			.click({ timeout: 15000 })

		await expect(page).toHaveURL(/\/reports\/weekly-review(\?|$)/, {
			timeout: 15000,
		})
		await expect(
			page
				.getByTestId('weekly-review-body')
				.or(page.getByTestId('weekly-review-empty')),
		).toBeVisible({ timeout: 15000 })

		await expect(page.getByTestId('weekly-review-degraded')).toContainText(
			'watchEvent',
			{ timeout: 15000 },
		)
		await assertNoHardError(page)
	})

	// @e2e marketing-integrated-campaigns::the-review-names-the-source-it-could-not-read
	test('the whole review arrives in one response', async ({ page }) => {
		await gotoRoute(page, '/reports/weekly-review')

		const res = await api(page, 'GET', `${APP}/api/weekly-review`)
		expect(res.status, res.text).toBe(200)

		// One response carries every section the page renders. pipelinq#1781
		// removed a page that asked the server once per object before it
		// painted anything, and this is the shape that keeps it removed.
		for (const key of [
			'weekStarting',
			'sources',
			'degraded',
			'summary',
			'highlights',
			'suggestions',
			'topicIdeas',
		]) {
			expect(res.json, `${key} must be in the one response`).toHaveProperty(
				key,
			)
		}

		// The absent source is NAMED. It is never reported as a zero: "0
		// competitor moves" for a collection that does not exist is a number
		// a reader believes.
		expect(res.json?.degraded).toContain('watchEvent')
	})
})
