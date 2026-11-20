/*
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 * SPDX-License-Identifier: EUPL-1.2
 *
 * marketing-events: a campaign event with two seats takes three sign-ups,
 * seats two and puts the third on the waiting list, moves the waiting one up
 * when a seat is cancelled, and counts a marked attendance as an `attend`
 * touchpoint in the campaign report (tasks 4.1 and 4.2).
 *
 * The sign-ups go through pipelinq's registrations endpoint
 * (POST /api/campaign-events/{id}/registrations). That endpoint calls the same
 * EventRegistrationService::register() the landing page listener calls, so
 * the seat count, the waiting list and the same-address check are the ones a
 * form sign-up gets. A browser cannot dispatch portaliq's
 * LandingPageFormSubmittedEvent itself; the listener's own wiring is covered by
 * PHPUnit (LandingPageFormSubmittedListenerTest).
 *
 * The cancel step needs the signed link from the confirmation mail. It runs
 * when MAILPIT_URL names a Mailpit instance that catches this instance's mail,
 * and is skipped otherwise; the move-up itself is covered by PHPUnit
 * (EventRegistrationServiceTest).
 *
 * The target instance comes from PLAYWRIGHT_BASE_URL (see base-url.ts); there
 * is no localhost default. Every object is created with this run's stamp and
 * removed after.
 *
 * @e2e marketing-events-and-attendees::a-visitor-signs-up-and-gets-a-seat
 * @e2e marketing-events-and-attendees::a-sign-up-after-the-last-seat-goes-on-the-waiting-list
 * @e2e marketing-events-and-attendees::an-attendee-cancels-and-the-waiting-list-moves-up
 * @e2e marketing-events-and-attendees::opening-the-cancel-link-does-not-cancel
 * @e2e marketing-events-and-attendees::the-team-records-who-came
 */
import type { APIRequestContext, Page } from '@playwright/test'

import { expect, test } from '@playwright/test'
import { resolveBaseUrl } from './base-url.ts'
import { gotoAppRoute, openApp } from './helpers/pipelinq.ts'

const STAMP = Date.now()
const OBJECTS = '/index.php/apps/openregister/api/objects/pipelinq'
const API = '/index.php/apps/pipelinq/api'

/** The three people who sign up, in order. */
const PEOPLE = [
	{ name: `Anna Event ${STAMP}`, email: `anna.${STAMP}@example.org` },
	{ name: `Bram Event ${STAMP}`, email: `bram.${STAMP}@example.org` },
	{ name: `Cor Event ${STAMP}`, email: `cor.${STAMP}@example.org` },
]

/**
 * Call a route from inside the page, carrying the session and its token.
 *
 * @param page   The page to run in.
 * @param method The HTTP verb.
 * @param url    The url.
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
 * Create one object and return its id.
 *
 * @param page   The page to run in.
 * @param schema The schema slug.
 * @param data   The object.
 *
 * @return The created id.
 */
async function seed(
	page: Page,
	schema: string,
	data: Record<string, unknown>,
): Promise<string> {
	const res = await api(page, 'POST', `${OBJECTS}/${schema}`, data)
	expect(
		res.status,
		`could not seed ${schema}: ${JSON.stringify(res.body).slice(0, 300)}`,
	).toBeLessThan(400)
	const created = res.body as Record<string, never>
	return (created?.id ?? created?.['@self']?.id) as string
}

/**
 * The event's registrations, by email address.
 *
 * @param page    The page to run in.
 * @param eventId The event.
 *
 * @return Each registration's status, keyed by email.
 */
async function statuses(page: Page, eventId: string): Promise<Record<string, string>> {
	const res = await api(page, 'GET', `${OBJECTS}/eventRegistration?event=${eventId}&_limit=50`)
	expect(res.status).toBe(200)
	const rows = (res.body.results ?? []) as Array<Record<string, unknown>>
	const out: Record<string, string> = {}
	for (const row of rows) {
		out[String(row.email ?? '')] = String(row.status ?? '')
	}
	return out
}

/**
 * The cancel link of the last mail Mailpit caught for an address.
 *
 * @param request The API context.
 * @param mailpit Mailpit's base url.
 * @param email   The address.
 *
 * @return The link, or '' when no mail carries one.
 */
