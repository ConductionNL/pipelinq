/*
 * SPDX-FileCopyrightText: 2026 Pipelinq Contributors
 * SPDX-License-Identifier: EUPL-1.2
 *
 * The contact moments leaf: one schema, appended and listed over the leaf API.
 *
 * WHY THIS FILE EXISTS. A case app used to carry its own contact moment
 * schema, under the same globally unique slug pipelinq already held, so two
 * definitions answered for one name and whichever was reached first won. The
 * fleet keeps pipelinq's, and a host app now appends through this leaf.
 *
 * ⚠️ THE FAILURE THIS GUARDS IS A SILENT WRONG ANSWER, NOT AN ERROR. A leaf
 * that refuses every append, and a leaf that appends to the wrong host, both
 * render as an empty panel. So the assertions here are positive and specific:
 * the row that comes back must be the row that was written, with the direction
 * that was asked for, against the host that was named.
 *
 * Covers, from openspec/changes/contact-moments-on-pipelinq-schema:
 *   REQ-CMD-003 "A handler logs a call on a case"
 *   REQ-CMD-004 "The Communication tab shows both directions"
 */
import type { Page } from '@playwright/test'

import { expect, test } from '@playwright/test'
import { openApp } from './helpers/pipelinq.ts'

/** Distinctive enough that finding it proves the read, not a coincidence. */
const STAMP = Date.now()

/**
 * Call a pipelinq API route from inside the page, so the request carries the
 * authenticated session and its CSRF token.
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
 * @param page     The page to run in.
 * @param register The register slug.
 * @param schema   The schema slug.
 * @param object   The object body.
 *
 * @return The created id, and the reason when it could not be made.
 */
async function seed(
	page: Page,
	register: string,
	schema: string,
	object: Record<string, unknown>,
): Promise<{ id: string | null; error: string }> {
	const res = await api(
		page,
		'POST',
		`/index.php/apps/openregister/api/objects/${register}/${schema}`,
		object,
	)
	if (res.status >= 400) {
		return {
			id: null,
			error: `HTTP ${res.status}: ${JSON.stringify(res.body).slice(0, 300)}`,
		}
	}
	const created = res.body as Record<string, never>
	return {
		id: (created?.id ?? created?.['@self']?.id ?? null) as string | null,
		error: '',
	}
}

test.describe('contact moments leaf', () => {
	// Three register writes plus a cold shell boot.
	test.setTimeout(120000)

	test('a handler logs a call on a host object and the leaf lists it back', async ({
		page,
	}) => {
		await openApp(page)

		// The host stands in for a case: the leaf treats it as an opaque uuid
		// and never learns which app owns it, so a pipelinq client is a
		// faithful host for this contract.
		const host = await seed(page, 'pipelinq', 'client', {
			name: `E2E leaf host ${STAMP}`,
			type: 'organization',
			contactsUid: `e2e-leaf-host-${STAMP}`,
		})
		expect(host.id, `could not seed a host object — ${host.error}`).toBeTruthy()

		const created = await api(
			page,
			'POST',
			`/index.php/apps/pipelinq/api/leaves/contact-moments/${host.id}`,
			{
				title: `E2E gebeld over de aanvraag ${STAMP}`,
				channel: 'telefoon',
				direction: 'inbound',
				summary: 'De aanvrager belde over de doorlooptijd.',
			},
		)
		expect(
			created.status,
			`the leaf refused the append — ${JSON.stringify(created.body).slice(0, 300)}`,
		).toBe(201)

		const listed = await api(
			page,
			'GET',
			`/index.php/apps/pipelinq/api/leaves/contact-moments/${host.id}`,
		)
		expect(listed.status).toBe(200)

		const moments = (listed.body.contactMoments ?? []) as Array<
			Record<string, string>
		>
		// The subject and the direction, not merely a non-empty list: a leaf
		// listing somebody else's contact moments would pass a count check.
		expect(
			moments.map((m) => m.subject),
			'the contact moment that was appended did not come back from the leaf',
		).toContain(`E2E gebeld over de aanvraag ${STAMP}`)
		expect(moments[0].direction).toBe('inbound')
	})

	test('an append without a direction is refused, naming the field', async ({
		page,
	}) => {
		await openApp(page)

		const host = await seed(page, 'pipelinq', 'client', {
			name: `E2E leaf host no direction ${STAMP}`,
			type: 'organization',
			contactsUid: `e2e-leaf-nodir-${STAMP}`,
		})
		expect(host.id, `could not seed a host object — ${host.error}`).toBeTruthy()

		const refused = await api(
			page,
			'POST',
			`/index.php/apps/pipelinq/api/leaves/contact-moments/${host.id}`,
			{ title: `E2E zonder richting ${STAMP}`, channel: 'telefoon' },
		)

		expect(refused.status).toBe(400)
		expect(
			JSON.stringify(refused.body),
			'the refusal has to name the field that was missing, or a handler cannot fix it',
		).toContain('direction')
	})

	test('an append against a host the caller cannot read is refused', async ({
		page,
	}) => {
		await openApp(page)

		// The least privileged principal that should be refused: a signed-in
		// handler naming a host that does not resolve for them. A leaf that
		// answered here would file contact moments against objects its caller
		// may not see.
		const refused = await api(
			page,
			'POST',
			'/index.php/apps/pipelinq/api/leaves/contact-moments/00000000-0000-0000-0000-000000000000',
			{ title: `E2E onbereikbaar ${STAMP}`, channel: 'telefoon', direction: 'inbound' },
		)

		expect(refused.status).toBe(403)
	})
})
