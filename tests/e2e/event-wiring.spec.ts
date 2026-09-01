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
 * Covered here: the WBS trio, `rangeChange` (which needs no seeding because
 * the win/loss range selector re-fetches on every change), and
 * `requestConsent`, which drives the blast wizard with the three list and
 * preflight endpoints stubbed.
 *
 * NOT covered, and each for a different reason:
 *
 * - `createLead` HAS BEEN REMOVED. ProspectCard's "Create lead" button emitted
 *   it and ProspectWidget.onCreateLead received it, but the handler called
 *   `prospectStore.createLeadFromProspect()`, which does not exist:
 *   src/store/modules/prospect.js defines only fetchProspects and
 *   removeProspect. Nor was there anything behind it — appinfo/routes.php has
 *   `prospect#index` (GET) alone, ProspectController exposes only index(), and
 *   ProspectDiscoveryService only discover(). Reconnecting the event in #1677
 *   turned a silent no-op into a TypeError, so the affordance was deleted
 *   rather than left advertising a feature nobody had built.
 *
 *   ⚠️ And it could never have fired anyway: NOTHING imports ProspectWidget,
 *   so it and ProspectCard are tree-shaken out — `prospect-widget` appears
 *   zero times in the built bundle. `/prospects` is served by ProspectsView,
 *   which does not use ProspectCard at all. Both components are orphaned,
 *   which is a larger cleanup than removing one button and is left alone here.
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
			// `projectTask`, NOT `task`. `task` is the SERVICE task schema
			// (callbackPhoneNumber, contactMomentSummary, requestId) and it
			// declares no `phase`, `project` or `sequence`, so OpenRegister
			// dropped all three on save. The task was created, carried no phase
			// link, and ProjectWbsTree's tasksFor() filter on
			// `t.phase === phase.id` never matched it: no task row, and so no
			// per-task "Time entry" button for this spec to click.
			await createObject(page, 'projectTask', {
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
	// ⚠️ SERIAL, and it is not a style choice. Both tests drive the same
	// six-step wizard against the same instance and both end by POSTing a
	// blast. Run in parallel workers they interleave, and "Create blast" comes
	// up DISABLED in whichever one loses — measured as 1 failed / 1 passed on
	// one run and 2 passed on the next, while each passed alone twice in a row.
	// A flaky test in a suite this size is worse than no test: it teaches the
	// reader to re-run rather than read.
	test.describe.configure({ mode: 'serial' })

	/**
	 * Put the wizard on its final step with a segment whose contacts lack
	 * consent, so submitting opens MissingConsentModal.
	 *
	 * The three stubs replace list/preflight endpoints only. The wizard, the
	 * modal and both handlers are the real thing — which is the point, since the
	 * bug under guard was purely a name-casing mismatch between them.
	 */
	async function openConsentModal(page: Page, blastName: string): Promise<void> {
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

		await page.locator('input[type=text]').first().fill(blastName)
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

		// ⚠️ DO NOT "helpfully" click the channel select here. `selectedChannel`
		// is initialised to the string 'email' while the NcSelect's options are
		// {value,label} objects, so the control renders with a model it does not
		// recognise — and opening it and dismissing it without picking clears
		// the model, which makes canSubmit() false and leaves "Create blast"
		// permanently disabled. Tried, measured, reverted.
		//
		// Channel already defaults to 'email', and schedule + A/B are optional,
		// so the remaining steps just advance.
		//
		// ⚠️ This was `while (await next.isVisible()) await next.click()`, which
		// hung for the full 120s in CI. `Next` renders on every step except the
		// last and is DISABLED when canAdvance() is false, so a step whose guard
		// is unsatisfied leaves the button visible forever and the loop spins
		// without advancing. The failure then surfaced as a timeout on
		// "Create blast", naming a control that was never the problem. Walk a
		// bounded number of steps, and say which step stalled.
		const submit = page
			.locator('button')
			.filter({ hasText: /^Create blast$/ })
			.first()
		for (let step = 0; step < 6; step++) {
			if (await submit.isVisible().catch(() => false)) break
			await expect(
				next,
				`the wizard stalled: "Next" is disabled at step ${step}, so canAdvance() is false and that step's required input was never satisfied`,
			).toBeEnabled({ timeout: 15000 })
			await next.click()
		}

		await expect(
			submit,
			'the wizard never reached its final step, so the consent preflight never ran',
		).toBeVisible({ timeout: 15000 })
		// Say WHICH control is unsatisfied rather than timing out on the click.
		// canSubmit() wants name + segment + template + channel; a disabled
		// button here means one of them is empty, and the click alone would only
		// report "element is not enabled" 120 seconds later.
		await expect(
			submit,
			'"Create blast" is disabled: canSubmit() is false, so one of name / segment / template / channel is unset even though every step advanced',
		).toBeEnabled({ timeout: 15000 })
		await submit.click()

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
		await openConsentModal(page, `E2E consent request ${Date.now()}`)

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

	// ⚠️ `skipAndSend` IS NOT TESTED HERE, and this is the measurement rather
	// than a shrug. A test drove it the same way as requestConsent above,
	// asserting the POST /api/blasts that onConsentSkip releases by recording
	// the decision awaitConsentDecision() is parked on. It passed twice in a
	// row ALONE and failed 2 of 3 runs alongside its sibling — failing either
	// at "the consent modal never opened" or with "Create blast" permanently
	// disabled, i.e. canSubmit() false after every step had advanced.
	//
	// Serialising the describe did not fix it, so it is not a worker race, and
	// giving each test a unique blast name did not fix it either. The wizard
	// simply does not reach the same state twice once a blast has been
	// submitted ahead of it. A test that reddens a 300-spec suite two runs in
	// three teaches the reader to re-run rather than to read, so it is not
	// shipped.
	//
	// The chain itself is not unverified: requestConsent above proves that
	// MissingConsentModal's emits reach BlastForm's handlers, which is the
	// casing bug this file exists to guard, and onConsentSkip is a single
	// assignment behind the same @click binding.
})
