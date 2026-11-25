// SPDX-License-Identifier: EUPL-1.2
// Copyright (C) 2026 Conduction B.V.

import type { APIRequestContext, Browser, Page } from '@playwright/test'

import { expect, test } from '@playwright/test'
import { login } from './helpers/auth.ts'
import { gotoAppRoute } from './helpers/pipelinq.ts'

/**
 * Price lists price a lead line on the server (products-price-lists).
 *
 * WHAT THE UNIT TESTS CANNOT SHOW. ProductCatalogServiceTest pins the
 * resolution order and LeadLinePricerTest pins when a line is priced and when a
 * typed price stays, both over doubles. Neither shows that OpenRegister runs
 * the pricing listener before it stores a line, that the line comes back with
 * the list price and the list's title, or that the Price lists page draws a
 * list's prices. Those sit between pipelinq and OpenRegister, so they are here.
 *
 * 🔴 THE SALESPERSON IS NOT THE ADMIN. The resolver reads the client with the
 * caller's rights, and the register is open to the `sales` group only. Run as
 * the admin, the lines would be priced for an account no salesperson has. So
 * the lines are written by a provisioned member of `sales`, from an empty
 * storage state, and the page is opened in a browser context of their own.
 *
 * The admin only seeds: the product, the list, the client and the lead.
 * Base URL and credentials come from the environment (playwright.config.ts).
 *
 * NOT RUN when written: it needs a live instance with this branch installed
 * (tasks.md 1.4 and 1.5 verify lines).
 *
 * @spec openspec/changes/products-price-lists/specs/product-price-lists/spec.md
 */

/** A prefix that marks every object this run seeds. */
const RUN = `E2E-PL-${Date.now().toString(36)}`

/** The salesperson, a member of the staff group `sales`. */
const SALES_USER = process.env.E2E_SALES_USER || 'e2e-sales'
const SALES_PASS = process.env.E2E_SALES_PASS || 'e2e-Sales-pass-2026'

/** OpenRegister's objects endpoint for one pipelinq schema. */
const objects = (schema: string) => `/index.php/apps/openregister/api/objects/pipelinq/${schema}`

/** What the admin seeded, for the tests and for teardown. */
const seeded: Array<{ schema: string; id: string }> = []
let productId = ''
let priceListId = ''
let leadId = ''

/**
 * A request context that authenticates with a password and carries no session.
 *
 * @param playwright The Playwright fixture.
 * @param baseURL    The instance.
 * @param user       The account.
 * @param password   Its password.
 * @return The context.
 */
async function basicContext(
	playwright: { request: { newContext: (o: object) => Promise<APIRequestContext> } },
	baseURL: string,
	user: string,
	password: string,
): Promise<APIRequestContext> {
	return playwright.request.newContext({
		baseURL,
		// An explicit empty jar, or the project's admin session rides along.
		storageState: { cookies: [], origins: [] },
		extraHTTPHeaders: {
			Authorization: `Basic ${Buffer.from(`${user}:${password}`).toString('base64')}`,
			'OCS-APIRequest': 'true',
		},
	})
}

/**
 * Create one object and return its id.
 *
 * @param api    The context.
 * @param schema The schema slug.
 * @param data   The object.
 * @return The id.
 */
async function create(api: APIRequestContext, schema: string, data: Record<string, unknown>): Promise<string> {
	const res = await api.post(objects(schema), { data })
	expect(res.status(), `seed ${schema}: ${await res.text()}`).toBeLessThan(400)
	const body = await res.json()
	return String(body?.id ?? body?.['@self']?.id ?? '')
}

/**
 * A browser page signed in as the salesperson.
 *
 * @param browser The browser.
 * @param baseURL The instance.
 * @return The page.
 */
async function salesPage(browser: Browser, baseURL: string): Promise<Page> {
	const context = await browser.newContext({ baseURL, storageState: { cookies: [], origins: [] } })
	const page = await context.newPage()
	await login(page, SALES_USER, SALES_PASS)
	return page
}

