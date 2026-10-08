/*
 * SPDX-FileCopyrightText: 2026 Pipelinq Contributors
 * SPDX-License-Identifier: EUPL-1.2
 *
 * One contact moment, several cases: filed, not copied.
 *
 * WHY THIS FILE EXISTS. One telephone call about three cases used to be three
 * contact moments, and the day the caller corrected something two of them
 * silently stayed wrong. Filing is an act on one record: it appends a case, it
 * records who did it, and it leaves the content alone.
 *
 * ⚠️ THE FAILURE THIS GUARDS IS A COPY THAT LOOKS LIKE A SUCCESS. A "file onto
 * also" that created a second contact moment renders correctly on both cases
 * and is wrong forever after the first edit. So the count is asserted, not only
 * the presence of the row.
 *
 * Covers, from openspec/changes/one-contact-moment-on-several-cases:
 *   REQ-CMS-003 "Each of three cases sees the one call"
 *   REQ-CMS-004 "Filing a call onto a second case creates no second record"
 *   REQ-CMS-006 "The last reference cannot be removed"
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
 * Seed one host object standing in for a case.
 *
 * @param page  The page to run in.
 * @param label A label distinctive to this run.
 *
 * @return The created id.
 */
async function seedHost(page: Page, label: string): Promise<string> {
	const res = await api(
		page,
		'POST',
		'/index.php/apps/openregister/api/objects/pipelinq/client',
		{
			name: `E2E ${label} ${STAMP}`,
			type: 'organization',
			contactsUid: `e2e-${label}-${STAMP}`,
		},
	)
	expect(
		res.status,
		`could not seed a host — ${JSON.stringify(res.body).slice(0, 300)}`,
	).toBeLessThan(400)
	const created = res.body as Record<string, never>
	return (created?.id ?? created?.['@self']?.id) as string
}

/**
 * The contact moments the leaf lists on one host.
 *
 * @param page   The page to run in.
 * @param hostId The host to list on.
 *
 * @return The rows.
 */
async function listOn(
	page: Page,
	hostId: string,
): Promise<Array<Record<string, unknown>>> {
	const res = await api(
		page,
		'GET',
		`/index.php/apps/pipelinq/api/leaves/contact-moments/${hostId}`,
	)
	expect(res.status).toBe(200)
	return (res.body.contactMoments ?? []) as Array<Record<string, unknown>>
}

test.describe('one contact moment on several cases', () => {
	// Several register writes plus a cold shell boot.
	test.setTimeout(180000)

	test('filing a call onto a second case creates no second record', async ({
		page,
	}) => {
		await openApp(page)

		const caseA = await seedHost(page, 'several-a')
		const caseB = await seedHost(page, 'several-b')

		const subject = `E2E een telefoontje over twee zaken ${STAMP}`
		const created = await api(
			page,
			'POST',
			`/index.php/apps/pipelinq/api/leaves/contact-moments/${caseA}`,
			{ title: subject, channel: 'telefoon', direction: 'inbound' },
		)
		expect(created.status).toBe(201)
		const momentId = (created.body.contactMoment as Record<string, string>).id
		expect(momentId).toBeTruthy()

		const filed = await api(
			page,
			'POST',
			`/index.php/apps/pipelinq/api/contact-moments/${momentId}/cases`,
			{ caseId: caseB },
		)
		expect(
			filed.status,
			`filing onto the second case was refused — ${JSON.stringify(filed.body).slice(0, 300)}`,
		).toBe(200)

		// One record, seen from both cases. The COUNT is the assertion: a copy
		// would render correctly on both and be wrong after the first edit.
		const onA = await listOn(page, caseA)
		const onB = await listOn(page, caseB)
		const matchingA = onA.filter((m) => m.subject === subject)
		const matchingB = onB.filter((m) => m.subject === subject)

		expect(matchingA).toHaveLength(1)
		expect(matchingB).toHaveLength(1)
		expect(matchingA[0].id).toBe(matchingB[0].id)
		expect(matchingB[0].shared, 'the second case has to say it is shared').toBe(
			true,
		)
	})

	test('the last case reference cannot be removed', async ({ page }) => {
		await openApp(page)

		const caseA = await seedHost(page, 'last-ref')
		const created = await api(
			page,
			'POST',
			`/index.php/apps/pipelinq/api/leaves/contact-moments/${caseA}`,
			{
				title: `E2E laatste verwijzing ${STAMP}`,
				channel: 'telefoon',
				direction: 'inbound',
			},
		)
		expect(created.status).toBe(201)
		const momentId = (created.body.contactMoment as Record<string, string>).id

		const refused = await api(
			page,
			'DELETE',
			`/index.php/apps/pipelinq/api/contact-moments/${momentId}/cases/${caseA}`,
		)

		expect(refused.status).toBe(409)
		expect(
			JSON.stringify(refused.body),
			'the refusal has to say why, or a handler will try again',
		).toContain('at least one case')

		// And the contact moment is still there, on the case it was on.
		const still = await listOn(page, caseA)
		expect(still.map((m) => m.id)).toContain(momentId)
	})
})
