/*
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Lock and unlock a client, a finished lead and a finished ticket
 * (platform-record-lock tasks 2.1 to 2.4, 3.2).
 *
 *   @e2e platform-record-lock::a-sales-manager-locks-a-won-lead
 *   @e2e platform-record-lock::an-open-ticket-cannot-be-locked
 *   @e2e platform-record-lock::an-edit-to-a-locked-client-is-refused
 *   @e2e platform-record-lock::a-team-lead-unlocks-a-client-to-correct-an-address
 *   @e2e platform-record-lock::a-deleted-locked-client-stays
 *
 * Lock is the `lock-record` header action (POST .../freeze through
 * OpenRegister, with a confirm), Unlock the `unlock-record` one (DELETE on the
 * same address), and the banner is the page's `*-locked` banner widget, read
 * from `@self.frozen`. The refusal under an edit is OpenRegister's; the test
 * reads the locker's name from the record's own `@self.frozen.by`, so it
 * asserts the name the server stored, whatever the display name is.
 *
 * The delete case waits on OpenRegister refusing deletion of a frozen object
 * (task 3.1's issue), so it is `test.fixme` until that ships.
 *
 * @spec openspec/changes/platform-record-lock/specs/record-locking/spec.md#requirement-a-user-can-lock-a-client-a-finished-lead-or-a-finished-ticket-req-prl-001
 */
import type { APIRequestContext, Page } from '@playwright/test'

import { expect, request, test } from '@playwright/test'
import { resolveBaseUrl } from './base-url.ts'
import {
	clickHeaderAction,
	gotoAppRoute,
	openActionsOverflow,
} from './helpers/pipelinq.ts'

const BASE_URL = resolveBaseUrl()
const RUN = Date.now().toString(36)
const OBJECTS = '/index.php/apps/openregister/api/objects/pipelinq'
const ADMIN = {
	username: process.env.ADMIN_USER ?? process.env.NC_ADMIN_USER ?? 'admin',
	password: process.env.ADMIN_PASSWORD ?? process.env.NC_ADMIN_PASS ?? 'admin',
}
const OCS = { 'OCS-APIRequest': 'true', Accept: 'application/json' }
const OLD_PHONE = '+31201234567'
const NEW_PHONE = '+31207654321'

let api: APIRequestContext
const made: Array<{ schema: string; id: string }> = []

/**
 * Create one object and remember it for clean-up.
 *
 * @param schema The pipelinq schema.
 * @param data The fields.
 */
async function seed(schema: string, data: Record<string, unknown>): Promise<string> {
	const res = await api.post(`${OBJECTS}/${schema}`, { data })
	expect(
		res.ok(),
		`seeding ${schema}: ${res.status()} ${(await res.text()).slice(0, 300)}`,
	).toBeTruthy()
	const body = await res.json()
	const id = String(body.id ?? body['@self']?.id ?? body.uuid)
	made.push({ schema, id })
	return id
}

/**
 * Read one object.
 *
 * @param schema The pipelinq schema.
 * @param id The object.
 */
async function read(schema: string, id: string): Promise<Record<string, unknown>> {
	const res = await api.get(`${OBJECTS}/${schema}/${id}`)
	expect(res.ok(), `reading ${schema} ${id}: ${res.status()}`).toBeTruthy()
	return await res.json()
}

/**
 * Change an object the way the edit form saves it: the whole object back.
 * A ticket starts in its lifecycle's initial state, so a later status is set
 * here rather than on create.
 *
 * @param schema The pipelinq schema.
 * @param id The object.
 * @param patch The fields to change.
 */
async function update(
	schema: string,
	id: string,
	patch: Record<string, unknown>,
): Promise<void> {
	const body: Record<string, unknown> = {
		...(await read(schema, id)),
		...patch,
		id,
	}
	delete body['@self']
	const res = await api.put(`${OBJECTS}/${schema}/${id}`, { data: body })
	expect(
		res.ok(),
		`updating ${schema} ${id}: ${res.status()} ${(await res.text()).slice(0, 300)}`,
	).toBeTruthy()
}

/**
 * Open the page's own Edit, whether it sits beside the header or in the Actions menu.
 *
 * @param page The page, on a detail page.
 */
async function openEdit(page: Page): Promise<void> {
	const button = page.getByTestId('cn-detail-page-edit')
	if (await button.isVisible()) {
		await button.click()
		return
	}
	await clickHeaderAction(page, 'Edit')
}

