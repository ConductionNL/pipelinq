/*
 * SPDX-FileCopyrightText: 2026 Pipelinq Contributors
 * SPDX-License-Identifier: EUPL-1.2
 *
 * The language a party asked to be written in, and the rule that answered.
 *
 * WHY THIS FILE EXISTS. A resolver that returns `nl` tells a caller nothing
 * about whether the party asked for Dutch or simply never said anything, and
 * those are different facts on a letter. Every answer names the rule that
 * produced it.
 *
 * ⚠️ THE FAILURE THIS GUARDS IS AN UNSET PREFERENCE DRESSED UP AS A CHOSEN
 * ONE. A party who stated nothing and a party who chose the instance default
 * resolve to the same tag, so the RULE is asserted here, not only the tag.
 *
 * Covers, from openspec/changes/correspondence-language-per-party:
 *   REQ-PCL-002 "An unrenderable tag is refused"
 *   REQ-PCL-003 "The party's own preference answers" / "The instance default
 *   answers, and says so"
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
 * Seed one party.
 *
 * @param page  The page to run in.
 * @param label A label distinctive to this run.
 *
 * @return The created id.
 */
async function seedParty(page: Page, label: string): Promise<string> {
	const res = await api(
		page,
		'POST',
		'/index.php/apps/openregister/api/objects/pipelinq/client',
		{
			name: `E2E ${label} ${STAMP}`,
			type: 'person',
			contactsUid: `e2e-${label}-${STAMP}`,
		},
	)
	expect(
		res.status,
		`could not seed a party — ${JSON.stringify(res.body).slice(0, 300)}`,
	).toBeLessThan(400)
	const created = res.body as Record<string, never>
	return (created?.id ?? created?.['@self']?.id) as string
}

test.describe('correspondence language per party', () => {
	// Several register writes plus a cold shell boot.
	test.setTimeout(180000)

	test('an unset preference resolves with the instance default named as the rule', async ({
		page,
	}) => {
		await openApp(page)

		const party = await seedParty(page, 'geen-voorkeur')

		const answer = await api(
			page,
			'GET',
			`/index.php/apps/pipelinq/api/parties/${party}/correspondence-language`,
		)

		expect(answer.status).toBe(200)
		expect(
			answer.body.partyPreference,
			'a party who stated nothing must come back with nothing stated',
		).toBe('')
		expect(
			['instanceDefault', 'fallback'],
			'the answer has to name which rule produced it',
		).toContain(answer.body.rule)
	})

	test('a stated preference answers, and names the party as the rule', async ({
		page,
	}) => {
		await openApp(page)

		const party = await seedParty(page, 'engels')

		const available = await api(
			page,
			'GET',
			'/index.php/apps/pipelinq/api/correspondence-languages',
		)
		expect(available.status).toBe(200)
		expect(
			(available.body.languages ?? []) as string[],
			'the picker must offer at least the source language',
		).toContain('en')

		const set = await api(
			page,
			'PUT',
			`/index.php/apps/pipelinq/api/parties/${party}/correspondence-language`,
			{ language: 'en' },
		)
		expect(
			set.status,
			`setting a renderable tag was refused — ${JSON.stringify(set.body).slice(0, 300)}`,
		).toBe(200)
		expect(set.body.language).toBe('en')
		expect(set.body.rule).toBe('party')
	})

	test('a tag this instance cannot render is refused, naming what exists', async ({
		page,
	}) => {
		await openApp(page)

		const party = await seedParty(page, 'onrenderbaar')

		const refused = await api(
			page,
			'PUT',
			`/index.php/apps/pipelinq/api/parties/${party}/correspondence-language`,
			{ language: 'zz-nonexistent' },
		)

		expect(refused.status).toBe(422)
		expect(
			JSON.stringify(refused.body),
			'the refusal has to name the tag and list what is available',
		).toContain('zz-nonexistent')

		// And the party still resolves, with nothing stated on it.
		const answer = await api(
			page,
			'GET',
			`/index.php/apps/pipelinq/api/parties/${party}/correspondence-language`,
		)
		expect(answer.body.partyPreference).toBe('')
	})
})
