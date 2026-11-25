/*
 * SPDX-FileCopyrightText: 2026 Pipelinq Contributors
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Gate-19 behavioural e2e coverage for
 * openspec/specs/lead-scoring-win-probability/spec.md, requirement "Win
 * probability is surfaced on the pipeline list and deal detail".
 *
 * `winProbability` is a `materialise: false` calculation: OpenRegister
 * computes it only when the read carries `_extend[]=calculations`
 * (pipelinq#774, run 31473685688). The Leads list, the lead page and the
 * pipeline board now ask for it (lead-win-probability-recency), so these
 * tests read the served number where a salesperson sees it.
 *
 * One open lead is created through OpenRegister. A fresh lead was touched just
 * now, so its win chance is its full probability. The decay bands are keyed on
 * `@self.updated`, which no client may set, so they are not asserted here.
 */

import type { Page } from '@playwright/test'

import { expect, test } from '@playwright/test'
import { gotoAppRoute } from '../helpers/pipelinq.ts'

const OR = '/index.php/apps/openregister/api/objects/pipelinq'

/** One JSON call issued from inside the logged-in page. */
async function api(
	page: Page,
	method: string,
	path: string,
	body?: unknown,
): Promise<{ status: number; json: any; text: string }> {
	return await page.evaluate(
		async ({ method, path, body }) => {
			const res = await fetch(path, {
				method,
				headers: {
					'Content-Type': 'application/json',
					requesttoken: (window as any).OC?.requestToken || '',
					'OCS-APIREQUEST': 'true',
				},
				body: body === undefined ? undefined : JSON.stringify(body),
			})
			const text = await res.text()
			let json: any = null
			try {
				json = text ? JSON.parse(text) : null
			} catch {
				/* not JSON */
			}
			return { status: res.status, json, text: text.slice(0, 600) }
		},
		{ method, path, body },
	)
}

/** Read the id off an OpenRegister object. */
function idOf(row: any): string {
	return String(row?.id || row?.['@self']?.id || row?.uuid || '')
}

/** The first object of a schema, which the CI seed provides. */
async function firstId(page: Page, schema: string): Promise<string> {
	const res = await api(page, 'GET', `${OR}/${schema}?_limit=1`)
	expect(res.status, res.text).toBe(200)
	const id = idOf(res.json?.results?.[0])
	expect(id, `the CI seed must provide a ${schema}`).not.toBe('')
	return id
}

const RUN = `Win chance ${Date.now()}`
let leadId = ''

/** Create the run's lead once. */
async function createLead(page: Page): Promise<void> {
	if (leadId) return
	const client = await firstId(page, 'client')
	const pipeline = await firstId(page, 'pipeline')
	const res = await api(page, 'POST', `${OR}/lead`, {
		title: RUN,
		client,
		pipeline,
		status: 'open',
		probability: 60,
		value: 10000,
	})
	expect(res.status, res.text).toBeLessThan(300)
	leadId = idOf(res.json)
}

/** The served win chance of the run's lead, as the page prints it. */
async function servedWinChance(page: Page): Promise<string> {
	const res = await api(page, 'GET', `${OR}/lead/${leadId}?_extend[]=calculations`)
	expect(res.status, res.text).toBe(200)
	expect(typeof res.json?.winProbability, res.text).toBe('number')
	return String(Math.round(Number(res.json.winProbability)))
}

test.afterAll(async ({ browser }) => {
	if (!leadId) return
	const page = await browser.newPage()
	await gotoAppRoute(page, '/')
	await api(page, 'DELETE', `${OR}/lead/${leadId}`)
	await page.close()
})

// @e2e lead-scoring-win-probability::pipeline-list-column
test('the Leads index shows the served win chance in the Win chance column', async ({
	page,
}) => {
	await gotoAppRoute(page, '/')
	await createLead(page)
	const expected = await servedWinChance(page)
	await gotoAppRoute(page, '/leads')

	await expect(page.locator('thead th', { hasText: 'Win chance' }).first()).toBeVisible({ timeout: 20000 })
	const headers = await page.locator('thead th').allInnerTexts()
	const column = headers.findIndex((text) => text.includes('Win chance'))
	expect(column).toBeGreaterThanOrEqual(0)

	const row = page.locator('tbody tr', { hasText: RUN }).first()
	await expect(row).toBeVisible({ timeout: 20000 })
	await expect(row.locator('td').nth(column)).toContainText(expected, { timeout: 15000 })
})

// @e2e lead-scoring-win-probability::deal-page
test('the lead page shows the served win chance', async ({ page }) => {
	await gotoAppRoute(page, '/')
	await createLead(page)
	const expected = await servedWinChance(page)

	await gotoAppRoute(page, `/leads/${leadId}`)
	await expect(page.getByText('Win chance').first()).toBeVisible({ timeout: 20000 })
	await expect(page.getByText(`${expected}%`).first()).toBeVisible({ timeout: 15000 })
})
