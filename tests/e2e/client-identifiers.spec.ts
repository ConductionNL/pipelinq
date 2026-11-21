/*
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 * SPDX-License-Identifier: EUPL-1.2
 *
 * The Identifiers section on ClientDetail (clients-registry-numbers-and-enrichment
 * task 3.1, REQ-CRN-001 and REQ-CRN-002).
 *
 * An account manager adds a KvK number to an organisation, which lists it as
 * unchecked, and presses Check, which shows it as valid. The KvK register is
 * reached by the server through OpenRegister's KvK leaf, which a browser test
 * cannot reach, so the check route's answer is mocked in the page: what is
 * tested is the section's round trip, not the register. A malformed RSIN is
 * refused by the server's format guard and the client keeps its numbers.
 *
 * Written from the change's scenarios; not yet run (it needs a seeded instance).
 *
 * @spec openspec/specs/client-registry-numbers/spec.md
 */
import type { Page } from '@playwright/test'

import { expect, test } from '@playwright/test'
import { gotoAppRoute } from './helpers/pipelinq.ts'

const STAMP = `E2E-CRN-${Date.now()}`
const OBJECTS = '/index.php/apps/openregister/api/objects/pipelinq/client'

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
async function call(
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
					requesttoken: document.querySelector('head[data-requesttoken]')?.getAttribute('data-requesttoken') ?? '',
				},
				body: body === undefined ? undefined : JSON.stringify(body),
			})
			const text = await res.text()
			try {
				return { status: res.status, body: JSON.parse(text) }
			} catch {
				return { status: res.status, body: { raw: text.slice(0, 300) } }
			}
		},
		{ method, url, body },
	)
}

test.describe('the Identifiers section on a client', () => {
	test.describe.configure({ mode: 'serial' })
	test.setTimeout(180_000)

	let clientId = ''

	test.beforeAll(async ({ browser }) => {
		const page = await browser.newPage()
		await gotoAppRoute(page, '/clients')
		const res = await call(page, 'POST', OBJECTS, { name: `${STAMP} Bakkerij de Korenschoof`, type: 'organization' })
		expect(res.status, 'creating the client').toBeLessThan(300)
		clientId = String(res.body.id ?? (res.body['@self'] as Record<string, unknown> | undefined)?.id)
		await page.close()
	})

	test.afterAll(async ({ browser }) => {
		if (clientId === '') return
		const page = await browser.newPage()
		await gotoAppRoute(page, '/clients')
		await call(page, 'DELETE', `${OBJECTS}/${clientId}`)
		await page.close()
	})

	test('an account manager adds a KvK number and Check shows it as valid', async ({ page }) => {
		await gotoAppRoute(page, `/clients/${clientId}`)
		const section = page.getByTestId('client-identifiers')
		await expect(section).toBeVisible({ timeout: 30_000 })

		const add = section.getByTestId('client-identifier-add')
		await add.getByLabel(/Number|Nummer/).fill('1234 5678')
		await add.getByRole('button', { name: /^(Add|Toevoegen)$/ }).click()

		const row = section.getByTestId('client-identifier-row').filter({ hasText: '12345678' })
		await expect(row).toBeVisible({ timeout: 20_000 })
		await expect(row.getByTestId('client-identifier-status')).toHaveText(/Not checked|Niet gecontroleerd/)

		await page.route('**/apps/pipelinq/api/clients/*/identifiers/check', async (route) => {
			await route.fulfill({
				status: 200,
				contentType: 'application/json',
				body: JSON.stringify({
					identifier: {
						scheme: 'kvk',
						value: '12345678',
						checkStatus: 'valid',
						checkedAt: new Date().toISOString(),
						checkSource: 'KvK',
					},
				}),
			})
		})
		await row.getByTestId('client-identifier-check').click()
		await expect(row.getByTestId('client-identifier-status')).toHaveText(/Valid|Geldig/, { timeout: 20_000 })
	})

	test('a malformed RSIN is refused and the client keeps its numbers', async ({ page }) => {
		await gotoAppRoute(page, '/clients')
		const before = await call(page, 'GET', `${OBJECTS}/${clientId}`)
		const kept = before.body.identifiers

		const refused = await call(page, 'PUT', `${OBJECTS}/${clientId}`, {
			...before.body,
			identifiers: [...((kept as unknown[]) ?? []), { scheme: 'rsin', value: '123456789' }],
		})
		expect(refused.status, 'the format guard refuses the save').toBeGreaterThanOrEqual(400)
		expect(JSON.stringify(refused.body)).toContain('eleven test')

		const after = await call(page, 'GET', `${OBJECTS}/${clientId}`)
		expect(after.body.identifiers).toEqual(kept)
	})
})