/**
 * The `@self.frozen` marker of an object, or null.
 *
 * @param schema The pipelinq schema.
 * @param id The object.
 */
async function frozen(
	schema: string,
	id: string,
): Promise<Record<string, unknown> | null> {
	const body = await read(schema, id)
	const marker = (body['@self'] as Record<string, unknown> | undefined)?.frozen
	return marker && typeof marker === 'object'
		? (marker as Record<string, unknown>)
		: null
}

/**
 * Lock through the Actions menu and confirm.
 *
 * @param page The page, on a detail page.
 * @param confirmTitle The confirm dialog's title.
 */
async function lockFromMenu(page: Page, confirmTitle: string): Promise<void> {
	await clickHeaderAction(page, 'Lock')
	const confirm = page.getByRole('dialog', { name: confirmTitle })
	await expect(confirm).toBeVisible()
	await confirm.getByTestId('cn-confirm-dialog-confirm').click()
}

/**
 * The entries of the open Actions menu, matched page-wide (NcActions teleports it).
 *
 * @param page The page.
 * @param label The entry.
 */
function menuEntry(page: Page, label: string) {
	return page.getByRole('menuitem', { name: label, exact: true })
}

/**
 * The lock banner on a detail page.
 *
 * @param page The page.
 */
function lockBanner(page: Page) {
	return page.getByTestId('cn-banner-widget-text').filter({ hasText: 'Locked by' })
}

