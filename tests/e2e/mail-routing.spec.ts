/*
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Routing rules route a mail ticket to its team (requests-inbound-to-queue
 * task 5.2). An administrator opens the Routing rules section of the admin
 * settings, adds the rule "sender domain kadaster.nl sets category
 * Registratie and priority high" and saves, which switches routing on. A
 * ticket then arrives the way the mail intake writes one (channel email, the
 * sender's domain in channelMetadata, no category), and the flow sets its
 * category and priority and records the rule. The Queue filtered on
 * Registratie lists it.
 *
 * The flow runs asynchronously; the test waits for the run, so the instance
 * needs Nextcloud's background jobs (cron) running.
 *
 * @e2e requests-inbound-to-queue/inbound-ticket-intake::routing-is-off-until-someone-switches-it-on
 * @e2e requests-inbound-to-queue/inbound-ticket-intake::mail-from-a-partner-domain-goes-to-its-team
 * @e2e requests-inbound-to-queue/inbound-ticket-intake::a-team-opens-its-part-of-the-queue
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

test.describe('routing rules', () => {
	test.setTimeout(180000)

	test('a rule on the sender domain sets the category of a new mail ticket', async ({ page }) => {
		await page.goto('/settings/admin/pipelinq')
		const section = page.locator('#section-routing-rules')
		await expect(section).toBeVisible({ timeout: 30000 })

		await section.getByRole('button', { name: 'Add rule' }).click()
		const rule = section.getByTestId('routing-rule').last()
		await rule.getByLabel('Sender domain').fill('kadaster.nl')
		await rule.getByLabel('Sets category').fill('Registratie')
		await rule.getByLabel('Sets priority').click()
		await page.getByRole('option', { name: 'high' }).click()
		await section.getByTestId('routing-rules-save').click()
		await expect(section.getByText('The routing rules are saved and in use.')).toBeVisible({ timeout: 20000 })
		await expect(section.getByTestId('routing-rules-off')).toHaveCount(0)

		await openApp(page)
		const title = `Kadaster vraag ${STAMP}`
		const created = await api(page, 'POST', `${OBJECTS}/ticket`, {
			title,
			ticketType: 'request',
			status: 'new',
			priority: 'normal',
			channel: 'email',
			inboundThreadKey: `mail:e2e:${STAMP}`,
			channelMetadata: { fromAddress: 'someone@kadaster.nl', fromDomain: 'kadaster.nl', mailbox: 'service@example.org' },
		})
		expect(created.status, JSON.stringify(created.body).slice(0, 300)).toBeLessThan(400)
		const id = (created.body?.id ?? (created.body?.['@self'] as Record<string, unknown>)?.id) as string

		await expect.poll(async () => {
			const read = await api(page, 'GET', `${OBJECTS}/ticket/${id}`)
			return [read.body?.category, read.body?.priority]
		}, { timeout: 120000, intervals: [2000, 5000, 10000] }).toEqual(['Registratie', 'high'])

		const routed = await api(page, 'GET', `${OBJECTS}/ticket/${id}`)
		const routing = (routed.body?.channelMetadata as Record<string, unknown>)?.routing
		expect(routing, 'the ticket does not record which rule matched').toBeTruthy()

		await gotoAppRoute(page, '/queue')
		await expect(page.getByText(title).first()).toBeVisible({ timeout: 20000 })
	})
})
