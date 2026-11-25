/*
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 * SPDX-License-Identifier: EUPL-1.2
 *
 * A quote is sent from a lead's lines and logged as an outbound contact
 * moment that names the copy filed in Files (products-quote-from-lead task 7).
 *
 *   @e2e products-quote-from-lead::send-to-the-contact
 *   @e2e products-quote-from-lead::no-recipient
 *
 * The run seeds, through OpenRegister's object API, a client and a contact
 * with an email address, a lead on them, two products and two lines on the
 * lead. The lines are written through the API because the lead form's line
 * picker (`lead.lines.addFrom`) copies the product's price and cannot be told
 * apart from a typed row here; the send itself goes through the page: the
 * lead's "Send quote" header action, the template, the valid-until date and
 * the recipient line, then Send quote.
 *
 * Quotes are rendered by filinq. Without filinq, or without a quote template
 * in the pipelinq namespace, `GET /api/letters/templates` answers
 * `available: false` or no templates and the test skips rather than pass.
 *
 * Not run yet: it needs an instance with filinq, a quote template and a mail
 * transport. Everything it made is removed afterwards.
 *
 * @spec openspec/changes/products-quote-from-lead/specs/product-catalog-quoting/spec.md#requirement-a-quote-is-sent-as-a-pdf-and-logged-req-qslice-002
 */
import type { APIRequestContext, Page } from '@playwright/test'

import { expect, request, test } from '@playwright/test'
import { resolveBaseUrl } from './base-url.ts'
import { clickHeaderAction, dismissSupportDialog, dismissWalkthrough, gotoAppRoute } from './helpers/pipelinq.ts'

const BASE_URL = resolveBaseUrl()
const RUN = Date.now().toString(36)
const OR = '/index.php/apps/openregister/api/objects/pipelinq'
const API = '/index.php/apps/pipelinq/api'
const ADMIN = {
	username: process.env.ADMIN_USER ?? process.env.NC_ADMIN_USER ?? 'admin',
	password: process.env.ADMIN_PASSWORD ?? process.env.NC_ADMIN_PASS ?? 'admin',
}
const HEADERS = { 'OCS-APIRequest': 'true', Accept: 'application/json' }
const EMAIL = `e2e-quote-${RUN}@example.org`

let api: APIRequestContext
const made: Array<{ schema: string; id: string }> = []

/**
 * Create one object and remember it for the clean-up.
 *
 * @param schema The pipelinq schema slug.
 * @param data The object.
 * @return The new object's id.
 */
async function create(schema: string, data: Record<string, unknown>): Promise<string> {
	const response = await api.post(`${OR}/${schema}`, { data })
	expect(response.ok(), `creating a ${schema}: ${response.status()} ${(await response.text()).slice(0, 300)}`).toBeTruthy()
	const body = await response.json()
	const id = String(body.id ?? body['@self']?.id ?? body.uuid)
	made.push({ schema, id })
	return id
}

/**
 * Whether filinq is there with at least one quote template.
 *
 * @return True when a quote can be rendered.
 */
async function quotesAvailable(): Promise<boolean> {
	const response = await api.get(`${API}/letters/templates`)
	if (!response.ok()) {
		return false
	}
	const body = await response.json()
	const templates = body.templates ?? body.results ?? body.data ?? []
	return body.available === true && Array.isArray(templates) && templates.length > 0
}

/**
 * Open the lead and its Send quote dialog.
 *
 * @param page The page.
 * @param leadId The lead.
 */
async function openSendQuote(page: Page, leadId: string): Promise<void> {
	await gotoAppRoute(page, `/leads/${leadId}`)
	await dismissWalkthrough(page)
	await dismissSupportDialog(page)
	await clickHeaderAction(page, 'Send quote')
	await expect(page.getByTestId('quote-modal')).toBeVisible({ timeout: 30_000 })
}

