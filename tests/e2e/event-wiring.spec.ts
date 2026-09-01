/*
 * SPDX-FileCopyrightText: 2026 Pipelinq Contributors
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Child-to-parent event wiring on the project WBS tree.
 *
 * WHY THIS FILE EXISTS. Nine emit/listener pairs in this app were broken for
 * an unknown length of time: the child emitted a KEBAB-case event
 * (`$emit('add-phase')`) while the parent listened in CAMEL case
 * (`@addPhase`). Vue matches event names literally, so the handler simply
 * never ran. Nothing threw, nothing logged, and the 55 unit tests stayed green
 * throughout — a suite that passes while the feature is dead cannot be the
 * guard. The 31 eslint `vue/custom-event-name-casing` findings that would have
 * named it were sitting in `eslint-suppressions.json`.
 *
 * The three assertions below are therefore deliberately about the HANDLER
 * FIRING, not about the markup: each clicks the real affordance and asserts the
 * dialog the parent's handler opens. Under the bug every one of these clicks
 * was a silent no-op, so this spec fails loudly if the casing regresses.
 *
 * ⚠️ The failure would surface FOUR lines later than its cause. When the click
 * lands but nothing happens, the timeout is reported against the dialog
 * assertion, which reads as "the dialog is broken" rather than "the event never
 * arrived". If this spec ever goes red, suspect the emit/listener names first.
 *
 * Covered here: the WBS trio, plus `rangeChange` (below), which needs no
 * seeding because the win/loss range selector re-fetches on every change.
 *
 * NOT covered, and each for a different reason:
 *
 * - `createLead` is a DEAD AFFORDANCE, not a wiring gap. ProspectCard's
 *   "Create lead" button emits it, ProspectWidget.onCreateLead receives it, and
 *   the handler then calls `prospectStore.createLeadFromProspect()` — which
 *   does not exist. `src/store/modules/prospect.js` defines only
 *   `fetchProspects` and `removeProspect`, and there is no backend behind it
 *   either: appinfo/routes.php has `prospect#index` (GET) alone,
 *   ProspectController exposes only index(), and ProspectDiscoveryService only
 *   discover(). Reconnecting the event turned a silent no-op into a TypeError.
 *   Writing a test here would assert a feature nobody has built; it needs a
 *   product decision about what a lead created from a prospect contains.
 * `requestConsent` / `skipAndSend` ARE covered, but only by stubbing the three
 * list/preflight endpoints the six-step wizard needs (segments, templates,
 * compliance). That is deliberate: this file is about whether the emit reaches
 * the parent, not about the compliance backend, and seeding a segment whose
 * contacts genuinely lack consent would make the spec depend on data no fixture
 * ships. Everything downstream of the click is real component code.
 * - `validateLeaf` / `searchContacts` live in components nothing imports, so
 *   they are absent from every built chunk and cannot be reached at all.
 */
import type { Page } from '@playwright/test'

import { expect, test } from '@playwright/test'
import { openApp } from './helpers/pipelinq.ts'

/** The demo project the seed ships; its detail page hosts the WBS tree. */
const PROJECT_ID = 'a1c4e2b3-4d5f-4e8a-9b6c-7d8e9f0a1b2c'

/**
 * Create one object through OpenRegister's REST API from inside the page, so
 * the request carries the authenticated session and CSRF token.
 *
 * ⚠️ The list parameter is `_limit`, NOT `limit`. A `?limit=` query is accepted
 * and answers `total: 0` — it does not error — so a wrong parameter reads as an
 * empty register and invites the conclusion that the seed failed.
 */
