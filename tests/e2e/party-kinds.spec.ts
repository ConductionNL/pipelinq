/*
 * SPDX-FileCopyrightText: 2026 Pipelinq Contributors
 * SPDX-License-Identifier: EUPL-1.2
 *
 * The party kind registry: one vocabulary, and a declaration per record type.
 *
 * WHY THIS FILE EXISTS. A subsidy case accepts an aanvrager and a gemachtigde,
 * a Woo request accepts a verzoeker, and today every picker in the fleet offers
 * whatever list its own app happened to ship. The vocabulary lives in pipelinq
 * and a consuming app declares which of it its record type accepts.
 *
 * ⚠️ THE FAILURE THIS GUARDS IS A RULE THE API GOES AROUND. A picker that
 * offers two kinds while the API accepts eleven is no rule at all, so the
 * refusal is asserted against the API rather than against the picker.
 *
 * Covers, from openspec/changes/party-kinds-accepted-per-case-type:
 *   REQ-PKR-003 "A subsidy case offers two kinds and not eleven"
 *   REQ-PKR-004 "An API caller cannot go around the picker"
 *   REQ-PKR-005 "One aanvrager"
 */
import type { Page } from '@playwright/test'

import { expect, test } from '@playwright/test'
import { openApp } from './helpers/pipelinq.ts'

/** Distinctive enough that finding it proves the read, not a coincidence. */
const STAMP = Date.now()

/** The record type this run declares an acceptance for. */
const RECORD_TYPE = `dossiq:zaak:e2e-subsidie-${STAMP}`

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

test.describe('party kinds accepted per record type', () => {
	// Several register writes plus a cold shell boot.
	test.setTimeout(180000)

	test('a declared record type offers its two kinds and refuses a third', async ({
		page,
	}) => {
		await openApp(page)

		await seed(page, 'partyKindAcceptance', {
			recordType: RECORD_TYPE,
			kinds: ['aanvrager', 'gemachtigde'],
		})

		const offered = await api(
			page,
			'GET',
			`/index.php/apps/pipelinq/api/party-kinds?recordType=${encodeURIComponent(RECORD_TYPE)}`,
		)
		expect(offered.status).toBe(200)
		expect(offered.body.declared).toBe(true)

		const codes = ((offered.body.kinds ?? []) as Array<Record<string, string>>).map(
			(k) => k.code,
		)
		// The ORDER, not merely the membership: the first kind is what most
		// handlers will take, and that is part of the declaration.
		expect(codes).toEqual(['aanvrager', 'gemachtigde'])

		const party = await seed(page, 'client', {
			name: `E2E partij ${STAMP}`,
			type: 'person',
			contactsUid: `e2e-partij-${STAMP}`,
		})

		// The API, not the picker: a rule the API goes around is no rule.
		const refused = await api(page, 'POST', '/index.php/apps/pipelinq/api/party-links', {
			recordType: RECORD_TYPE,
			recordId: `zaak-${STAMP}`,
			party,
			kind: 'vergunninghouder',
		})

		expect(refused.status).toBe(409)
		expect(JSON.stringify(refused.body)).toContain('vergunninghouder')
	})

	test('one aanvrager, and the refusal names who holds it', async ({ page }) => {
		await openApp(page)

		const recordId = `zaak-single-${STAMP}`
		const first = await seed(page, 'client', {
			name: `E2E eerste aanvrager ${STAMP}`,
			type: 'person',
			contactsUid: `e2e-eerste-${STAMP}`,
		})
		const second = await seed(page, 'client', {
			name: `E2E tweede aanvrager ${STAMP}`,
			type: 'person',
			contactsUid: `e2e-tweede-${STAMP}`,
		})

		const linked = await api(page, 'POST', '/index.php/apps/pipelinq/api/party-links', {
			recordType: RECORD_TYPE,
			recordId,
			party: first,
			kind: 'aanvrager',
		})
		expect(
			linked.status,
			`the first aanvrager was refused — ${JSON.stringify(linked.body).slice(0, 300)}`,
		).toBe(201)

		const refused = await api(page, 'POST', '/index.php/apps/pipelinq/api/party-links', {
			recordType: RECORD_TYPE,
			recordId,
			party: second,
			kind: 'aanvrager',
		})

		expect(refused.status).toBe(409)
		expect(
			JSON.stringify(refused.body),
			'the refusal has to name the party already holding the kind',
		).toContain(first)
	})

	test('an undeclared record type still works', async ({ page }) => {
		await openApp(page)

		const offered = await api(
			page,
			'GET',
			`/index.php/apps/pipelinq/api/party-kinds?recordType=${encodeURIComponent(`dossiq:zaak:niets-gedeclareerd-${STAMP}`)}`,
		)

		expect(offered.status).toBe(200)
		expect(offered.body.declared).toBe(false)
		expect(
			((offered.body.kinds ?? []) as unknown[]).length,
			'an app that has declared nothing has to keep working, with every active kind offered',
		).toBeGreaterThan(0)
	})
})
