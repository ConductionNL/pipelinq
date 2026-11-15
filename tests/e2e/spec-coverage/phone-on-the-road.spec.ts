/*
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Working a client visit from a phone (platform-phone-on-the-road), at a
 * 390 by 844 viewport: the only honest check of "works on a phone" without a
 * device. Records are created through OpenRegister and removed afterwards.
 */

import type { Page } from '@playwright/test'

import { expect, test } from '@playwright/test'
import { gotoAppRoute } from '../helpers/pipelinq.ts'

test.use({ viewport: { width: 390, height: 844 } })

const OR = '/index.php/apps/openregister/api/objects/pipelinq'
const RUN = `Phone ${Date.now()}`
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

/** The page does not scroll sideways. */
async function expectNoHorizontalScroll(page: Page) {
	const { scrollWidth, clientWidth } = await page.evaluate(() => ({
		scrollWidth: document.documentElement.scrollWidth,
		clientWidth: document.documentElement.clientWidth,
	}))
	expect(
		scrollWidth,
		'the page must not scroll sideways at 390 px',
	).toBeLessThanOrEqual(clientWidth)
}

test.afterAll(async ({ browser }) => {
	const page = await browser.newPage()
	await gotoAppRoute(page, '/')
	for (const [schema, id] of created.reverse()) {
		await api(page, 'DELETE', `${OR}/${schema}/${id}`)
	}
	await page.close()
})

// @e2e mobile-experience::tap-to-call
test('a client number is a call link that keeps the number as typed', async ({
	page,
}) => {
	await gotoAppRoute(page, '/')
	const client = await create(page, 'client', {
		name: `${RUN} Bakkerij De Jong`,
		type: 'organization',
		phone: '06 12 34 56 78',
	})
	await gotoAppRoute(page, `/clients/${client}`)

	const link = page.locator('[data-testid="contact-link-phone"]')
	await expect(link).toBeVisible({ timeout: 20000 })
	await expect(link).toHaveAttribute('href', 'tel:0612345678')
	await expect(link).toContainText('06 12 34 56 78')
	await expectNoHorizontalScroll(page)
})

// @e2e mobile-experience::today-first
test('My work shows today first and does not scroll sideways', async ({ page }) => {
	await gotoAppRoute(page, '/')
	const uid = await page.evaluate(
		() => (window as any).OC?.getCurrentUser?.()?.uid,
	)
	const today = new Date()
	today.setHours(15, 0, 0, 0)
	const later = new Date(today.getTime() + 3 * 86400000)
	for (const [n, due] of [
		[1, today],
		[2, today],
		[3, later],
	] as const) {
		await create(page, 'crmTask', {
			type: 'followUpTask',
			subject: `${RUN} follow-up ${n}`,
			status: 'open',
			deadline: due.toISOString(),
			assigneeUserId: uid,
		})
	}
	await gotoAppRoute(page, '/my-work')

	const firstGroup = page.locator('.work-group').first()
	await expect(firstGroup.locator('.work-group__header')).toContainText('Today', {
		timeout: 20000,
	})
	await expect(firstGroup.locator('.work-card', { hasText: RUN })).toHaveCount(2)
	await expectNoHorizontalScroll(page)
})

// @e2e mobile-experience::note-after-a-visit
test('Log a visit records the visit and a follow-up task', async ({ page }) => {
	await gotoAppRoute(page, '/')
	const client = await create(page, 'client', {
		name: `${RUN} visit`,
		type: 'organization',
	})
	await gotoAppRoute(page, `/clients/${client}`)

	await page.locator('[data-testid="log-visit-button"]').click()
	await page.locator('#log-visit-note').fill('wants a quote for two ovens')
	const friday = new Date(Date.now() + 3 * 86400000).toISOString().slice(0, 10)
	await page.locator('#log-visit-follow-up').fill(friday)
	await page.getByRole('button', { name: 'Save visit' }).click()

	await expect
		.poll(
			async () => {
				const res = await api(
					page,
					'GET',
					`${OR}/ticket?client=${client}&channel=visit`,
				)
				return (res.json?.results ?? []).map((r: any) => r.description)
			},
			{ timeout: 20000 },
		)
		.toContain('wants a quote for two ovens')
	const tasks = await api(page, 'GET', `${OR}/crmTask?clientId=${client}`)
	const task = (tasks.json?.results ?? [])[0]
	expect(task?.deadline?.slice(0, 10)).toBe(friday)
	for (const row of (await api(page, 'GET', `${OR}/ticket?client=${client}`)).json
		?.results ?? []) {
		created.push(['ticket', idOf(row)])
	}
	if (task) created.push(['crmTask', idOf(task)])
})

test('lead detail and the board do not scroll sideways at phone width', async ({
	page,
}) => {
	await gotoAppRoute(page, '/')
	const lead = idOf(
		(await api(page, 'GET', `${OR}/lead?_limit=1`)).json?.results?.[0],
	)
	if (lead) {
		await gotoAppRoute(page, `/leads/${lead}`)
		await expect(page.locator('[data-testid="log-visit-button"]')).toBeVisible({
			timeout: 20000,
		})
		await expectNoHorizontalScroll(page)
	}
	await gotoAppRoute(page, '/pipeline')
	await expectNoHorizontalScroll(page)
})