test.describe('Lock and unlock a record', () => {
	test.describe.configure({ mode: 'serial' })
	test.setTimeout(150_000)

	let pipelineId = ''

	test.beforeAll(async () => {
		api = await request.newContext({
			baseURL: BASE_URL,
			httpCredentials: ADMIN,
			extraHTTPHeaders: OCS,
		})
		const pipelines = await api.get(`${OBJECTS}/pipeline`, {
			params: { _limit: 1 },
		})
		if (pipelines.ok()) {
			const first = ((await pipelines.json()).results ?? [])[0]
			pipelineId = first ? String(first.id ?? first['@self']?.id) : ''
		}
	})

	test.afterAll(async () => {
		for (const { schema, id } of made.reverse()) {
			await api.delete(`${OBJECTS}/${schema}/${id}/freeze`)
			await api.delete(`${OBJECTS}/${schema}/${id}`)
		}
		await api.dispose()
	})

	test('a locked client offers Unlock; unlocked, Edit saves a new phone number', async ({
		page,
	}) => {
		const clientId = await seed('client', {
			name: `E2E-PRL-${RUN} client`,
			type: 'organization',
			phone: OLD_PHONE,
		})

		await gotoAppRoute(page, `/clients/${clientId}`)
		await lockFromMenu(page, 'Lock this client?')
		await expect(lockBanner(page)).toBeVisible({ timeout: 20_000 })
		expect(await frozen('client', clientId)).not.toBeNull()

		await openActionsOverflow(page)
		await expect(menuEntry(page, 'Unlock')).toBeVisible()
		await expect(menuEntry(page, 'Lock')).toHaveCount(0)
		await menuEntry(page, 'Unlock').click()

		await expect(lockBanner(page)).toHaveCount(0, { timeout: 20_000 })
		expect(await frozen('client', clientId)).toBeNull()

		await openEdit(page)
		const form = page.locator('[data-testid-modal="cn-form-dialog"]')
		await expect(form).toBeVisible()
		await form.locator('[data-cn-field="phone"] input').fill(NEW_PHONE)
		await form.getByRole('button', { name: 'Save', exact: true }).click()
		await expect(form).toBeHidden({ timeout: 20_000 })
		expect((await read('client', clientId)).phone).toBe(NEW_PHONE)
	})

	test('a won lead shows who locked it; an unlocked won lead shows no banner', async ({
		page,
	}) => {
		test.skip(
			pipelineId === '',
			'No pipeline on this instance to put a lead on.',
		)

		const leadIds: string[] = []
		for (const suffix of ['locked', 'open']) {
			const id = await seed('lead', {
				title: `E2E-PRL-${RUN} lead ${suffix}`,
				pipeline: pipelineId,
				status: 'open',
			})
			const won = await api.post(
				`/index.php/apps/openregister/api/objects/${id}/transition`,
				{ data: { action: 'win' } },
			)
			expect(
				won.ok(),
				`winning lead ${suffix}: ${won.status()} ${(await won.text()).slice(0, 300)}`,
			).toBeTruthy()
			leadIds.push(id)
		}

		await gotoAppRoute(page, `/leads/${leadIds[0]}`)
		await lockFromMenu(page, 'Lock this lead?')
		await expect(lockBanner(page)).toBeVisible({ timeout: 20_000 })
		const marker = await frozen('lead', leadIds[0])
		expect(marker, 'the lead carries no @self.frozen after Lock').not.toBeNull()
		await expect(lockBanner(page)).toContainText(String(marker?.by))
		await openActionsOverflow(page)
		await expect(menuEntry(page, 'Unlock')).toBeVisible()
		await page.keyboard.press('Escape')

		await gotoAppRoute(page, `/leads/${leadIds[1]}`)
		await expect(page.getByTestId('cn-detail-page')).toBeVisible()
		await expect(lockBanner(page)).toHaveCount(0)
	})

	test('an open ticket offers no Lock', async ({ page }) => {
		const ticketId = await seed('ticket', {
			title: `E2E-PRL-${RUN} open ticket`,
			ticketType: 'request',
			status: 'new',
			channel: 'phone',
		})
		await update('ticket', ticketId, { status: 'in_progress' })

		await gotoAppRoute(page, `/tickets/${ticketId}`)
		await openActionsOverflow(page)
		// The menu is open (it holds at least one entry), and Lock is not among them.
		await expect(page.getByRole('menuitem').first()).toBeVisible()
		await expect(menuEntry(page, 'Lock')).toHaveCount(0)
	})

	test('an edit to a locked ticket is refused, names who locked it, and changes nothing', async ({
		page,
	}) => {
		const oldTitle = `E2E-PRL-${RUN} resolved ticket`
		const ticketId = await seed('ticket', {
			title: oldTitle,
			ticketType: 'request',
			status: 'new',
			channel: 'phone',
		})
		await update('ticket', ticketId, { status: 'resolved' })
		const locked = await api.post(`${OBJECTS}/ticket/${ticketId}/freeze`)
		expect(
			locked.ok(),
			`locking the ticket: ${locked.status()} ${(await locked.text()).slice(0, 300)}`,
		).toBeTruthy()
		const marker = await frozen('ticket', ticketId)
		expect(
			marker,
			'the ticket carries no @self.frozen after freeze',
		).not.toBeNull()

		await gotoAppRoute(page, `/tickets/${ticketId}`)
		await expect(lockBanner(page)).toBeVisible({ timeout: 20_000 })
		await openEdit(page)
		const form = page.locator('[data-testid-modal="cn-form-dialog"]')
		await expect(form).toBeVisible()
		await form
			.locator('[data-cn-field="title"] input')
			.fill(`${oldTitle} changed`)
		await form.getByRole('button', { name: 'Save', exact: true }).click()

		await expect(form.getByTestId('cn-form-dialog-error')).toBeVisible({
			timeout: 20_000,
		})
		await expect(form.getByTestId('cn-form-dialog-error')).toContainText(
			String(marker?.by),
		)

		await page.reload()
		await expect(page.getByTestId('cn-detail-page-header')).toContainText(
			oldTitle,
		)
		expect((await read('ticket', ticketId)).title).toBe(oldTitle)
	})

	test.fixme('a locked client cannot be deleted from the Clients list', async ({
		page,
	}) => {
		// Waits on OpenRegister refusing deletion of a frozen object (guard on
		// ObjectDeletingEvent, the issue opened under task 3.1). Until it ships,
		// OpenRegister deletes a frozen client and this test would fail for that
		// reason only.
		const name = `E2E-PRL-${RUN} locked client to delete`
		const clientId = await seed('client', { name, type: 'organization' })
		const locked = await api.post(`${OBJECTS}/client/${clientId}/freeze`)
		expect(locked.ok()).toBeTruthy()

		await gotoAppRoute(page, '/clients')
		const row = page.getByRole('row').filter({ hasText: name })
		await expect(row).toBeVisible({ timeout: 20_000 })
		await row.getByRole('button', { name: /actions/i }).click()
		await page.getByRole('menuitem', { name: 'Delete' }).click()
		await page.getByTestId('cn-confirm-dialog-confirm').click()

		await expect(page.getByText(/locked/i).first()).toBeVisible({
			timeout: 20_000,
		})
		await page.reload()
		await expect(page.getByRole('row').filter({ hasText: name })).toBeVisible({
			timeout: 20_000,
		})
		expect((await api.get(`${OBJECTS}/client/${clientId}`)).ok()).toBeTruthy()
	})
})
