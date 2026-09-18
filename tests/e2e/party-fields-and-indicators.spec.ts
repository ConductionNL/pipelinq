/*
 * SPDX-FileCopyrightText: 2026 Pipelinq Contributors
 * SPDX-License-Identifier: EUPL-1.2
 *
 * A party's typed fields and its standing indicators, over the leaf.
 *
 * WHY THIS FILE EXISTS. Writing to somebody who died last month, or publishing
 * an address that is under geheimhouding, is the failure the indicator
 * prevents. The flag is held on the PARTY and resolved live, so a flag set
 * today reaches a case opened last year and a flag lifted today leaves every
 * surface at once.
 *
 * ⚠️ THE FAILURE THIS GUARDS IS AN ANSWER THAT IS ALWAYS "NOT BLOCKED". An app
 * that asks and is told no is indistinguishable from an app that never asked,
 * so both a BLOCKED and a NOT BLOCKED answer are asserted here, against the
 * same party, for two different acts.
 *
 * Covers, from openspec/changes/typed-fields-and-indicators-on-a-party:
 *   REQ-PFI-004 "A send to a deceased party is refused with a reason"
 *   REQ-PFI-004 "Publication is answered separately from sending"
 *   REQ-PFI-007 "A case page shows the party's flags"
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

test.describe('party fields and indicators', () => {
	// Several register writes plus a cold shell boot.
	test.setTimeout(180000)

	test('a deceased party blocks a send and not a publication question', async ({
		page,
	}) => {
		await openApp(page)

		const party = await seed(page, 'client', {
			name: `E2E overleden ${STAMP}`,
			type: 'person',
			contactsUid: `e2e-overleden-${STAMP}`,
		})

		await seed(page, 'partyIndicator', {
			code: `overleden-${STAMP}`,
			label: 'Overleden',
			severity: 'critical',
			blocksOutbound: true,
		})

		await seed(page, 'partyIndicatorValue', {
			party,
			indicator: `overleden-${STAMP}`,
			validFrom: '2026-01-01',
			source: 'e2e',
		})

		const send = await api(
			page,
			'GET',
			`/index.php/apps/pipelinq/api/parties/${party}/blocked/send`,
		)
		expect(send.status).toBe(200)
		expect(
			send.body.blocked,
			'a send to a party carrying a blocksOutbound indicator has to be blocked',
		).toBe(true)
		expect(
			JSON.stringify(send.body.indicators),
			'the answer has to name the indicator, or a handler cannot be told why',
		).toContain('Overleden')

		// The same party, a different act. An answer that is always "blocked"
		// is as useless as one that is never blocked.
		const publish = await api(
			page,
			'GET',
			`/index.php/apps/pipelinq/api/parties/${party}/blocked/publishAddress`,
		)
		expect(publish.status).toBe(200)
		expect(publish.body.blocked).toBe(false)
	})

	test('a lifted indicator leaves the panel on its own date', async ({
		page,
	}) => {
		await openApp(page)

		const party = await seed(page, 'client', {
			name: `E2E opgeheven ${STAMP}`,
			type: 'person',
			contactsUid: `e2e-opgeheven-${STAMP}`,
		})

		await seed(page, 'partyIndicator', {
			code: `geheimhouding-${STAMP}`,
			label: 'Geheimhouding adres',
			severity: 'warning',
			blocksAddressPublication: true,
		})

		// A period that ended yesterday. Nobody edits anything afterwards:
		// the value stops applying on its own date.
		await seed(page, 'partyIndicatorValue', {
			party,
			indicator: `geheimhouding-${STAMP}`,
			validFrom: '2026-01-01',
			validUntil: '2026-01-02',
			source: 'e2e',
		})

		const panel = await api(
			page,
			'GET',
			`/index.php/apps/pipelinq/api/leaves/party/${party}`,
		)
		expect(panel.status).toBe(200)
		expect(
			(panel.body.indicators ?? []) as unknown[],
			'a value whose period has passed must not resolve',
		).toHaveLength(0)

		const publish = await api(
			page,
			'GET',
			`/index.php/apps/pipelinq/api/parties/${party}/blocked/publishAddress`,
		)
		expect(publish.body.blocked).toBe(false)
	})

	test('a cycle in the organisation tree is refused', async ({ page }) => {
		await openApp(page)

		const parent = await seed(page, 'client', {
			name: `E2E moeder ${STAMP}`,
			type: 'organization',
			contactsUid: `e2e-moeder-${STAMP}`,
		})
		const child = await seed(page, 'client', {
			name: `E2E dochter ${STAMP}`,
			type: 'organization',
			contactsUid: `e2e-dochter-${STAMP}`,
		})

		const nested = await api(
			page,
			'PUT',
			`/index.php/apps/pipelinq/api/parties/${child}/parent`,
			{ parentId: parent },
		)
		expect(nested.status).toBe(200)

		const cycle = await api(
			page,
			'PUT',
			`/index.php/apps/pipelinq/api/parties/${parent}/parent`,
			{ parentId: child },
		)
		expect(cycle.status).toBe(409)
		expect(JSON.stringify(cycle.body)).toContain('cycle')
	})
})