async function createObject(
	page: Page,
	schema: string,
	body: Record<string, unknown>,
): Promise<string | null> {
	return await page.evaluate(
		async ({ schema, body }) => {
			const w = window as unknown as { OC?: { requestToken?: string } }
			const token =
				w.OC?.requestToken
				?? document.head
					.querySelector('meta[name=requesttoken]')
					?.getAttribute('content')
				?? ''
			const res = await fetch(
				`/index.php/apps/openregister/api/objects/pipelinq/${schema}`,
				{
					method: 'POST',
					headers: {
						'Content-Type': 'application/json',
						requesttoken: token,
					},
					credentials: 'include',
					body: JSON.stringify(body),
				},
			)
			if (!res.ok) return null
			const created = await res.json()
			return created?.id ?? created?.['@self']?.id ?? null
		},
		{ schema, body },
	)
}

/** Open the seeded project's detail page, where the WBS tree lives. */
async function openProjectDetail(page: Page): Promise<void> {
	// openApp() already ran in beforeEach — calling it again here doubled the
	// shell boot and pushed each test past its budget.
	//
	// The shell routes on HISTORY, so this path deep-link lands on the project
	// detail directly. It used to reset the SPA to the Dashboard under hash
	// routing (see helpers/pipelinq.ts).
	await page.goto(`/apps/pipelinq/projects/${PROJECT_ID}`)
	await expect(page.locator('.wbs-tree, [class*=wbs]').first()).toBeVisible({
		timeout: 30000,
	})
}

/** The dialog count, used as the observable effect of a handler running. */
async function dialogCount(page: Page): Promise<number> {
	return await page.evaluate(
		() => document.querySelectorAll('[role=dialog], .modal-container').length,
	)
}

test.describe('WBS tree child-to-parent events', () => {
	// The tree fetches phases, tasks and activities as separate relation
	// queries before any per-phase affordance renders; 60s is not enough on a
	// cold shell.
	test.setTimeout(120000)

	test.beforeEach(async ({ page }) => {
		await openApp(page)
		// A phase and a task must exist before the per-phase and per-task
		// affordances render at all; "Add phase" is the only one visible on an
		// empty tree. Seeded per test run, so the spec does not depend on
		// whatever earlier runs left behind.
		const phaseId = await createObject(page, 'projectPhase', {
			project: PROJECT_ID,
			title: 'e2e event wiring phase',
			name: 'e2e event wiring phase',
			status: 'open',
			sequence: 1,
		})
		if (phaseId) {
			await createObject(page, 'task', {
				phase: phaseId,
				project: PROJECT_ID,
				title: 'e2e event wiring task',
				name: 'e2e event wiring task',
				status: 'open',
				sequence: 1,
			})
		}
	})

	test('addPhase reaches ProjectDetail and opens the phase dialog', async ({
		page,
	}) => {
		await openProjectDetail(page)
		const before = await dialogCount(page)

		// Native dispatch: the themed NcButton swallows Playwright's synthetic
		// click, the same reason the other specs in this suite click natively.
		await page.evaluate(() => {
			const button = [...document.querySelectorAll('button')].find(
				(el) => (el.innerText || '').trim().toLowerCase() === 'add phase',
			)
			button?.click()
		})

		await expect
			.poll(async () => await dialogCount(page), {
				message:
					'clicking "Add phase" opened no dialog — openPhaseDialog() never ran, which means the addPhase event did not reach ProjectDetail',
				timeout: 15000,
			})
			.toBeGreaterThan(before)
	})

	test('addTask reaches ProjectDetail and opens the task dialog', async ({
		page,
	}) => {
		await openProjectDetail(page)
		const addTask = page
			.locator('button')
			.filter({ hasText: /^Add task$/ })
			.first()
		await expect(addTask).toBeVisible({ timeout: 30000 })
		const before = await dialogCount(page)

		await page.evaluate(() => {
			const button = [...document.querySelectorAll('button')].find(
				(el) => (el.innerText || '').trim().toLowerCase() === 'add task',
			)
			button?.click()
		})

		await expect
			.poll(async () => await dialogCount(page), {
				message:
					'clicking "Add task" opened no dialog — openTaskDialog() never ran, which means the addTask event did not reach ProjectDetail',
				timeout: 15000,
			})
			.toBeGreaterThan(before)
	})

	test('addActivity reaches ProjectDetail and opens the time-entry dialog', async ({
		page,
	}) => {
		await openProjectDetail(page)
		// The addActivity emit sits behind the per-task button LABELLED
		// "Time entry" — the label and the event name deliberately differ.
		const timeEntry = page
			.locator('button')
			.filter({ hasText: /^Time entry$/ })
			.first()
		await expect(timeEntry).toBeVisible({ timeout: 30000 })
		const before = await dialogCount(page)

		await page.evaluate(() => {
			const button = [...document.querySelectorAll('button')].find(
				(el) => (el.innerText || '').trim().toLowerCase() === 'time entry',
			)
			button?.click()
		})

		await expect
			.poll(async () => await dialogCount(page), {
				message:
					'clicking "Time entry" opened no dialog — openActivityDialog() never ran, which means the addActivity event did not reach ProjectDetail',
				timeout: 15000,
			})
			.toBeGreaterThan(before)
	})
})