async function cancelLinkFor(request: APIRequestContext, mailpit: string, email: string): Promise<string> {
	const search = await request.get(`${mailpit}/api/v1/search?query=${encodeURIComponent(`to:${email}`)}`)
	if (search.ok() === false) {
		return ''
	}
	const messages = ((await search.json()).messages ?? []) as Array<{ ID: string }>
	for (const message of messages) {
		const full = await request.get(`${mailpit}/api/v1/message/${message.ID}`)
		const body = String((await full.json()).HTML ?? '') + String((await full.json()).Text ?? '')
		const link = body.match(/https?:\/\/[^\s"'<>]*\/apps\/pipelinq\/events\/cancel\?token=[^\s"'<>]+/)
		if (link !== null) {
			return link[0].replace(/&amp;/g, '&')
		}
	}
	return ''
}

test.describe('A campaign event with two seats', () => {
	test.describe.configure({ mode: 'serial' })
	test.setTimeout(180000)

	let campaignId = ''
	let eventId = ''

	test.afterAll(async ({ browser }) => {
		const page = await browser.newPage()
		await openApp(page)
		if (eventId !== '') {
			const res = await api(page, 'GET', `${OBJECTS}/eventRegistration?event=${eventId}&_limit=50`)
			for (const row of (res.body.results ?? []) as Array<Record<string, unknown>>) {
				const id = String(row.id ?? (row['@self'] as Record<string, unknown>)?.id ?? '')
				if (id !== '') {
					await api(page, 'DELETE', `${OBJECTS}/eventRegistration/${id}`)
				}
			}
			await api(page, 'DELETE', `${OBJECTS}/campaignEvent/${eventId}`)
		}
		if (campaignId !== '') {
			await api(page, 'DELETE', `${OBJECTS}/campaign/${campaignId}`)
		}
		await page.close()
	})

	test('three sign-ups give two seats and one waiting', async ({ page }) => {
		await openApp(page)
		campaignId = await seed(page, 'campaign', {
			name: `Webinar e2e ${STAMP}`,
			utmCampaign: `webinar-e2e-${STAMP}`,
			status: 'running',
		})
		eventId = await seed(page, 'campaignEvent', {
			title: `Webinar e2e ${STAMP}`,
			campaign: campaignId,
			startAt: new Date(Date.now() + 14 * 86400000).toISOString(),
			endAt: new Date(Date.now() + 14 * 86400000 + 3600000).toISOString(),
			capacity: 2,
			signupStatus: 'open',
		})

		const outcomes: string[] = []
		for (const person of PEOPLE) {
			const res = await api(page, 'POST', `${API}/campaign-events/${eventId}/registrations`, person)
			expect(res.status, JSON.stringify(res.body).slice(0, 300)).toBe(200)
			outcomes.push(String(res.body.outcome))
		}
		expect(outcomes).toEqual(['registered', 'registered', 'waitlisted'])

		// The same address a second time takes no second seat.
		const again = await api(page, 'POST', `${API}/campaign-events/${eventId}/registrations`, PEOPLE[0])
		expect(again.body.outcome).toBe('duplicate')

		expect(await statuses(page, eventId)).toEqual({
			[PEOPLE[0].email]: 'confirmed',
			[PEOPLE[1].email]: 'confirmed',
			[PEOPLE[2].email]: 'waitlist',
		})

		// The event page reaches it: the campaign card opens the event, whose
		// Registrations tab lists the three.
		await gotoAppRoute(page, `/campaign-events/${eventId}`)
		await expect(page.getByText(`Webinar e2e ${STAMP}`).first()).toBeVisible({ timeout: 30000 })
		await page.getByRole('tab', { name: /Registrations/ }).click()
		for (const person of PEOPLE) {
			await expect(page.getByText(person.name).first()).toBeVisible({ timeout: 30000 })
		}
	})

	test('a visitor without a session cannot add an attendee', async ({ browser }) => {
		const context = await browser.newContext({ storageState: { cookies: [], origins: [] } })
		const res = await context.request.post(`${resolveBaseUrl()}${API}/campaign-events/${eventId}/registrations`, {
			data: { name: 'Nobody', email: `nobody.${STAMP}@example.org` },
			headers: { 'OCS-APIRequest': 'true' },
		})
		expect(res.status()).toBeGreaterThanOrEqual(400)
		await context.close()
	})

	test('a cancelled seat goes to the first on the waiting list', async ({ page, browser }) => {
		const mailpit = (process.env.MAILPIT_URL ?? '').replace(/\/+$/, '')
		test.skip(mailpit === '', 'Needs MAILPIT_URL: the cancel link is only in the confirmation mail.')

		const anonymous = await browser.newContext({ storageState: { cookies: [], origins: [] } })
		const link = await cancelLinkFor(anonymous.request, mailpit, PEOPLE[0].email)
		expect(link, 'no cancel link in the confirmation mail').not.toBe('')

		// Opening the link changes nothing; only the button cancels.
		const visitor = await anonymous.newPage()
		await visitor.goto(link)
		await openApp(page)
		expect((await statuses(page, eventId))[PEOPLE[0].email]).toBe('confirmed')

		await visitor.getByRole('button', { name: /Cancel my registration|Afmelden/i }).click()
		await expect.poll(async () => await statuses(page, eventId), { timeout: 30000 }).toEqual({
			[PEOPLE[0].email]: 'cancelled',
			[PEOPLE[1].email]: 'confirmed',
			[PEOPLE[2].email]: 'confirmed',
		})
		await anonymous.close()
	})

	test('marking one attended counts one attend in the campaign report', async ({ page }) => {
		await openApp(page)
		const res = await api(page, 'GET', `${OBJECTS}/eventRegistration?event=${eventId}&email=${encodeURIComponent(PEOPLE[1].email)}`)
		const row = ((res.body.results ?? []) as Array<Record<string, unknown>>)[0]
		const id = String(row?.id ?? (row?.['@self'] as Record<string, unknown>)?.id ?? '')
		expect(id).not.toBe('')

		const marked = await api(page, 'POST', `${API}/event-registrations/${id}/attendance`, { attended: true })
		expect(marked.status, JSON.stringify(marked.body).slice(0, 300)).toBe(200)

		const report = await api(page, 'GET', `${API}/campaigns/${campaignId}/report`)
		expect(report.status).toBe(200)
		expect((report.body.engagement as Record<string, number>).attend).toBe(1)
	})
})
