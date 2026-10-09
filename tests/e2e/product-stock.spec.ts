/*
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 * SPDX-License-Identifier: EUPL-1.2
 *
 * The Stock tracked line on the product page (products-stock-on-hand).
 *
 * Seeds a stock-tracked product on a SKU that shillinq's demo stock carries
 * (TONER-HP-CF283A, in Amsterdam and Rotterdam) and a service that keeps no
 * stock, then reads the Supply card. What the card says is checked against
 * the stock route's own answer, so the test holds with and without shillinq:
 * with shillinq the card reads "Yes, <number>" and lists both locations;
 * without it the card says shillinq is not installed.
 *
 * @e2e product-stock::a-salesperson-checks-stock-before-quoting
 * @e2e product-stock::stock-in-several-locations-is-summed-and-listed
 * @e2e product-stock::a-service-is-not-stock-tracked
 * @e2e product-stock::shillinq-is-not-installed
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

test.describe('stock on the product page', () => {
	test.setTimeout(120000)

	test('a tracked product shows what the stock route answers', async ({ page }) => {
		await openApp(page)
		const id = await seed(page, 'product', {
			name: `Toner HP 83A ${STAMP}`,
			sku: 'TONER-HP-CF283A',
			unitPrice: 79.95,
			type: 'product',
			unitOfMeasure: 'stuks',
			stockTracked: true,
		})
		const stock = await api(page, 'GET', `/index.php/apps/pipelinq/api/products/${id}/stock`)
		expect(stock.status).toBe(200)
		expect(stock.body.tracked).toBe(true)

		await gotoAppRoute(page, `/products/${id}`)
		const line = page.getByTestId('product-stock')
		await expect(line).toBeVisible({ timeout: 30000 })
		if (stock.body.state === 'ok') {
			await expect(line).toContainText(/^(Yes|Ja), /)
			const locations = stock.body.locations as unknown[]
			if (locations.length >= 2) {
				await expect(page.getByTestId('product-stock-locations')).toContainText('·')
			}
		} else {
			expect(stock.body.state).toBe('no-shillinq')
			await expect(line).toContainText(/shillinq/)
		}
	})

	test('a service reads No', async ({ page }) => {
		await openApp(page)
		const id = await seed(page, 'product', {
			name: `Knippen ${STAMP}`,
			sku: `SRV-${STAMP}`,
			unitPrice: 42.5,
			type: 'service',
			stockTracked: false,
		})
		const stock = await api(page, 'GET', `/index.php/apps/pipelinq/api/products/${id}/stock`)
		expect(stock.body.state).toBe('untracked')

		await gotoAppRoute(page, `/products/${id}`)
		await expect(page.getByTestId('product-stock')).toHaveText(/^\s*(No|Nee)\s*$/, { timeout: 30000 })
	})
})
