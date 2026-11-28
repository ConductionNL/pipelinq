/*
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 * SPDX-License-Identifier: EUPL-1.2
 *
 * A ticket's History tab tells who passed the ticket to whom and what
 * happened afterwards (REQ-THH-001), as read by a handler who is not an
 * administrator (task 2.5).
 *
 *   @e2e ticket-handover-history::a-handover-in-words
 *   @e2e ticket-handover-history::what-happened-after-the-handover
 *
 * Two handlers in the `sales` group (the group that may read and update
 * tickets) are made for the run. Sanne passes a ticket to Joris, Joris
 * resolves it, and Sanne then reads the History tab in a fresh browser
 * context with an empty storage state, logged in as herself. The assertion
 * is positive: both sentences must be there, with the display names.
 *
 * The note under a handover is not asserted: the ticket schema has no
 * handover-note field yet (see tasks.md). The admin account only provisions
 * the two users and the ticket, and removes them again.
 *
 * @spec openspec/specs/ticket-handover-history/spec.md#requirement-the-ticket-page-has-a-history-tab-in-words-req-thh-001
 */
import type { APIRequestContext } from '@playwright/test'

import { expect, request, test } from '@playwright/test'
import { resolveBaseUrl } from './base-url.ts'
import { login } from './helpers/auth.ts'
import { appUrl, dismissSupportDialog, dismissWalkthrough } from './helpers/pipelinq.ts'

const BASE_URL = resolveBaseUrl()
const RUN = Date.now().toString(36)
const GROUP = 'sales'
const OBJECTS = '/index.php/apps/openregister/api/objects/pipelinq/ticket'
const ADMIN = {
	username: process.env.ADMIN_USER ?? process.env.NC_ADMIN_USER ?? 'admin',
	password: process.env.ADMIN_PASSWORD ?? process.env.NC_ADMIN_PASS ?? 'admin',
}
const OCS = { 'OCS-APIRequest': 'true', Accept: 'application/json' }

interface Handler {
	id: string
	displayName: string
	password: string
}

const SANNE: Handler = { id: `e2e-thh-sanne-${RUN}`, displayName: `Sanne de Vries ${RUN}`, password: `Thh-${RUN}-Sanne-42!` }
const JORIS: Handler = { id: `e2e-thh-joris-${RUN}`, displayName: `Joris Bakker ${RUN}`, password: `Thh-${RUN}-Joris-42!` }

test.use({ storageState: { cookies: [], origins: [] } })

/**
 * An API context that acts as one account.
 *
 * @param username The account.
 * @param password Its password.
 */
async function as(username: string, password: string): Promise<APIRequestContext> {
	return request.newContext({ baseURL: BASE_URL, httpCredentials: { username, password }, extraHTTPHeaders: OCS })
}

/**
 * Make a handler in the sales group.
 *
 * @param admin The admin context.
 * @param handler The handler.
 */
async function provision(admin: APIRequestContext, handler: Handler): Promise<void> {
	// The group may already exist; creating it again is refused and harmless.
	await admin.post('/ocs/v2.php/cloud/groups', { form: { groupid: GROUP } })
	const made = await admin.post('/ocs/v2.php/cloud/users', {
		form: { userid: handler.id, password: handler.password, displayName: handler.displayName },
	})
	expect(made.ok(), `creating ${handler.id}: ${made.status()}`).toBeTruthy()
	const joined = await admin.post(`/ocs/v2.php/cloud/users/${handler.id}/groups`, { form: { groupid: GROUP } })
	expect(joined.ok(), `adding ${handler.id} to ${GROUP}: ${joined.status()}`).toBeTruthy()
}

/**
 * Change a ticket as one handler, the way the ticket form saves it.
 *
 * @param api The handler's context.
 * @param id The ticket.
 * @param patch The fields to change.
 */
async function change(api: APIRequestContext, id: string, patch: Record<string, unknown>): Promise<void> {
	const current = await api.get(`${OBJECTS}/${id}`)
	expect(current.ok(), `reading ticket ${id}: ${current.status()}`).toBeTruthy()
	const body = { ...(await current.json()), ...patch, id }
	delete body['@self']
	const saved = await api.put(`${OBJECTS}/${id}`, { data: body })
	expect(saved.ok(), `saving ticket ${id}: ${saved.status()} ${(await saved.text()).slice(0, 300)}`).toBeTruthy()
}

test('a handover and what came after it read as sentences on the History tab', async ({ browser }) => {
	test.setTimeout(180_000)
	const admin = await as(ADMIN.username, ADMIN.password)
	let ticketId = ''
	try {
		await provision(admin, SANNE)
		await provision(admin, JORIS)

		const created = await admin.post(OBJECTS, {
			data: {
				title: `E2E-THH-${RUN} permit question`,
				ticketType: 'request',
				status: 'new',
				channel: 'phone',
				assignee: SANNE.id,
			},
		})
		expect(created.ok(), `creating the ticket: ${created.status()}`).toBeTruthy()
		const ticket = await created.json()
		ticketId = String(ticket.id ?? ticket['@self']?.id ?? ticket.uuid)

		const sanne = await as(SANNE.id, SANNE.password)
		await change(sanne, ticketId, { assignee: JORIS.id })
		await sanne.dispose()

		const joris = await as(JORIS.id, JORIS.password)
		await change(joris, ticketId, { status: 'resolved' })
		await joris.dispose()

		const context = await browser.newContext({ baseURL: BASE_URL, storageState: { cookies: [], origins: [] } })
		const page = await context.newPage()
		await login(page, SANNE.id, SANNE.password)
		await page.goto(appUrl(`/tickets/${ticketId}`))
		await dismissSupportDialog(page)
		await dismissWalkthrough(page)
		await page.getByRole('tab', { name: /^(History|Geschiedenis)$/ }).click()

		const handover = new RegExp(`(Passed to|Overgedragen aan) ${JORIS.displayName} (by|door) ${SANNE.displayName}`)
		const resolved = new RegExp(`(Resolved|Opgelost) (by|door) ${JORIS.displayName}`)
		await expect(page.getByText(handover)).toBeVisible({ timeout: 30_000 })
		await expect(page.getByText(resolved)).toBeVisible()

		// Newest first: the resolution is listed above the handover.
		const handoverBox = await page.getByText(handover).boundingBox()
		const resolvedBox = await page.getByText(resolved).boundingBox()
		expect(resolvedBox && handoverBox && resolvedBox.y < handoverBox.y).toBeTruthy()
		await context.close()
	} finally {
		if (ticketId !== '') {
			await admin.delete(`${OBJECTS}/${ticketId}`).catch(() => undefined)
		}
		for (const handler of [SANNE, JORIS]) {
			await admin.delete(`/ocs/v2.php/cloud/users/${handler.id}`).catch(() => undefined)
		}
		await admin.dispose()
	}
})