test.describe('rapportage win/loss range events', () => {
	test.setTimeout(120000)

	/**
	 * WinLossWidget emits `rangeChange`; LeadAnalyticsSection.onRangeChange
	 * stores the range and calls loadStats(), which re-fetches
	 * GET /api/rapportage/pipeline-stats with dateFrom/dateTo.
	 *
	 * That re-fetch is the whole assertion, and it is a clean one because the
	 * widget mounts on "All time", which sends NO date parameters. So a request
	 * carrying `dateFrom` can only exist if the emit reached the parent. Under
	 * the kebab/camel casing bug the selector still changed and the chart still
	 * looked alive — nothing re-fetched, and the numbers silently stayed on the
	 * all-time window.
	 */
	test('rangeChange reaches LeadAnalyticsSection and re-fetches the stats', async ({
		page,
	}) => {
		const datedRequests: string[] = []
		page.on('request', (request) => {
			const url = request.url()
			if (url.includes('pipeline-stats') && url.includes('dateFrom')) {
				datedRequests.push(url)
			}
		})

		await openApp(page)
		await page.goto('/apps/pipelinq/rapportage')

		// The initial mount fetch carries no date parameters, so nothing should
		// have been recorded yet; if it has, the assertion below would pass for
		// the wrong reason.
		const rangeSelect = page
			.locator('.vs__dropdown-toggle, .v-select')
			.filter({ hasText: /All time|Last 30 days|Last 90 days|Last 12 months/ })
			.first()
		await expect(rangeSelect).toBeVisible({ timeout: 60000 })
		expect(
			datedRequests,
			'a dated pipeline-stats request fired before the range was ever changed — the assertion below would not prove anything',
		).toHaveLength(0)

		await rangeSelect.click()
		await page
			.locator('.vs__dropdown-menu li', { hasText: 'Last 30 days' })
			.first()
			.click()

		await expect
			.poll(() => datedRequests.length, {
				message:
					'selecting "Last 30 days" produced no pipeline-stats request carrying dateFrom — onRangeChange() never ran, which means the rangeChange event did not reach LeadAnalyticsSection',
				timeout: 30000,
			})
			.toBeGreaterThan(0)
	})
})

