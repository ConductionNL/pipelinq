/*
 * SPDX-FileCopyrightText: 2026 Pipelinq Contributors
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Gate-19 e2e coverage for openspec/specs/pipelinq-contracts/spec.md
 * (archived change contract-contact-ref).
 *
 * Each test seeds its own client, contact and contract through the
 * OpenRegister objects API, so it does not depend on the Zuiddrecht example
 * set being loaded; the Zuiddrecht contract CON-2026-0031 with Joke Smit is
 * the same shape (zuiddrecht.d/79-quote-0042-contact-history.json).
 *
 * @e2e pipelinq-contracts::joke-smits-contract
 * @e2e pipelinq-contracts::edit-a-contract-that-has-no-renewal-outcome
 */
import type { Page } from '@playwright/test'

import { expect, test } from '@playwright/test'
import { appUrl, openApp } from '../helpers/pipelinq.ts'

/** Distinctive enough that finding it proves the read, not a coincidence. */
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
 * Create one object and return its id, failing the test with the answer when it is refused.
 *
 * @param page   The page to run in.
 * @param schema The pipelinq schema slug.
 * @param data   The object.
 *
 * @return The created id.
 */
async function create(page: Page, schema: string, data: Record<string, unknown>): Promise<string> {
	const res = await api(page, 'POST', `${OBJECTS}/${schema}`, data)
	expect(res.status, `create ${schema}: ${JSON.stringify(res.body).slice(0, 300)}`).toBeLessThan(300)
	const id = (res.body.id ?? (res.body['@self'] as Record<string, unknown> | undefined)?.id) as string
	expect(id, `create ${schema} returned no id`).toBeTruthy()
	return id
}

/**
 * A client, a contact person of it, and a contract made with that person.
 *
 * @param page   The page to run in.
 * @param number The contract number.
 *
 * @return The contact and contract ids.
 */
async function seedContract(page: Page, number: string): Promise<{ contactId: string; contractId: string }> {
	const clientId = await create(page, 'client', {
		name: `E2E Sportcentrum ${STAMP}`,
		type: 'organization',
		contactsUid: `e2e-contract-client-${number}`,
	})
	const contactId = await create(page, 'contact', {
		name: `E2E Joke Smit ${STAMP}`,
		client: clientId,
		contactsUid: `e2e-contract-contact-${number}`,
	})
	const contractId = await create(page, 'salesContract', {
		contractNumber: number,
		title: `E2E Onderhoud sportvelden ${STAMP}`,
		clientRef: clientId,
		contactRef: contactId,
		billingInterval: 'annual',
		valuePerInterval: 42000,
		startDate: '2026-01-01',
	})
	return { contactId, contractId }
}

test('pipelinq-contracts: the contact page lists the contract made with that person', async ({ page }) => {
	await openApp(page)
	const number = `E2E-${STAMP}-1`
	const { contactId, contractId } = await seedContract(page, number)

	await page.goto(appUrl(`/contacts/${contactId}`))
	const link = page.locator('#content-vue').getByText(`Contract ${number}`).first()
	await expect(link).toBeVisible({ timeout: 20000 })
	await link.click()
	await expect(page).toHaveURL(new RegExp(contractId), { timeout: 15000 })
})

test('pipelinq-contracts: a contract without a renewal outcome can be changed', async ({ page }) => {
	await openApp(page)
	const { contractId } = await seedContract(page, `E2E-${STAMP}-2`)

	const stored = await api(page, 'GET', `${OBJECTS}/salesContract/${contractId}`)
	expect(stored.status).toBe(200)
	expect([null, undefined, '']).toContain(stored.body.renewalLeadOutcome)

	const changed = await api(page, 'PATCH', `${OBJECTS}/salesContract/${contractId}`, {
		title: `E2E Onderhoud sportvelden ${STAMP} (gewijzigd)`,
	})
	expect(changed.status, JSON.stringify(changed.body).slice(0, 300)).toBeLessThan(300)

	const reread = await api(page, 'GET', `${OBJECTS}/salesContract/${contractId}`)
	expect(reread.body.title).toBe(`E2E Onderhoud sportvelden ${STAMP} (gewijzigd)`)
})