test.describe('a quote sent from a lead', () => {
	test.beforeAll(async () => {
		api = await request.newContext({
			baseURL: BASE_URL,
			httpCredentials: ADMIN,
			extraHTTPHeaders: HEADERS,
		})
	})

	test.afterAll(async () => {
		for (const { schema, id } of made.reverse()) {
			await api.delete(`${OR}/${schema}/${id}`)
		}
		await api.dispose()
	})

	test('two lines are sent as a quote and logged with the filed copy', async ({ page }) => {
		test.setTimeout(180_000)
		test.skip(!(await quotesAvailable()), 'filinq or a quote template is not available on this instance')

		const clientId = await create('client', { name: `E2E Quote client ${RUN}`, type: 'organization', email: EMAIL })
		const contactId = await create('contact', { name: `E2E Quote contact ${RUN}`, email: EMAIL, client: clientId })
		const leadId = await create('lead', { title: `E2E-QTE-${RUN} Nieuw kantoor`, client: clientId, contact: contactId, status: 'open' })
		const adviceId = await create('product', { name: `E2E Advies ${RUN}`, type: 'service', status: 'active', unit: 'hour', unitPrice: 100 })
		const deskId = await create('product', { name: `E2E Bureau ${RUN}`, type: 'goods', status: 'active', unit: 'piece', unitPrice: 500 })
		await create('leadProduct', { lead: leadId, product: adviceId, name: `E2E Advies ${RUN}`, quantity: 10, unit: 'hour', unitPrice: 100 })
		await create('leadProduct', { lead: leadId, product: deskId, name: `E2E Bureau ${RUN}`, quantity: 1, unit: 'piece', unitPrice: 500 })

		await openSendQuote(page, leadId)
		await expect(page.getByTestId('quote-recipient')).toContainText(EMAIL)
		await expect(page.getByTestId('quote-template')).toBeVisible()
		await page.getByTestId('quote-send').click()

		const result = page.getByTestId('quote-result')
		await expect(result).toBeVisible({ timeout: 90_000 })
		await expect(result).toContainText(EMAIL)
		const number = ((await result.textContent()) ?? '').match(/Quote (\S+) was sent/)?.[1] ?? ''
		expect(number, 'the result names the quote number').not.toBe('')
		await expect(result).toContainText('A copy is filed at')

		const moments = await api.get(`${OR}/ticket`, { params: { lead: leadId, channel: 'quote', _limit: '10' } })
		expect(moments.ok(), `reading the contact moments: ${moments.status()}`).toBeTruthy()
		const rows = ((await moments.json()).results ?? []) as Array<Record<string, unknown>>
		const moment = rows.find((row) => row.title === `Quote ${number}`)
		expect(moment, `an outbound contact moment "Quote ${number}" on the lead`).toBeTruthy()
		expect(moment?.direction).toBe('outbound')
		expect(String(moment?.description ?? '')).toContain('A copy is filed at')
		made.push({ schema: 'ticket', id: String(moment?.id ?? (moment?.['@self'] as Record<string, unknown>)?.id) })

		await page.getByRole('button', { name: 'Close' }).click()
		await page.reload()
		await expect(page.getByText(`Quote ${number}`).first()).toBeVisible({ timeout: 30_000 })
	})

	test('a lead without an email address to send to sends nothing', async ({ page }) => {
		test.setTimeout(120_000)
		test.skip(!(await quotesAvailable()), 'filinq or a quote template is not available on this instance')

		const clientId = await create('client', { name: `E2E Quote no mail ${RUN}`, type: 'organization' })
		const leadId = await create('lead', { title: `E2E-QTE-${RUN} no recipient`, client: clientId, status: 'open' })

		await openSendQuote(page, leadId)
		await expect(page.getByTestId('quote-no-recipient')).toBeVisible()
		await expect(page.getByTestId('quote-send')).toBeDisabled()

		const moments = await api.get(`${OR}/ticket`, { params: { lead: leadId, channel: 'quote', _limit: '10' } })
		expect(moments.ok()).toBeTruthy()
		expect(((await moments.json()).results ?? []).length).toBe(0)
	})
})