test.describe('blast consent-modal events', () => {
	test.setTimeout(120000)

	/**
	 * Put the wizard on its final step with a segment whose contacts lack
	 * consent, so submitting opens MissingConsentModal.
	 *
	 * The three stubs replace list/preflight endpoints only. The wizard, the
	 * modal and both handlers are the real thing — which is the point, since the
	 * bug under guard was purely a name-casing mismatch between them.
	 */
	async function openConsentModal(page: Page): Promise<void> {
		await page.route('**/apps/pipelinq/api/segments', async (route) => {
			await route.fulfill({
				status: 200,
				contentType: 'application/json',
				body: JSON.stringify({
					data: [{ id: 'seg-e2e', name: 'E2E segment', estimatedSize: 3 }],
				}),
			})
		})
		await page.route('**/apps/pipelinq/api/templates', async (route) => {
			await route.fulfill({
				status: 200,
				contentType: 'application/json',
				body: JSON.stringify({
					// `channel` must be 'email': filteredTemplates() filters on
					// selectedChannel, which defaults to 'email', so an 'sms'
					// template would leave the select empty and canAdvance false.
					data: [
						{ id: 'tpl-e2e', name: 'E2E template', channel: 'email' },
					],
				}),
			})
		})
		await page.route('**/api/segments/*/compliance**', async (route) => {
			await route.fulfill({
				status: 200,
				contentType: 'application/json',
				body: JSON.stringify({
					missingConsent: [
						{ id: 'c-e2e', name: 'Contact Without Consent' },
					],
				}),
			})
		})

		await openApp(page)
		await page.goto('/apps/pipelinq/blasts/new')

		await page.locator('input[type=text]').first().fill('E2E consent blast')
		const next = page
			.locator('button')
			.filter({ hasText: /^Next$/ })
			.first()
		await next.click()

		// Segment, then template. Both are NcSelects, so the option is a list
		// item in the dropdown rather than an <option>.
		await page.locator('.vs__dropdown-toggle').first().click()
		await page
			.locator('.vs__dropdown-menu li', { hasText: 'E2E segment' })
			.first()
			.click()
		await next.click()

		await page.locator('.vs__dropdown-toggle').first().click()
		await page
			.locator('.vs__dropdown-menu li', { hasText: 'E2E template' })
			.first()
			.click()
		await next.click()

		// Channel already defaults to 'email', and schedule + A/B are optional,
		// so the remaining steps just advance.
		while (await next.isVisible().catch(() => false)) {
			await next.click()
		}

		await page
			.locator('button')
			.filter({ hasText: /^Create blast$/ })
			.first()
			.click()

		await expect(
			page
				.locator('button')
				.filter({ hasText: /^Skip and send$/ })
				.first(),
			'the consent modal never opened, so neither consent event can be exercised',
		).toBeVisible({ timeout: 30000 })
	}

	/**
	 * MissingConsentModal emits `requestConsent`; BlastForm.onConsentRequest
	 * shows a temporary notification. That notification is the observable
	 * effect — under the casing bug the button was a silent no-op.
	 */
	test('requestConsent reaches BlastForm and announces the consent flow', async ({
		page,
	}) => {
		await openConsentModal(page)

		await page
			.locator('button')
			.filter({ hasText: /^Request consent$/ })
			.first()
			.click()

		await expect(
			page.getByText(/consent-request flow will be opened/i).first(),
			'clicking "Request consent" showed no notification — onConsentRequest() never ran, which means the requestConsent event did not reach BlastForm',
		).toBeVisible({ timeout: 15000 })
	})

	/**
	 * MissingConsentModal emits `skipAndSend`; BlastForm.onConsentSkip records
	 * the decision, which releases awaitConsentDecision() and lets submit()
	 * POST the blast. The POST is therefore the observable effect: with the
	 * event unwired, submit() stays parked on the pending decision forever.
	 */
	test('skipAndSend reaches BlastForm and releases the blocked submit', async ({
		page,
	}) => {
		const posted: string[] = []
		page.on('request', (request) => {
			if (
				request.method() === 'POST'
				&& request.url().includes('/apps/pipelinq/api/blasts')
			) {
				posted.push(request.url())
			}
		})

		await openConsentModal(page)
		expect(
			posted,
			'the blast was POSTed before the consent decision was made — the assertion below would not prove anything',
		).toHaveLength(0)

		await page
			.locator('button')
			.filter({ hasText: /^Skip and send$/ })
			.first()
			.click()

		await expect
			.poll(() => posted.length, {
				message:
					'clicking "Skip and send" issued no POST /api/blasts — onConsentSkip() never ran, so awaitConsentDecision() never resolved and submit() stayed parked',
				timeout: 30000,
			})
			.toBeGreaterThan(0)
	})
})
