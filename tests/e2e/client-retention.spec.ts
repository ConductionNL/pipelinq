/*
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 * SPDX-License-Identifier: EUPL-1.2
 *
 * A records officer approves a destruction list of clients on Retention
 * review (platform-client-retention task 3.2): the page lists the seeded
 * clients with their linked tickets and leads, Approve asks for confirmation,
 * and the approval goes to OpenRegister's archival route.
 *
 *   @e2e platform-client-retention::a-records-officer-approves-a-list
 *
 * Seeding: three inactive clients whose relationship ended in 2020, so their
 * two-year term has passed, and a destruction list drawn up for them through
 * OpenRegister (`POST /api/archival/destruction-lists`). OpenRegister judges
 * eligibility itself; when it refuses (no destruction-list register, or the
 * retention recalculation of design D3 is not on this instance) the test is
 * skipped with OpenRegister's reason rather than passing on nothing.
 *
 * What happens right after Approve is asserted positively: the confirm
 * dialog, the toast and the list's status (UI and API). Destruction itself is
 * done by OpenRegister's execution job, which a browser cannot start. Set
 * E2E_DESTRUCTION_JOB_WAIT_MS on an instance where that job runs on cron to
 * also wait for the clients to be gone; without it that step is skipped.
 *
 * @spec openspec/changes/platform-client-retention/specs/client-retention/spec.md#requirement-a-records-officer-approves-each-destruction-req-pcr-003
 */
import type { APIRequestContext } from '@playwright/test'

import { expect, request, test } from '@playwright/test'
import { resolveBaseUrl } from './base-url.ts'
import { gotoAppRoute } from './helpers/pipelinq.ts'

const BASE_URL = resolveBaseUrl()
const RUN = Date.now().toString(36)
const CLIENTS = '/index.php/apps/openregister/api/objects/pipelinq/client'
const LISTS = '/index.php/apps/openregister/api/archival/destruction-lists'
const JOB_WAIT_MS = Number(process.env.E2E_DESTRUCTION_JOB_WAIT_MS ?? 0)
const ADMIN = {
	username: process.env.ADMIN_USER ?? process.env.NC_ADMIN_USER ?? 'admin',
	password: process.env.ADMIN_PASSWORD ?? process.env.NC_ADMIN_PASS ?? 'admin',
}
const OCS = { 'OCS-APIRequest': 'true', Accept: 'application/json' }

test.describe('Retention review: approve a destruction list of clients', () => {
	test.describe.configure({ mode: 'serial' })
	test.setTimeout(180_000 + JOB_WAIT_MS)

	let api: APIRequestContext
	const clientIds: string[] = []
	const names: string[] = []
	let listId = ''
	let skipReason = ''

	test.beforeAll(async () => {
		api = await request.newContext({
			baseURL: BASE_URL,
			httpCredentials: ADMIN,
			extraHTTPHeaders: OCS,
		})

		for (const suffix of ['A', 'B', 'C']) {
			const name = `E2E-PCR-${RUN} ${suffix}`
			const made = await api.post(CLIENTS, {
				data: {
					name,
					type: 'organization',
					accountStatus: 'inactive',
					relationshipEndedAt: '2020-01-15T00:00:00+00:00',
				},
			})
			expect(
				made.ok(),
				`seeding client ${suffix}: ${made.status()} ${(await made.text()).slice(0, 300)}`,
			).toBeTruthy()
			const body = await made.json()
			clientIds.push(String(body.id ?? body['@self']?.id ?? body.uuid))
			names.push(name)
		}

		const drawn = await api.post(LISTS, { data: { objects: clientIds } })
		if (
			drawn.status() === 409
			|| drawn.status() === 422
			|| drawn.status() === 404
		) {
			skipReason = `OpenRegister drew up no destruction list (${drawn.status()}): ${(await drawn.text()).slice(0, 300)}`
			return
		}
		expect(
			drawn.ok(),
			`drawing up the list: ${drawn.status()} ${(await drawn.text()).slice(0, 300)}`,
		).toBeTruthy()
		const list = await drawn.json()
		listId = String(list.uuid ?? list.id)
		if (Number(list.entryCount ?? clientIds.length) < clientIds.length) {
			skipReason = `OpenRegister took ${list.entryCount} of ${clientIds.length} clients: ${JSON.stringify(list.refused ?? []).slice(0, 300)}`
		}
	})

	test.afterAll(async () => {
		for (const id of clientIds) {
			await api.delete(`${CLIENTS}/${id}`)
		}
		await api.dispose()
	})

	test('the list shows the clients, Approve confirms, and OpenRegister records the approval', async ({
		page,
	}) => {
		test.skip(skipReason !== '', skipReason)

		await gotoAppRoute(page, '/retention-review')
		await expect(
			page.getByRole('heading', { name: 'Retention review' }),
		).toBeVisible()

		const list = page.locator(
			`section.retention-review__list[data-list="${listId}"]`,
		)
		await expect(list).toBeVisible({ timeout: 30_000 })
		for (const [index, id] of clientIds.entries()) {
			const row = list.locator(`tr[data-entry="${id}"]`)
			await expect(row).toContainText(names[index])
			await expect(row).toContainText('Client')
			// The linked tickets and leads counts are there to read: none for a fresh client.
			await expect(row.locator('td').nth(3)).toHaveText('0')
			await expect(row.locator('td').nth(4)).toHaveText('0')
		}

		await list.getByRole('button', { name: 'Approve', exact: true }).click()
		const confirm = page.getByRole('dialog', {
			name: 'Approve destruction list',
		})
		await expect(confirm).toBeVisible()
		await confirm.getByRole('button', { name: 'Approve', exact: true }).click()
		await expect(page.getByText('Destruction list approved')).toBeVisible({
			timeout: 20_000,
		})
		await expect(confirm).toBeHidden()

		// No longer open: the Approve and Reject buttons are gone from this list.
		await expect(
			list.getByRole('button', { name: 'Approve', exact: true }),
		).toHaveCount(0, { timeout: 20_000 })

		const after = await api.get(`${LISTS}/${listId}`)
		expect(after.ok(), `reading the list: ${after.status()}`).toBeTruthy()
		expect(['approved', 'awaiting_second_approval', 'completed']).toContain(
			(await after.json()).status,
		)
	})

	test('after the execution job, the approved clients are gone', async () => {
		test.skip(skipReason !== '', skipReason)
		test.skip(
			JOB_WAIT_MS <= 0,
			"Destruction runs in OpenRegister's execution job; set E2E_DESTRUCTION_JOB_WAIT_MS where that job runs on cron.",
		)

		await expect
			.poll(
				async () => {
					const statuses = await Promise.all(
						clientIds.map(async (id) =>
							(await api.get(`${CLIENTS}/${id}`)).status(),
						),
					)
					return statuses.every((status) => status === 404)
				},
				{ timeout: JOB_WAIT_MS, intervals: [10_000] },
			)
			.toBe(true)
	})
})
