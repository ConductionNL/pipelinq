/*
 * SPDX-FileCopyrightText: 2026 Pipelinq Contributors
 * SPDX-License-Identifier: EUPL-1.2
 *
 * The closed loop: a token that answers once, and a panel that says nothing
 * when there is nothing to say.
 *
 * WHY THIS FILE EXISTS. A satisfaction feature that collects answers and does
 * nothing with them is a form, not a loop. The parts that can fail silently are
 * the token (a second answer that quietly lands) and the empty state (a client
 * with no responses rendering as zero rather than as unmeasured).
 *
 * ⚠️ A SECOND SUBMISSION THAT SUCCEEDS LOOKS EXACTLY LIKE A FIRST ONE. So the
 * second call's status is asserted, not merely the first one's.
 *
 * Covers, from openspec/changes/customer-satisfaction-closed-loop:
 *   "Submit via invitation link" and "Single use enforced"
 *   "Empty state without responses"
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

test.describe('customer satisfaction, closed loop', () => {
	// Several register writes plus a cold shell boot.
	test.setTimeout(180000)

	test('a token answers once, and the second answer is refused', async ({
		page,
	}) => {
		await openApp(page)

		const client = await seed(page, 'client', {
			name: `E2E kto klant ${STAMP}`,
			type: 'organization',
			contactsUid: `e2e-kto-${STAMP}`,
		})

		const survey = await seed(page, 'survey', {
			title: `E2E KTO ${STAMP}`,
			questions: [
				{ key: 'recommend', label: 'Would you recommend us?', kind: 'nps' },
				{ key: 'anything', label: 'Anything else?', kind: 'text' },
			],
			active: true,
		})

		const token = `e2e-token-${STAMP}`
		await seed(page, 'surveyInvitation', {
			token,
			surveyRef: survey,
			clientRef: client,
			contactRef: `e2e-kto-${STAMP}`,
			channel: 'email',
			status: 'sent',
			expiresAt: '2099-01-01T00:00:00+00:00',
		})

		const opened = await api(
			page,
			'GET',
			`/index.php/apps/pipelinq/survey/i/${token}`,
		)
		expect(
			opened.status,
			`the token did not open the survey — ${JSON.stringify(opened.body).slice(0, 300)}`,
		).toBe(200)

		const answered = await api(
			page,
			'POST',
			`/index.php/apps/pipelinq/survey/i/${token}`,
			{ answers: { recommend: 3, anything: 'Het duurde te lang' } },
		)
		expect(answered.status).toBe(201)

		// The same token again. A second answer that quietly lands is
		// indistinguishable from a first one in every aggregate afterwards.
		const again = await api(
			page,
			'POST',
			`/index.php/apps/pipelinq/survey/i/${token}`,
			{ answers: { recommend: 10 } },
		)
		expect(again.status).toBe(410)
	})

	test('a client with no responses gets an empty state, not a zero', async ({
		page,
	}) => {
		await openApp(page)

		const client = await seed(page, 'client', {
			name: `E2E ongemeten klant ${STAMP}`,
			type: 'organization',
			contactsUid: `e2e-ongemeten-${STAMP}`,
		})

		const panel = await api(
			page,
			'GET',
			`/index.php/apps/pipelinq/api/satisfaction/client/${client}`,
		)

		expect(panel.status).toBe(200)
		expect(panel.body.empty).toBe(true)
		expect(
			panel.body.nps,
			'a client nobody scored has no NPS, and zero is a real score that means promoters and detractors cancelled out',
		).toBeNull()
	})

	test('the response rate names its suppressed count beside the percentage', async ({
		page,
	}) => {
		await openApp(page)

		const rate = await api(
			page,
			'GET',
			'/index.php/apps/pipelinq/api/satisfaction/response-rate',
		)

		expect(rate.status).toBe(200)
		// The shape, not the numbers: a throttle folded into the denominator
		// reads as disinterest, so the counts have to be reported separately.
		expect(Object.keys(rate.body)).toEqual(
			expect.arrayContaining(['delivered', 'responded', 'rate', 'suppressed', 'failed']),
		)
	})
})
