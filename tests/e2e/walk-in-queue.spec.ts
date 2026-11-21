/*
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 * SPDX-License-Identifier: EUPL-1.2
 *
 * The walk-in queue page (walk-in-queue-page, task 1 "an e2e walk through the
 * queue"). Seeds three walk-in tickets that arrived at the very start of
 * today, so they are the oldest waiting tickets of the day, then works the
 * queue on /walk-in: Call next calls the oldest one and it leaves the
 * waiting list; Help serves the called ticket; Left marks a waiting one as
 * abandoned. Each change is read back through the OpenRegister object API.
 *
 * Written from the change's scenario; not yet run (it needs a seeded
 * instance).
 *
 * @e2e walk-in-queue-page/appointment-booking::the-operator-calls-the-next-walk-in
 */
import type { Page } from '@playwright/test'

import { expect, test } from '@playwright/test'
import { gotoAppRoute, openApp } from './helpers/pipelinq.ts'

const STAMP = `e2e-walkin-${Date.now()}`
const OBJECTS = '/index.php/apps/openregister/api/objects/pipelinq/walkInTicket'

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
						document.querySelector('head[data-requesttoken]')?.getAttribute('data-requesttoken') ?? '',
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
 * The start of today in the browser's time zone, plus some seconds, as ISO.
 *
 * @param seconds Seconds after midnight.
 *
 * @return The timestamp.
 */
function todayAt(seconds: number): string {
	const start = new Date()
	start.setHours(0, 0, 0, 0)
	return new Date(start.getTime() + seconds * 1000).toISOString()
}

/**
 * Seed one waiting walk-in ticket and return its id.
 *
 * @param page    The page to run in.
 * @param name    The customer's display name.
 * @param seconds When it arrived, in seconds after midnight.
 *
 * @return The created id.
 */
async function seedTicket(page: Page, name: string, seconds: number): Promise<string> {
	const res = await api(page, 'POST', OBJECTS, { displayName: name, arrivedAt: todayAt(seconds), status: 'waiting' })
	expect(res.status, `could not seed a walk-in ticket: ${JSON.stringify(res.body).slice(0, 300)}`).toBeLessThan(400)
	const created = res.body as { id?: string; '@self'?: { id?: string } }
	return String(created.id ?? created['@self']?.id ?? '')
}

/**
 * The stored status of one ticket.
 *
 * @param page The page to run in.
 * @param id   The ticket id.
 *
 * @return Its status.
 */
async function statusOf(page: Page, id: string): Promise<string> {
	const res = await api(page, 'GET', `${OBJECTS}/${id}`)
	expect(res.status).toBe(200)
	return String(res.body.status ?? '')
}

test.describe('the walk-in queue', () => {
	const ids: Record<string, string> = {}

	test.beforeAll(async ({ browser }) => {
		const page = await browser.newPage()
		await openApp(page)
		ids.first = await seedTicket(page, `${STAMP} first`, 1)
		ids.second = await seedTicket(page, `${STAMP} second`, 2)
		ids.third = await seedTicket(page, `${STAMP} third`, 3)
		await page.close()
	})

	test.afterAll(async ({ browser }) => {
		const page = await browser.newPage()
		await openApp(page)
		for (const id of Object.values(ids)) {
			await api(page, 'DELETE', `${OBJECTS}/${id}`)
		}
		await page.close()
	})

	test('the operator calls the next walk-in, helps them, and marks one as left', async ({ page }) => {
		await gotoAppRoute(page, '/walk-in')
		const content = page.locator('#content-vue')
		await expect(content.getByText('In the queue').first()).toBeVisible({ timeout: 15000 })
		for (const which of ['first', 'second', 'third']) {
			await expect(page.getByRole('row').filter({ hasText: `${STAMP} ${which}` })).toHaveCount(1)
		}

		// Call next calls whoever has waited longest: the first of the three.
		await content.getByRole('button', { name: 'Call next' }).first().click()
		await expect.poll(() => statusOf(page, ids.first), { timeout: 15000 }).toBe('called')
		expect(await statusOf(page, ids.second)).toBe('waiting')
		expect(await statusOf(page, ids.third)).toBe('waiting')

		// The called ticket offers Help, which serves it.
		await gotoAppRoute(page, '/walk-in')
		const firstRow = page.getByRole('row').filter({ hasText: `${STAMP} first` })
		await firstRow.getByRole('button', { name: 'Help' }).first().click()
		await expect.poll(() => statusOf(page, ids.first), { timeout: 15000 }).toBe('served')

		// A waiting customer who walked out is marked Left.
		await gotoAppRoute(page, '/walk-in')
		const thirdRow = page.getByRole('row').filter({ hasText: `${STAMP} third` })
		const left = thirdRow.getByRole('button', { name: 'Left' }).first()
			.or(thirdRow.getByRole('menuitem', { name: 'Left' }).first())
		if (!(await left.isVisible())) {
			// Past the inline actions, Left sits in the row's overflow menu.
			await thirdRow.getByRole('button', { name: /actions/i }).first().click()
		}
		await page.getByRole('menuitem', { name: 'Left' }).first()
			.or(thirdRow.getByRole('button', { name: 'Left' }).first())
			.click()
		await expect.poll(() => statusOf(page, ids.third), { timeout: 15000 }).toBe('abandoned')
		expect(await statusOf(page, ids.second)).toBe('waiting')
	})
})
