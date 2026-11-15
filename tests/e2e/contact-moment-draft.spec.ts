/*
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 * SPDX-License-Identifier: EUPL-1.2
 *
 * A half typed contact moment survives a closed page (contact-moments-keep-draft).
 *
 * Seeds a client, types a subject and notes in the quick log on its page,
 * waits past the two second quiet time, closes the page, opens the client in
 * a new page, restores the draft and saves the contact moment.
 *
 * The draft is the contact moment itself, a ticket in OpenRegister's status
 * `draft` (decisions 180 and 216). So the test also checks that the draft
 * stays out of the client's ordinary ticket list while it is one, and that
 * the saved contact moment is the same object, now active. The seeded client
 * and its contact moments are deleted again afterwards.
 *
 * @e2e contact-moment-drafts::the-tab-closes-halfway-through
 * @e2e contact-moment-drafts::saving-removes-the-draft
 * @e2e contact-moment-drafts::a-draft-is-not-in-the-ticket-list
 */
import type { Page } from '@playwright/test'

import { expect, test } from '@playwright/test'
import { gotoAppRoute, openApp } from './helpers/pipelinq.ts'

const STAMP = Date.now()
const OBJECTS = '/index.php/apps/openregister/api/objects/pipelinq'

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

type Ticket = { id: string; '@self'?: { status?: string } }

/**
 * The contact moments the server lists for one client.
 *
 * @param page     The page to run in.
 * @param clientId The client.
 * @param drafts   The draft lens: 'true' for drafts alone, '' for the ordinary list.
 *
 * @return The tickets.
 */
async function contactMomentsFor(
	page: Page,
	clientId: string,
	drafts: '' | 'true',
): Promise<Ticket[]> {
	const lens = drafts === '' ? '' : `&_drafts=${drafts}`
	const res = await api(
		page,
		'GET',
		`${OBJECTS}/ticket?client=${clientId}&ticketType=interaction&_limit=20${lens}`,
	)
	expect(res.status).toBeLessThan(400)
	return (res.body.results as Ticket[]) ?? []
}

/** The client this test seeded, removed again with its contact moments. */
let seededClient: string | null = null

test.describe('contact moment draft', () => {
	test.setTimeout(120000)

	test.afterEach(async ({ context }) => {
		if (seededClient === null) {
			return
		}
		const page = await context.newPage()
		await page.goto('/index.php/settings/user', { timeout: 120000 })
		const tickets = [
			...(await contactMomentsFor(page, seededClient, '')),
			...(await contactMomentsFor(page, seededClient, 'true')),
		]
		for (const ticket of tickets) {
			await api(page, 'DELETE', `${OBJECTS}/ticket/${ticket.id}`)
		}
		await api(page, 'DELETE', `${OBJECTS}/client/${seededClient}`)
		seededClient = null
	})

	test('a draft survives a closed page and is gone after saving', async ({
		page,
		context,
	}) => {
		await openApp(page)
		const created = await api(page, 'POST', `${OBJECTS}/client`, {
			name: `Jansen ${STAMP}`,
			type: 'person',
			contactsUid: `e2e-draft-${STAMP}`,
		})
		expect(created.status).toBeLessThan(400)
		const clientId = (created.body.id
			?? (created.body['@self'] as Record<string, string>)?.id) as string
		seededClient = clientId

		await gotoAppRoute(page, `/clients/${clientId}`)
		const quickLog = page.getByTestId('contactmoment-quicklog')
		await expect(quickLog).toBeVisible({ timeout: 30000 })
		await quickLog
			.getByLabel(/^(Subject|Onderwerp)$/)
			.fill(`Adreswijziging ${STAMP}`)
		await quickLog.getByLabel(/^(Notes|Notities)$/).fill('Belt morgen terug')
		await expect
			.poll(
				async () => (await contactMomentsFor(page, clientId, 'true')).length,
				{
					timeout: 15000,
				},
			)
			.toBe(1)
		const [draft] = await contactMomentsFor(page, clientId, 'true')
		// The agent's own draft is not in the ordinary ticket list.
		expect(await contactMomentsFor(page, clientId, '')).toHaveLength(0)
		await page.close()

		const second = await context.newPage()
		await gotoAppRoute(second, `/clients/${clientId}`)
		await expect(second.getByTestId('contactmoment-draft-offer')).toBeVisible({
			timeout: 30000,
		})
		await second.getByTestId('contactmoment-draft-restore').click()
		const restored = second.getByTestId('contactmoment-quicklog')
		await expect(restored.getByLabel(/^(Subject|Onderwerp)$/)).toHaveValue(
			`Adreswijziging ${STAMP}`,
		)

		await restored.getByRole('combobox', { name: /^(Channel|Kanaal)$/ }).click()
		await second.getByRole('option', { name: 'telefoon' }).click()
		await restored.getByRole('button', { name: /^(Save|Opslaan)$/ }).click()
		await expect
			.poll(
				async () =>
					(await contactMomentsFor(second, clientId, 'true')).length,
				{
					timeout: 15000,
				},
			)
			.toBe(0)
		// The draft became the contact moment: same id, now in the ordinary list.
		const saved = await contactMomentsFor(second, clientId, '')
		expect(saved.map((ticket) => ticket.id)).toEqual([draft.id])
		expect(saved[0]['@self']?.status).toBe('active')
	})
})
