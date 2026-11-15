/*
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 * SPDX-License-Identifier: EUPL-1.2
 *
 * A letter for a client from a filinq template (work-letter-from-filinq-template).
 * The letter itself needs filinq with a template in the pipelinq namespace:
 * those tests run where the instance has both and say why they skip where it
 * has not. The absence test runs on every instance, because either answer of
 * the templates endpoint has a matching page to check.
 */

import type { Page } from '@playwright/test'

import { expect, test } from '@playwright/test'
import { gotoAppRoute } from '../helpers/pipelinq.ts'

const LETTERS = '/index.php/apps/pipelinq/api/letters/templates'
const OR = '/index.php/apps/openregister/api/objects/pipelinq'
const RUN = `Letter ${Date.now()}`
const created: Array<[string, string]> = []

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

/** Create an object and remember it for clean-up. */
async function create(page: Page, schema: string, body: object): Promise<string> {
	const res = await api(page, 'POST', `${OR}/${schema}`, body)
	expect(res.status, res.text).toBeLessThan(300)
	const id = idOf(res.json)
	created.push([schema, id])
	return id
}

/** The templates endpoint's answer. */
async function letterState(
	page: Page,
): Promise<{ available: boolean; templates: Array<{ id: string; name: string }> }> {
	const res = await api(page, 'GET', LETTERS)
	expect(res.status, res.text).toBe(200)
	return {
		available: res.json?.available === true,
		templates: res.json?.templates || [],
	}
}

/** Open the detail page's Actions menu. */
async function openActions(page: Page) {
	const button = page
		.locator('#content-vue')
		.getByRole('button', { name: 'Actions' })
		.first()
	await expect(button).toBeVisible({ timeout: 20000 })
	await button.click()
}

test.afterAll(async ({ browser }) => {
	const page = await browser.newPage()
	await gotoAppRoute(page, '/')
	for (const [schema, id] of created.reverse()) {
		await api(page, 'DELETE', `${OR}/${schema}/${id}`)
	}
	await page.close()
})

// @e2e client-letters::an-instance-without-filinq
test('Make a letter is offered only when filinq can make one', async ({ page }) => {
	await gotoAppRoute(page, '/')
	const state = await letterState(page)
	const client = await create(page, 'client', {
		name: `${RUN} Bakkerij De Jong`,
		type: 'organization',
	})
	await gotoAppRoute(page, `/clients/${client}`)
	await openActions(page)
	const item = page.getByRole('menuitem', { name: 'Make a letter' })

	if (state.available) {
		await expect(item).toBeVisible()
		return
	}

	await expect(item).toHaveCount(0)
	const res = await api(
		page,
		'POST',
		`/index.php/apps/pipelinq/api/clients/${client}/letters`,
		{ templateId: 'any' },
	)
	expect(res.status).toBe(503)
	expect(res.text).toContain('filinq')
})

// @e2e client-letters::an-account-manager-prints-an-appointment-letter
// @e2e client-letters::the-timeline-shows-the-letter
test('a letter from a template downloads and is logged on the client', async ({
	page,
}) => {
	await gotoAppRoute(page, '/')
	const state = await letterState(page)
	test.skip(
		!state.available || state.templates.length === 0,
		'needs filinq with a template in the pipelinq namespace',
	)

	const client = await create(page, 'client', {
		name: `${RUN} Bakkerij De Jong`,
		type: 'organization',
	})
	await gotoAppRoute(page, `/clients/${client}`)
	await openActions(page)
	await page.getByRole('menuitem', { name: 'Make a letter' }).click()
	const dialog = page.locator('[data-testid="letter-modal"]')
	await expect(dialog).toBeVisible()

	const download = page.waitForEvent('download')
	if (state.templates.length > 1) {
		await page.locator('[data-testid="letter-template"]').click()
		await page.getByRole('option', { name: state.templates[0].name }).click()
	}
	await page.locator('[data-testid="letter-make"]').click()
	expect((await download).suggestedFilename()).toMatch(/\.pdf$/)
	await expect(page.locator('[data-testid="letter-result"]')).toBeVisible()

	const moments = await api(
		page,
		'GET',
		`${OR}/ticket?client=${client}&ticketType=interaction`,
	)
	const rows = moments.json?.results || []
	expect(
		rows.some(
			(row: any) =>
				row.channel === 'brief'
				&& row.direction === 'outbound'
				&& String(row.title).startsWith('Letter: '),
		),
	).toBe(true)
	for (const row of rows) created.push(['ticket', idOf(row)])
})

// @e2e client-letters::a-kcc-employee-writes-to-the-resident-about-their-request
test('a letter from a ticket hangs under that ticket', async ({ page }) => {
	await gotoAppRoute(page, '/')
	const state = await letterState(page)
	test.skip(
		!state.available || state.templates.length === 0,
		'needs filinq with a template in the pipelinq namespace',
	)

	const client = await create(page, 'client', {
		name: `${RUN} Resident`,
		type: 'person',
	})
	const ticket = await create(page, 'ticket', {
		title: `${RUN} Oven repair`,
		ticketType: 'request',
		client,
	})
	const res = await api(
		page,
		'POST',
		`/index.php/apps/pipelinq/api/clients/${client}/letters`,
		{
			templateId: state.templates[0].id,
			ticketId: ticket,
		},
	)
	expect(res.status, res.text).toBe(200)
	expect(res.json?.contactMomentId).toBeTruthy()
	created.push(['ticket', res.json.contactMomentId])
	const moment = await api(page, 'GET', `${OR}/ticket/${res.json.contactMomentId}`)
	expect(moment.json?.parentTicket).toBe(ticket)
})
