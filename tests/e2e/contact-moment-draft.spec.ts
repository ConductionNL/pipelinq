/*
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 * SPDX-License-Identifier: EUPL-1.2
 *
 * A half typed contact moment survives a closed page (contact-moments-keep-draft).
 *
 * Seeds a client, types a subject and notes in the quick log on its page,
 * waits past the two second quiet time, closes the page, opens the client in
 * a new page, restores the draft, saves the contact moment, and checks that
 * the server holds no draft for that client any more.
 *
 * @e2e contact-moment-drafts::the-tab-closes-halfway-through
 * @e2e contact-moment-drafts::saving-removes-the-draft
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
 * The drafts the server holds for one client.
 *
 * @param page     The page to run in.
 * @param clientId The client.
 *
 * @return The drafts.
 */
async function draftsFor(page: Page, clientId: string): Promise<unknown[]> {
	const res = await api(
		page,
		'GET',
		`${OBJECTS}/contactMomentDraft?client=${clientId}&_limit=20`,
	)
	expect(res.status).toBeLessThan(400)
	return (res.body.results as unknown[]) ?? []
}

test.describe('contact moment draft', () => {
	test.setTimeout(120000)

	test('a draft survives a closed page and is gone after saving', async ({
		page,
		context,
	}) => {
		await openApp(page)
		const created = await api(page, 'POST', `${OBJECTS}/client`, {
			name: `Jansen ${STAMP}`,
			type: 'person',
		})
		expect(created.status).toBeLessThan(400)
		const clientId = (created.body.id
			?? (created.body['@self'] as Record<string, string>)?.id) as string

		await gotoAppRoute(page, `/clients/${clientId}`)
		const quickLog = page.getByTestId('contactmoment-quicklog')
		await expect(quickLog).toBeVisible({ timeout: 30000 })
		await quickLog.getByLabel(/^(Subject|Onderwerp)$/).fill(`Adreswijziging ${STAMP}`)
		await quickLog.getByLabel(/^(Notes|Notities)$/).fill('Belt morgen terug')
		await expect
			.poll(async () => (await draftsFor(page, clientId)).length, { timeout: 15000 })
			.toBe(1)
		await page.close()

		const second = await context.newPage()
		await openApp(second)
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
			.poll(async () => (await draftsFor(second, clientId)).length, { timeout: 15000 })
			.toBe(0)
	})
})
