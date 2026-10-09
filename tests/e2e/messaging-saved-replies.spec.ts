/*
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Saved replies and Send again (messaging-saved-replies-and-resend).
 *
 * Seeds through the OpenRegister object API from inside the page, then drives
 * the screens: the Messages section on a contact, the Saved replies page, the
 * Send message dialog, the answer to the customer on a ticket, Write email,
 * and Send again on a failed SMS.
 *
 * The Send again happy path needs an active SMS provider; CI seeds the mock
 * provider (tests/e2e/ci-seed.sh). Without one the server answers no-provider
 * and the test reads that reason on the row instead.
 *
 * @e2e messaging-saved-replies::an-agent-sees-a-message-that-was-sent-yesterday
 * @e2e messaging-saved-replies::a-team-lead-adds-a-saved-reply
 * @e2e messaging-saved-replies::an-agent-answers-an-sms-with-a-saved-reply
 * @e2e messaging-saved-replies::a-reply-for-email-only-is-not-offered-on-whatsapp
 * @e2e messaging-saved-replies::an-agent-answers-a-portal-request-and-waits-for-the-customer
 * @e2e messaging-saved-replies::an-agent-writes-an-email-in-mail-from-a-saved-reply
 * @e2e messaging-saved-replies::an-agent-sends-a-failed-sms-again
 * @e2e messaging-saved-replies::a-closed-whatsapp-window-asks-for-a-template
 * @e2e messaging-saved-replies::a-message-cannot-be-sent-again-twice
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

test.describe('saved replies and send again', () => {
	test.setTimeout(180000)

	test('a contact lists its messages and a failed SMS is sent again once', async ({ page }) => {
		await openApp(page)
		const contactId = await seed(page, 'contact', {
			name: `Jan de Vries ${STAMP}`,
			phone: '+31611111111',
		})
		const yesterday = new Date(Date.now() - 86400000).toISOString()
		await seed(page, 'channelMessage', {
			contactId,
			channel: 'sms',
			direction: 'outbound',
			body: `Uw afspraak staat ${STAMP}`,
			deliveryStatus: 'delivered',
			sentAt: yesterday,
		})
		const failedId = await seed(page, 'channelMessage', {
			contactId,
			channel: 'sms',
			direction: 'outbound',
			body: `We are open until five ${STAMP}`,
			deliveryStatus: 'failed',
			sentAt: yesterday,
		})

		await gotoAppRoute(page, `/contacts/${contactId}`)
		await expect(page.getByText(`Uw afspraak staat ${STAMP}`)).toBeVisible()
		await expect(page.getByText('No messages yet.')).toHaveCount(0)

		await page.getByTestId('messaging-resend').first().click()
		const outcome = page.getByText(`We are open until five ${STAMP}`)
		await expect(outcome.first()).toBeVisible()

		const failed = await api(page, 'GET', `${OBJECTS}/channelMessage/${failedId}`)
		expect(failed.body.deliveryStatus).toBe('failed')
		const resentAs = ((failed.body.metadata ?? {}) as Record<string, string>).resentAs ?? ''
		if (resentAs !== '') {
			const again = await api(page, 'POST', `/index.php/apps/pipelinq/api/messaging/messages/${failedId}/resend`)
			expect(again.status).toBe(409)
		} else {
			await expect(page.locator('[role="alert"]').first()).toBeVisible()
		}
	})

	test('a closed WhatsApp window turns Send again into the composer', async ({ page }) => {
		await openApp(page)
		const contactId = await seed(page, 'contact', { name: `Wa ${STAMP}`, phone: '+31622222222' })
		await seed(page, 'channelMessage', {
			contactId,
			channel: 'whatsapp',
			direction: 'outbound',
			body: `Free text ${STAMP}`,
			deliveryStatus: 'expired',
			sentAt: new Date(Date.now() - 3 * 86400000).toISOString(),
		})

		await gotoAppRoute(page, `/contacts/${contactId}`)
		await page.getByTestId('messaging-resend').first().click()
		await expect(page.getByRole('dialog', { name: 'Send message' })).toBeVisible()
	})

	test('a team lead adds a saved reply and an agent uses it on SMS, not on WhatsApp', async ({ page }) => {
		await openApp(page)
		const title = `Opening hours ${STAMP}`
		await seed(page, 'savedReply', {
			title,
			body: 'Dear {{contact.name}}, we are open until five.',
			channels: ['sms', 'email'],
			active: true,
		})
		await gotoAppRoute(page, '/saved-replies')
		await expect(page.getByText(title)).toBeVisible()

		const contactId = await seed(page, 'contact', { name: `Jan ${STAMP}`, phone: '+31633333333' })
		await gotoAppRoute(page, `/contacts/${contactId}`)
		await page.getByRole('button', { name: 'Send message' }).click()
		const dialog = page.getByRole('dialog', { name: 'Send message' })
		await dialog.getByLabel('Channel').click()
		await page.getByRole('option', { name: 'SMS' }).click()
		await dialog.getByLabel('Saved reply').click()
		await page.getByRole('option', { name: title }).click()
		await expect(dialog.getByLabel('Message')).toHaveValue(`Dear Jan ${STAMP}, we are open until five.`)
	})

	test('an agent answers a portal request with a saved reply and waits for the customer', async ({ page }) => {
		await openApp(page)
		const title = `Thanks ${STAMP}`
		await seed(page, 'savedReply', { title, body: 'About {{ticket.title}}: we will call you.', channels: ['portal'] })
		const ticketId = await seed(page, 'ticket', {
			ticketType: 'request',
			title: `Straatfeest ${STAMP}`,
			channel: 'portal',
			status: 'in_progress',
			portalReplies: [{ message: 'Is er nieuws?', createdAt: new Date().toISOString() }],
		})

		await gotoAppRoute(page, `/tickets/${ticketId}`)
		await page.getByLabel('Saved reply').click()
		await page.getByRole('option', { name: title }).click()
		await page.getByLabel('Message to the customer').fill(`About Straatfeest ${STAMP}: we will call you tomorrow.`)
		await page.getByTestId('customer-reply-send').click()

		await expect.poll(async () => {
			const res = await api(page, 'GET', `${OBJECTS}/ticket/${ticketId}`)
			return [res.body.customerMessage, res.body.status]
		}).toEqual([`About Straatfeest ${STAMP}: we will call you tomorrow.`, 'awaiting_customer'])
	})

	test('an agent writes an email in Mail from a saved reply', async ({ page, context }) => {
		await openApp(page)
		const title = `Mail reply ${STAMP}`
		await seed(page, 'savedReply', { title, body: 'Dear {{contact.name}}', channels: ['email'] })
		const contactId = await seed(page, 'contact', {
			name: `Mail ${STAMP}`,
			emails: [{ kind: 'work', value: 'jan@example.nl', primary: true }],
		})

		await gotoAppRoute(page, `/contacts/${contactId}`)
		await page.getByTestId('write-email-button').first().click()
		await page.getByLabel('Subject').fill('Uw vraag')
		await page.getByLabel('Saved reply').click()
		await page.getByRole('option', { name: title }).click()
		const opened = context.waitForEvent('page')
		await page.getByTestId('write-email-open').click()
		const tab = await opened
		expect(decodeURIComponent(tab.url())).toContain('mailto:jan@example.nl?subject=Uw vraag')
	})
})
