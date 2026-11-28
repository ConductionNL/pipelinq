/*
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Upcoming meetings on My work (my-work-roadmap block 5, REQ-MW-130).
 *
 *   @e2e my-work::a-meeting-with-a-client-this-week
 *   @e2e my-work::another-users-calendar-stays-out
 *
 * An event in the agent's own calendar whose description links a client's
 * page is listed under Upcoming meetings with its title and the client's
 * name; an event in a colleague's calendar is not. The No Calendar app
 * scenario is covered by the unit tests (MyWorkCalendarServiceTest), since a
 * shared test instance cannot switch the Calendar app off.
 *
 * Written from the change's scenarios; not yet run (it needs a seeded instance).
 *
 * @spec openspec/changes/archive/2026-11-25-my-work-roadmap/specs/my-work/spec.md#requirement-upcoming-meetings-from-the-calendar-req-mw-130
 */
import type { APIRequestContext } from '@playwright/test'

import { expect, request, test } from '@playwright/test'
import { resolveBaseUrl } from '../../base-url.ts'
import { gotoAppRoute } from '../../helpers/pipelinq.ts'

const RUN = Date.now().toString(36)
const OBJECTS = '/index.php/apps/openregister/api/objects/pipelinq'
const ADMIN = {
	username: process.env.ADMIN_USER ?? process.env.NC_ADMIN_USER ?? 'admin',
	password: process.env.ADMIN_PASSWORD ?? process.env.NC_ADMIN_PASS ?? 'admin',
}
const COLLEAGUE = { username: `e2e-mw-${RUN}`, password: `E2e-mw-${RUN}-Pw!` }

let api: APIRequestContext
let clientId = ''
const events: Array<{ user: string; uid: string }> = []

/**
 * An iCalendar date-time in UTC, `days` from now at 10:00.
 *
 * @param days Days from today.
 */
function icsDate(days: number): string {
	const at = new Date()
	at.setUTCDate(at.getUTCDate() + days)
	at.setUTCHours(10, 0, 0, 0)
	return at.toISOString().replace(/[-:]/g, '').replace(/\.\d{3}/, '')
}

/**
 * Put an event in a user's personal calendar over CalDAV.
 *
 * @param user The calendar's owner.
 * @param password Their password.
 * @param summary The event's title.
 * @param description The event's description.
 */
async function putEvent(user: string, password: string, summary: string, description: string): Promise<void> {
	const uid = `${RUN}-${events.length}@e2e`
	const dav = await request.newContext({ baseURL: resolveBaseUrl(), httpCredentials: { username: user, password } })
	const res = await dav.put(`/remote.php/dav/calendars/${user}/personal/${uid}.ics`, {
		headers: { 'Content-Type': 'text/calendar; charset=utf-8' },
		data: [
			'BEGIN:VCALENDAR',
			'VERSION:2.0',
			'PRODID:-//pipelinq e2e//EN',
			'BEGIN:VEVENT',
			`UID:${uid}`,
			`DTSTAMP:${icsDate(0)}`,
			`DTSTART:${icsDate(2)}`,
			`DTEND:${icsDate(2).replace('T100000', 'T110000')}`,
			`SUMMARY:${summary}`,
			`DESCRIPTION:${description}`,
			'END:VEVENT',
			'END:VCALENDAR',
			'',
		].join('\r\n'),
	})
	expect(res.status(), `putting ${summary}`).toBeLessThan(300)
	events.push({ user, uid })
	await dav.dispose()
}

test.describe('My work: Upcoming meetings', () => {
	test.setTimeout(120_000)

	test.beforeAll(async () => {
		api = await request.newContext({
			baseURL: resolveBaseUrl(),
			httpCredentials: ADMIN,
			extraHTTPHeaders: { 'OCS-APIRequest': 'true', Accept: 'application/json' },
		})
		const client = await api.post(`${OBJECTS}/client`, { data: { name: `Bakkerij De Jong ${RUN}`, type: 'organization' } })
		expect(client.ok(), `creating the client: ${client.status()}`).toBeTruthy()
		const body = await client.json()
		clientId = String(body.id ?? body['@self']?.id)

		const user = await api.post('/ocs/v2.php/cloud/users', { form: { userid: COLLEAGUE.username, password: COLLEAGUE.password } })
		expect(user.ok(), `creating the colleague: ${user.status()}`).toBeTruthy()
	})

	test.afterAll(async () => {
		await api.delete(`/ocs/v2.php/cloud/users/${COLLEAGUE.username}`)
		if (clientId !== '') {
			await api.delete(`${OBJECTS}/client/${clientId}`)
		}
		await api.dispose()
	})

	test('a meeting with a client this week is listed with its time and the client', async ({ page }) => {
		const title = `Kennismaking ${RUN}`
		await putEvent(ADMIN.username, ADMIN.password, title, `Zie /apps/pipelinq/clients/${clientId}`)

		await gotoAppRoute(page, '/my-work')
		const section = page.locator('#content-vue').getByText('Upcoming meetings').locator('xpath=ancestor::*[contains(@class, "cn-dashboard")][1]')
		await expect(page.locator('#content-vue').getByText(title)).toBeVisible({ timeout: 20_000 })
		await expect(section.getByText(`Bakkerij De Jong ${RUN}`).first()).toBeVisible()
	})

	test("another user's calendar stays out", async () => {
		const title = `Overleg collega ${RUN}`
		await putEvent(COLLEAGUE.username, COLLEAGUE.password, title, `/apps/pipelinq/clients/${clientId}`)

		const res = await api.get('/index.php/apps/pipelinq/api/my-work/meetings')
		expect(res.ok()).toBeTruthy()
		const titles = ((await res.json()).items ?? []).map((item: { title: string }) => item.title)
		expect(titles).not.toContain(title)
	})
})
