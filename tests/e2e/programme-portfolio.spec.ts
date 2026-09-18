/*
 * SPDX-FileCopyrightText: 2026 Pipelinq Contributors
 * SPDX-License-Identifier: EUPL-1.2
 *
 * The programme above the cases: work held by reference, never copied.
 *
 * WHY THIS FILE EXISTS. A gemeente programme sits above the zaken:
 * "Omgevingswet implementatie", "Sloop Kerkstraat". It holds cases, tasks and a
 * team, and none of that is a case.
 *
 * ⚠️ THE FAILURE THIS GUARDS IS A CASE IN TWO PROGRAMMES. Effort and progress
 * then roll up twice, and neither figure is wrong in a way anybody can see. So
 * the second link is asserted to be refused, and the refusal is asserted to
 * name the programme that already holds the work.
 *
 * ⚠️ THE SECOND FAILURE IS A ZERO THAT MEANS "WE CANNOT TELL". A progress
 * figure without its mode cannot be argued with, so the mode is asserted
 * alongside it.
 *
 * Covers, from openspec/changes/the-project-above-the-cases:
 *   REQ-PRJ-002 "A case cannot be in two programmes at once"
 *   REQ-PRJ-002 "An unresolvable reference is shown, not swallowed"
 *   REQ-PRJ-003 "The reader can tell a typed number from a derived one"
 */
import type { Page } from '@playwright/test'

import { expect, test } from '@playwright/test'
import { openApp } from './helpers/pipelinq.ts'

/** Distinctive enough that finding it proves the read, not a coincidence. */
const STAMP = Date.now()

/**
 * Call an app route from inside the page, carrying the session and its token.
 *
 * @param page   The page to run in.
 * @param method The HTTP verb.
 * @param url    The app-relative url.
 * @param body   The request body, for a write.
 *
 * @return The status and the parsed body.
 */
async function api(
	page: Page,
	method: string,
	url: string,
	body?: Record<string, unknown>,
): Promise<{ status: number; body: Record<string, unknown> }> {
	return await page.evaluate(
		async ({ method, url, body }) => {
			const res = await fetch(url, {
				method,
				headers: {
					'Content-Type': 'application/json',
					requesttoken:
						document
							.querySelector('head[data-requesttoken]')
							?.getAttribute('data-requesttoken') ?? '',
				},
				body: body === undefined ? undefined : JSON.stringify(body),
			})
			const text = await res.text()
			let parsed: Record<string, unknown>
			try {
				parsed = JSON.parse(text)
			} catch {
				parsed = { raw: text.slice(0, 300) }
			}
			return { status: res.status, body: parsed }
		},
		{ method, url, body },
	)
}

/**
 * Seed one object through OpenRegister's REST API.
 *
 * @param page   The page to run in.
 * @param schema The schema slug.
 * @param object The object body.
 *
 * @return The created id.
 */
async function seed(
	page: Page,
	schema: string,
	object: Record<string, unknown>,
): Promise<string> {
	const res = await api(
		page,
		'POST',
		`/index.php/apps/openregister/api/objects/pipelinq/${schema}`,
		object,
	)
	expect(
		res.status,
		`could not seed a ${schema} — ${JSON.stringify(res.body).slice(0, 300)}`,
	).toBeLessThan(400)
	const created = res.body as Record<string, never>
	return (created?.id ?? created?.['@self']?.id) as string
}

test.describe('the programme above the cases', () => {
	// Several register writes plus a cold shell boot.
	test.setTimeout(180000)

	test('a case cannot be in two programmes, and the refusal names the first', async ({
		page,
	}) => {
		await openApp(page)

		const first = await seed(page, 'programme', {
			name: `E2E Omgevingswet ${STAMP}`,
			status: 'active',
			progressMode: 'fromTasks',
		})
		const second = await seed(page, 'programme', {
			name: `E2E Verkiezingen ${STAMP}`,
			status: 'active',
		})

		const zaak = `zaak-${STAMP}`

		const linked = await api(
			page,
			'POST',
			`/index.php/apps/pipelinq/api/programmes/${first}/work-items`,
			{
				domainObjectType: 'dossiq:zaak',
				domainObjectRef: zaak,
				title: `E2E bezwaar ${STAMP}`,
			},
		)
		expect(
			linked.status,
			`the first link was refused — ${JSON.stringify(linked.body).slice(0, 300)}`,
		).toBe(201)

		const refused = await api(
			page,
			'POST',
			`/index.php/apps/pipelinq/api/programmes/${second}/work-items`,
			{ domainObjectType: 'dossiq:zaak', domainObjectRef: zaak },
		)

		expect(refused.status).toBe(409)
		expect(
			JSON.stringify(refused.body),
			'the refusal has to name the programme to take the work out of',
		).toContain(first)
	})

	test('a reference to an absent app is listed as unresolved', async ({
		page,
	}) => {
		await openApp(page)

		const programme = await seed(page, 'programme', {
			name: `E2E Sloop Kerkstraat ${STAMP}`,
			status: 'active',
		})

		await api(
			page,
			'POST',
			`/index.php/apps/pipelinq/api/programmes/${programme}/work-items`,
			{
				domainObjectType: 'anapp:thatisnotinstalled',
				domainObjectRef: `obj-${STAMP}`,
				title: `E2E onbereikbaar item ${STAMP}`,
			},
		)

		const items = await api(
			page,
			'GET',
			`/index.php/apps/pipelinq/api/programmes/${programme}/work-items`,
		)
		expect(items.status).toBe(200)

		const rows = (items.body.workItems ?? []) as Array<Record<string, unknown>>
		// LISTED, and listed as unresolved. A link that disappeared and a
		// programme with no links must not look the same.
		expect(rows).toHaveLength(1)
		expect(rows[0].resolved).toBe(false)
		expect(rows[0].domainObjectType).toBe('anapp:thatisnotinstalled')
	})

	test('a progress figure always names the mode that produced it', async ({
		page,
	}) => {
		await openApp(page)

		const programme = await seed(page, 'programme', {
			name: `E2E voortgang ${STAMP}`,
			status: 'active',
			progressMode: 'manual',
			manualProgress: 40,
		})

		const typed = await api(
			page,
			'GET',
			`/index.php/apps/pipelinq/api/programmes/${programme}/progress`,
		)

		expect(typed.status).toBe(200)
		expect(typed.body.mode).toBe('manual')
		expect(typed.body.progress).toBe(40)

		const derived = await seed(page, 'programme', {
			name: `E2E afgeleide voortgang ${STAMP}`,
			status: 'active',
			progressMode: 'fromTasks',
		})

		await seed(page, 'programmeTask', { programme: derived, title: 'Een', status: 'closed' })
		await seed(page, 'programmeTask', { programme: derived, title: 'Twee', status: 'open' })

		const fromTasks = await api(
			page,
			'GET',
			`/index.php/apps/pipelinq/api/programmes/${derived}/progress`,
		)

		expect(fromTasks.body.mode).toBe('fromTasks')
		expect(
			fromTasks.body.progress,
			'one of two tasks closed, and nobody typed this',
		).toBe(50)
	})
})
