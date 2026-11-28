/*
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Quick actions on My work (my-work-roadmap block 2, REQ-MW-100).
 *
 *   @e2e my-work::a-ticket-created-from-my-work-lands-on-my-work
 *   @e2e my-work::a-new-contact-does-not-change-the-list
 *   @e2e my-work::my-work-has-no-lead-button
 *
 * New ticket opens the New request dialog with the agent as handler and Open
 * after creating unticked; after Create the dialog closes, the page stays on
 * My work and the lists read again, so the ticket shows without a reload.
 * New contact opens the contact dialog, which opens the new contact.
 *
 * Written from the change's scenarios; not yet run (it needs a seeded instance).
 *
 * @spec openspec/changes/archive/2026-11-25-my-work-roadmap/specs/my-work/spec.md#requirement-quick-actions-on-my-work-req-mw-100
 */
import type { APIRequestContext, Page } from '@playwright/test'

import { expect, request, test } from '@playwright/test'
import { resolveBaseUrl } from '../base-url.ts'
import { gotoAppRoute } from '../helpers/pipelinq.ts'

const RUN = Date.now().toString(36)
const OBJECTS = '/index.php/apps/openregister/api/objects/pipelinq'
const ADMIN = {
	username: process.env.ADMIN_USER ?? process.env.NC_ADMIN_USER ?? 'admin',
	password: process.env.ADMIN_PASSWORD ?? process.env.NC_ADMIN_PASS ?? 'admin',
}

let api: APIRequestContext
const made: Array<{ schema: string; id: string }> = []

/**
 * The My work header button with this label.
 *
 * @param page The page, on My work.
 * @param label The button's label.
 */
function headerButton(page: Page, label: string) {
	return page
		.getByTestId('cn-dashboard-page-header-actions')
		.getByRole('button', { name: label })
}

/**
 * The objects of a schema whose field holds this run's stamp.
 *
 * @param schema The pipelinq schema.
 * @param field The field to search.
 */
async function stamped(
	schema: string,
	field: string,
): Promise<Array<Record<string, unknown>>> {
	const res = await api.get(`${OBJECTS}/${schema}`, {
		params: { _search: RUN, _limit: 20 },
	})
	expect(res.ok(), `listing ${schema}: ${res.status()}`).toBeTruthy()
	const rows = ((await res.json()).results ?? []) as Array<Record<string, unknown>>
	return rows.filter((row) => String(row[field] ?? '').includes(RUN))
}

test.describe('My work quick actions', () => {
	test.setTimeout(120_000)

	test.beforeAll(async () => {
		api = await request.newContext({
			baseURL: resolveBaseUrl(),
			httpCredentials: ADMIN,
			extraHTTPHeaders: {
				'OCS-APIRequest': 'true',
				Accept: 'application/json',
			},
		})
	})

	test.afterAll(async () => {
		for (const { schema, id } of made.reverse()) {
			await api.delete(`${OBJECTS}/${schema}/${id}`)
		}
		await api.dispose()
	})

	test('the header offers New ticket and New contact, and no New lead', async ({
		page,
	}) => {
		await gotoAppRoute(page, '/my-work')
		await expect(headerButton(page, 'New ticket')).toBeVisible({
			timeout: 20_000,
		})
		await expect(headerButton(page, 'New contact')).toBeVisible()
		await expect(headerButton(page, 'New lead')).toHaveCount(0)
	})

	test("a ticket created from My work is the agent's and shows on My work without a reload", async ({
		page,
	}) => {
		const subject = `E2E-MW-${RUN} ticket`
		await gotoAppRoute(page, '/my-work')
		const urlBefore = page.url()

		await headerButton(page, 'New ticket').click()
		const dialog = page.getByTestId('request-create-dialog')
		await expect(dialog).toBeVisible({ timeout: 15_000 })
		await expect(
			dialog.getByTestId('request-create-open-after').locator('input'),
		).not.toBeChecked()

		await dialog.getByLabel('Subject').fill(subject)
		await dialog
			.getByLabel('What does the customer ask?')
			.fill('Asked at the desk from My work')
		await dialog.getByTestId('request-create-save').click()
		await expect(dialog).toBeHidden({ timeout: 20_000 })

		expect(page.url()).toBe(urlBefore)
		await expect(
			page.locator('#content-vue').getByText(subject).first(),
		).toBeVisible({
			timeout: 20_000,
		})

		const [ticket] = await stamped('ticket', 'title')
		expect(ticket, 'the ticket is stored').toBeTruthy()
		made.push({
			schema: 'ticket',
			id: String(ticket.id ?? (ticket['@self'] as any)?.id),
		})
		expect(ticket.assignee).toBe(ADMIN.username)
	})

	test('a new contact opens the contact and leaves My work as it was', async ({
		page,
	}) => {
		const name = `E2E-MW-${RUN} contact`
		await gotoAppRoute(page, '/my-work')

		await headerButton(page, 'New contact').click()
		const dialog = page.getByTestId('contact-create-dialog')
		await expect(dialog).toBeVisible({ timeout: 15_000 })
		await dialog.getByLabel('Name', { exact: true }).fill(name)
		await dialog
			.getByRole('button', { name: /^(Save|Create)/ })
			.first()
			.click()

		await expect(page).toHaveURL(/\/contacts\/[^/]+$/, { timeout: 30_000 })
		await expect(page.getByText(name).first()).toBeVisible({ timeout: 20_000 })
		const [contact] = await stamped('contact', 'name')
		if (contact) {
			made.push({
				schema: 'contact',
				id: String(contact.id ?? (contact['@self'] as any)?.id),
			})
		}
	})
})