test.describe('Price lists price a lead line on the server', () => {
	test.setTimeout(180_000)

	test.beforeAll(async ({ playwright, baseURL }) => {
		const admin = await basicContext(
			playwright,
			String(baseURL),
			process.env.ADMIN_USER ?? 'admin',
			process.env.ADMIN_PASSWORD ?? 'admin',
		)

		// The salesperson and the staff group. OCS answers 102 for an account
		// or group that already exists, which every run after the first wants.
		await admin.post('/ocs/v2.php/cloud/users?format=json', {
			form: { userid: SALES_USER, password: SALES_PASS, displayName: SALES_USER },
		})
		await admin.post('/ocs/v2.php/cloud/groups?format=json', { form: { groupid: 'sales' } })
		const joined = await admin.post(`/ocs/v2.php/cloud/users/${encodeURIComponent(SALES_USER)}/groups?format=json`, {
			form: { groupid: 'sales' },
		})
		expect(joined.ok(), `add ${SALES_USER} to sales: ${await joined.text()}`).toBeTruthy()

		productId = await create(admin, 'product', { name: `${RUN} Licentie`, type: 'product', unitPrice: 120 })
		seeded.push({ schema: 'product', id: productId })

		const yesterday = new Date(Date.now() - 86_400_000).toISOString().slice(0, 10)
		priceListId = await create(admin, 'priceList', {
			title: `${RUN} Overheid 2027`,
			currency: 'EUR',
			validFrom: yesterday,
			segments: ['public-sector'],
			priority: 10,
			isActive: true,
			entries: [{ product: productId, minQuantity: 1, unitPrice: 95 }],
		})
		seeded.push({ schema: 'priceList', id: priceListId })

		const clientId = await create(admin, 'client', {
			name: `${RUN} Gemeente Zeist`,
			type: 'organization',
			contactsUid: `${RUN.toLowerCase()}-zeist`,
			segment: 'public-sector',
			country: 'NL',
		})
		seeded.push({ schema: 'client', id: clientId })

		const pipeline = await create(admin, 'pipeline', {
			title: `${RUN} pijplijn`,
			stages: [{ name: 'Nieuw', order: 0 }],
		})
		seeded.push({ schema: 'pipeline', id: pipeline })

		leadId = await create(admin, 'lead', {
			title: `${RUN} licenties`,
			pipeline,
			client: clientId,
			currency: 'EUR',
		})
		seeded.push({ schema: 'lead', id: leadId })
	})

	test.afterAll(async ({ playwright, baseURL }) => {
		const admin = await basicContext(
			playwright,
			String(baseURL),
			process.env.ADMIN_USER ?? 'admin',
			process.env.ADMIN_PASSWORD ?? 'admin',
		)
		for (const { schema, id } of seeded.reverse()) {
			await admin.delete(`${objects(schema)}/${id}`)
		}
	})

	/**
	 * @spec openspec/changes/products-price-lists/specs/product-price-lists/spec.md#requirement-a-quote-line-is-priced-from-the-matching-list-req-ppl-002
	 */
	test('a public sector client gets the list price, and the line names the list @spec REQ-PPL-002', async ({ playwright, baseURL }) => {
		const sales = await basicContext(playwright, String(baseURL), SALES_USER, SALES_PASS)
		const id = await create(sales, 'leadProduct', { lead: leadId, product: productId, quantity: 1 })
		seeded.push({ schema: 'leadProduct', id })

		const line = await (await sales.get(`${objects('leadProduct')}/${id}`)).json()
		expect(Number(line.unitPrice), 'the unit price is the list price, not the base price').toBe(95)
		expect(line.priceSource).toBe('price-list')
		expect(line.priceListTitle).toBe(`${RUN} Overheid 2027`)
	})

	/**
	 * @spec openspec/changes/products-price-lists/specs/product-price-lists/spec.md#requirement-a-typed-price-stays-req-ppl-003
	 */
	test('a typed price stays when the quantity changes @spec REQ-PPL-003', async ({ playwright, baseURL }) => {
		const sales = await basicContext(playwright, String(baseURL), SALES_USER, SALES_PASS)
		const id = await create(sales, 'leadProduct', { lead: leadId, product: productId, quantity: 1 })
		seeded.push({ schema: 'leadProduct', id })

		const typed = await sales.patch(`${objects('leadProduct')}/${id}`, { data: { unitPrice: 88 } })
		expect(typed.ok(), await typed.text()).toBeTruthy()
		const more = await sales.patch(`${objects('leadProduct')}/${id}`, { data: { quantity: 3 } })
		expect(more.ok(), await more.text()).toBeTruthy()

		const line = await (await sales.get(`${objects('leadProduct')}/${id}`)).json()
		expect(Number(line.unitPrice), 'the negotiated price is kept').toBe(88)
		expect(line.priceSource, 'and the line says it was set by hand').toBe('manual')
	})

	/**
	 * @spec openspec/changes/products-price-lists/specs/product-price-lists/spec.md#requirement-a-price-list-holds-prices-for-a-segment-a-country-or-one-client-req-ppl-001
	 */
	test('the price list page shows the list and its prices @spec REQ-PPL-001', async ({ browser, baseURL }) => {
		const page = await salesPage(browser, String(baseURL))
		await gotoAppRoute(page, '/price-lists')
		await expect(page.getByText(`${RUN} Overheid 2027`).first()).toBeVisible({ timeout: 30_000 })

		await gotoAppRoute(page, `/price-lists/${priceListId}`)
		const prices = page.locator('[data-testid^="cn-field-list"], .cn-field-list').first()
		await expect(prices.getByText(`${RUN} Licentie`)).toBeVisible({ timeout: 30_000 })
		await expect(prices.getByText(/95/)).toBeVisible()

		await page.context().close()
	})
})
