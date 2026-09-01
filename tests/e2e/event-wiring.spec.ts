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
 * Only the WBS trio is covered here. The other reconnected chains
 * (`createLead`, `requestConsent`, `skipAndSend`, `rangeChange`) need app state
 * this spec cannot create cheaply — an Ideal Customer Profile before any
 * prospect card renders, and a blast with missing consent — and two more
 * (`validateLeaf`, `searchContacts`) live in components nothing imports, so
 * they are absent from every built chunk and cannot be reached at all.
 */
import { test, expect } from '@playwright/test'
import { openApp } from './helpers/pipelinq'

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
	page: import('@playwright/test').Page,
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
async function openProjectDetail(
	page: import('@playwright/test').Page,
): Promise<void> {
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
async function dialogCount(page: import('@playwright/test').Page): Promise<number> {
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
