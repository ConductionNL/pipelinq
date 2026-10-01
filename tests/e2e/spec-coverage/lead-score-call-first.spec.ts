/*
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Lead score in the list and on the board (pipeline-lead-score-call-first).
 *
 * Three leads are created through OpenRegister with different fields, so
 * OpenRegister's own `qualificationScore` calculation scores them on save
 * (client is required, so every lead starts at 15):
 *   - high:   client, value 25,000, referral, close date, urgent  = 80
 *   - medium: client, value 5,000, close date, description         = 40
 *   - low:    client only                                           = 15
 * The test then reads the numbers where a salesperson decides who to call:
 * the Leads list with Call first on, the board card, and the explanation.
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

const RUN = `Score ${Date.now()}`
const created: string[] = []

/**
 * Create the three scored leads once per worker.
 *
 * @param page A logged-in page.
 */
async function createLeads(page: Page): Promise<void> {
	if (created.length) return
	const client = await firstId(page, 'client')
	const pipeline = await firstId(page, 'pipeline')
	const base = { client, pipeline, status: 'open' }
	const leads = [
		{ ...base, title: `${RUN} low` },
		{
			...base,
			title: `${RUN} medium`,
			value: 5000,
			expectedCloseDate: '2027-01-15',
			description: 'Asked for a quote',
		},
		{
			...base,
			title: `${RUN} high`,
			value: 25000,
			source: 'referral',
			expectedCloseDate: '2027-01-15',
			priority: 'urgent',
		},
	]
	for (const lead of leads) {
		const res = await api(page, 'POST', `${OR}/lead`, lead)
		expect(res.status, res.text).toBeLessThan(300)
		created.push(idOf(res.json))
	}
}

test.afterAll(async ({ browser }) => {
	const page = await browser.newPage()
	await gotoAppRoute(page, '/')
	for (const id of created) {
		await api(page, 'DELETE', `${OR}/lead/${id}`)
	}
	await page.close()
})

// @e2e lead-management::call-first
test('Call first lists the leads highest score first with their band', async ({
	page,
}) => {
	await gotoAppRoute(page, '/')
	await createLeads(page)
	await gotoAppRoute(page, '/leads')

	await page.getByText('Call first', { exact: true }).click()
	const rows = page.locator('tr', { hasText: RUN })
	await expect(rows).toHaveCount(3, { timeout: 20000 })

	const titles = await rows.allInnerTexts()
	const order = titles.map((t) =>
		t.includes(`${RUN} high`)
			? 'high'
			: t.includes(`${RUN} medium`)
				? 'medium'
				: 'low',
	)
	expect(order).toEqual(['high', 'medium', 'low'])
	await expect(rows.nth(0).locator('.lead-score-badge__band')).toHaveText('High')
	await expect(rows.nth(1).locator('.lead-score-badge__band')).toHaveText('Medium')
	await expect(rows.nth(2).locator('.lead-score-badge__band')).toHaveText('Low')
})

// @e2e lead-management::card-badge
test('the board card carries the score with its band as its accessible name', async ({
	page,
}) => {
	await gotoAppRoute(page, '/')
	await createLeads(page)
	await gotoAppRoute(page, '/pipeline')

	const card = page.locator('.pipeline-card', { hasText: `${RUN} high` })
	await expect(card).toBeVisible({ timeout: 20000 })
	await expect(card.getByRole('button', { name: 'Score 80, high' })).toBeVisible()
})

// @e2e lead-management::explain-35
test('the explanation lists what added points and the total', async ({ page }) => {
	await gotoAppRoute(page, '/')
	await createLeads(page)
	await gotoAppRoute(page, '/leads')

	const row = page.locator('tr', { hasText: `${RUN} medium` })
	await expect(row).toBeVisible({ timeout: 20000 })
	await row.getByRole('button', { name: 'Score 40, medium' }).click()

	const panel = page.locator('.lead-score-explanation')
	await expect(panel).toBeVisible()
	for (const line of [
		'Client linked',
		'Value present',
		'Expected close date set',
		'Description written',
	]) {
		await expect(panel).toContainText(line)
	}
	await expect(panel.locator('.lead-score-explanation__total')).toContainText('40')
})
