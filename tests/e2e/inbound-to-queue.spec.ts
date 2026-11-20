/*
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 * SPDX-License-Identifier: EUPL-1.2
 *
 * An inbound WhatsApp message lands in the Queue (requests-inbound-to-queue
 * task 2.2). Seeds a WhatsApp provider with a known webhook secret and a
 * contact through the OpenRegister object API, posts a signed delivery to the
 * public webhook the way the provider would, and reads the new ticket on the
 * Queue with channel whatsapp and nobody assigned. A STOP message on another
 * number opens no ticket.
 *
 * @e2e requests-inbound-to-queue/inbound-ticket-intake::a-whatsapp-question-lands-in-the-queue
 * @e2e requests-inbound-to-queue/inbound-ticket-intake::a-stop-message-opens-no-ticket
 */
import type { Page } from '@playwright/test'

import { expect, test } from '@playwright/test'
import { createHmac } from 'crypto'
import { gotoAppRoute, openApp } from './helpers/pipelinq.ts'

const STAMP = Date.now()
const OBJECTS = '/index.php/apps/openregister/api/objects/pipelinq'
const SECRET = `e2e-inbound-${STAMP}`

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
						document.querySelector('head[data-requesttoken]')?.getAttribute('data-requesttoken') ?? '',
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
async function seed(page: Page, schema: string, data: Record<string, unknown>): Promise<string> {
	const res = await api(page, 'POST', `${OBJECTS}/${schema}`, data)
	expect(res.status, `could not seed ${schema}: ${JSON.stringify(res.body).slice(0, 300)}`).toBeLessThan(400)
	const created = res.body as Record<string, never>
	return (created?.id ?? created?.['@self']?.id) as string
}

/**
 * Post one delivery to the public WhatsApp webhook, signed with the provider's secret.
 *
 * @param page       The page, for its request context (no session is needed).
 * @param providerId The provider the webhook is for.
 * @param from       The sender's number, without the plus.
 * @param text       The message.
 *
 * @return The webhook's status.
 */
async function deliver(page: Page, providerId: string, from: string, text: string): Promise<number> {
	const raw = JSON.stringify({
		entry: [{ changes: [{ value: { messages: [{ from, id: `wamid.${STAMP}.${from}`, type: 'text', text: { body: text } }] } }] }],
	})
	const signature = 'sha256=' + createHmac('sha256', SECRET).update(raw).digest('hex')
	const res = await page.request.post(`/index.php/apps/pipelinq/api/messaging-webhooks/whatsapp/${providerId}`, {
		data: raw,
		headers: { 'Content-Type': 'application/json', 'X-Hub-Signature-256': signature },
	})
	return res.status()
}

/**
 * The open request tickets on a thread key, read through the object API.
 *
 * @param page      The page to run in.
 * @param threadKey The thread key.
 *
 * @return The tickets.
 */
async function ticketsOn(page: Page, threadKey: string): Promise<Array<Record<string, unknown>>> {
	const res = await api(page, 'GET', `${OBJECTS}/ticket?inboundThreadKey=${encodeURIComponent(threadKey)}&_limit=10`)
	return (res.body?.results as Array<Record<string, unknown>>) ?? []
}

test.describe('inbound WhatsApp to the Queue', () => {
	test.setTimeout(120000)

	test('a WhatsApp question lands in the Queue, a STOP opens no ticket', async ({ page }) => {
		await openApp(page)
		const providerId = await seed(page, 'channelProvider', {
			kind: 'whatsapp-cloud-api',
			vendor: 'meta',
			displayName: `E2E WhatsApp ${STAMP}`,
			phoneNumber: '+31200000000',
			webhookSecret: SECRET,
			active: true,
			sandbox: true,
		})
		const asker = `3161${String(STAMP).slice(-7)}`
		const stopper = `3162${String(STAMP).slice(-7)}`
		const contactId = await seed(page, 'contact', { name: `Fatima Bakker ${STAMP}`, phone: `+${asker}` })
		await seed(page, 'contact', { name: `Piet Stop ${STAMP}`, phone: `+${stopper}` })

		const question = `Is my permit ready? ${STAMP}`
		expect(await deliver(page, providerId, asker, question)).toBeLessThan(400)
		expect(await deliver(page, providerId, stopper, 'STOP')).toBeLessThan(400)

		const conversations = await api(page, 'GET', `${OBJECTS}/channelConversation?contactId=${contactId}&_limit=5`)
		const conversation = ((conversations.body?.results as Array<Record<string, unknown>>) ?? [])[0]
		expect(conversation, 'the delivery opened no conversation').toBeTruthy()
		const tickets = await ticketsOn(page, `conversation:${conversation.id as string}`)
		expect(tickets).toHaveLength(1)
		expect(tickets[0].channel).toBe('whatsapp')
		expect(tickets[0].status).toBe('new')
		expect(tickets[0].assignee ?? null).toBeNull()

		await gotoAppRoute(page, '/queue')
		await expect(page.getByText(question.slice(0, 40)).first()).toBeVisible({ timeout: 20000 })

		const stopped = await api(page, 'GET', `${OBJECTS}/ticket?_search=${encodeURIComponent(`Piet Stop ${STAMP}`)}&_limit=5`)
		expect((stopped.body?.results as unknown[]) ?? []).toHaveLength(0)
	})
})
