/*
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Paste or scan on the Contacts and Clients lists (clients-contact-capture
 * task 3.2): a pasted email signature becomes proposed fields, Use these
 * fields opens the page's own create dialog prefilled, and nothing is saved
 * before Save.
 *
 *   @e2e clients-contact-capture::a-sales-rep-pastes-an-email-signature
 *   @e2e clients-contact-capture::no-recognition-provider
 *
 * The signature carries a run stamp in the name, so "nothing was saved" is a
 * positive read: a search of the register for the stamp finds no contact and
 * no client after the dialog was used and then cancelled. Whether hermiq
 * placed the name line or left it unassigned depends on the instance, so the
 * name is placed through the field picker when it comes back unassigned.
 *
 * The photo path (REQ-CCC-002 "A sales rep scans a card at a fair") needs an
 * OCR provider and a camera; it is covered by PHPUnit (ContactPhotoReaderTest)
 * and left out here. Only the provider-less wording is asserted, and only on
 * an instance that reports no provider.
 *
 * @spec openspec/changes/clients-contact-capture/specs/client-contact-capture/spec.md#requirement-pasted-text-becomes-proposed-contact-fields-req-ccc-001
 */
import type { APIRequestContext, Page } from '@playwright/test'

import { expect, request, test } from '@playwright/test'
import { resolveBaseUrl } from './base-url.ts'
import { clickHeaderAction, gotoAppRoute } from './helpers/pipelinq.ts'

const BASE_URL = resolveBaseUrl()
const RUN = Date.now().toString(36)
const NAME = `Marieke Capture ${RUN}`
const EMAIL = `marieke.${RUN}@example.org`
const SIGNATURE = [
	'Met vriendelijke groet,',
	NAME,
	'Accountmanager',
	`E: ${EMAIL}`,
	'M: 06 1234 5678',
].join('\n')
const ADMIN = {
	username: process.env.ADMIN_USER ?? process.env.NC_ADMIN_USER ?? 'admin',
	password: process.env.ADMIN_PASSWORD ?? process.env.NC_ADMIN_PASS ?? 'admin',
}
const OCS = { 'OCS-APIRequest': 'true', Accept: 'application/json' }

/**
 * The number of records of one schema whose search matches the run stamp.
 *
 * @param api The admin context.
 * @param schema `contact` or `client`.
 */
async function stampedCount(
	api: APIRequestContext,
	schema: string,
): Promise<number> {
	const res = await api.get(
		`/index.php/apps/openregister/api/objects/pipelinq/${schema}`,
		{
			params: { _search: RUN, _limit: 50 },
		},
	)
	expect(res.ok(), `searching ${schema}: ${res.status()}`).toBeTruthy()
	const body = await res.json()
	return Number(body.total ?? (body.results ?? []).length)
}

/**
 * Open Paste or scan, paste the signature and read it.
 *
 * @param page The page, on a list that offers Paste or scan.
 */
async function pasteSignature(page: Page): Promise<ReturnType<Page['getByTestId']>> {
	await clickHeaderAction(page, 'Paste or scan')
	const modal = page.getByTestId('contact-capture-modal')
	await expect(modal).toBeVisible()
	await modal
		.getByTestId('contact-capture-text')
		.locator('textarea')
		.fill(SIGNATURE)
	await modal.getByTestId('contact-capture-read').click()
	await expect(
		modal.getByTestId('contact-capture-field-email').locator('input'),
	).toHaveValue(EMAIL, { timeout: 20_000 })
	return modal
}

/**
 * Make sure the name ends up in the Name field: hermiq places it, and without
 * hermiq it is an unassigned line the user places with the field picker.
 *
 * @param page The page.
 * @param modal The Paste or scan dialog.
 */
async function placeName(
	page: Page,
	modal: ReturnType<Page['getByTestId']>,
): Promise<void> {
	const nameInput = modal
		.getByTestId('contact-capture-field-name')
		.locator('input')
	if ((await nameInput.inputValue()) === NAME) {
		return
	}
	const line = modal.getByTestId('contact-capture-line').filter({ hasText: NAME })
	await expect(
		line,
		'the name line is neither placed nor listed as unassigned',
	).toBeVisible()
	await line.locator('input').click()
	await page.getByRole('option', { name: 'Name', exact: true }).click()
	await expect(nameInput).toHaveValue(NAME)
}

test.describe('Paste or scan turns a signature into a prefilled create form', () => {
	test.setTimeout(120_000)

	let api: APIRequestContext

	test.beforeAll(async () => {
		api = await request.newContext({
			baseURL: BASE_URL,
			httpCredentials: ADMIN,
			extraHTTPHeaders: OCS,
		})
	})

	test.afterAll(async () => {
		// Nothing should have been saved; remove it anyway if a run did save.
		for (const schema of ['contact', 'client']) {
			const res = await api.get(
				`/index.php/apps/openregister/api/objects/pipelinq/${schema}`,
				{ params: { _search: RUN, _limit: 50 } },
			)
			if (res.ok()) {
				for (const row of ((await res.json()).results ?? []) as Array<
					Record<string, unknown>
				>) {
					await api.delete(
						`/index.php/apps/openregister/api/objects/pipelinq/${schema}/${String(row.id)}`,
					)
				}
			}
		}
		await api.dispose()
	})

	test('on Contacts: the fields land in the New contact form, and nothing is saved before Save', async ({
		page,
	}) => {
		await gotoAppRoute(page, '/contacts')
		const modal = await pasteSignature(page)

		// The phone comes back in international format.
		await expect(
			modal.getByTestId('contact-capture-field-phone').locator('input'),
		).toHaveValue(/^\+31\s?6/)
		await placeName(page, modal)

		await modal.getByTestId('contact-capture-use').click()
		const create = page.getByTestId('contact-create-dialog')
		await expect(create).toBeVisible({ timeout: 15_000 })
		await expect(create.getByLabel('Name', { exact: true })).toHaveValue(NAME)
		await expect(create.getByLabel('Email', { exact: true })).toHaveValue(EMAIL)
		await expect(create.getByLabel('Phone', { exact: true })).toHaveValue(
			/6\s?1234\s?5678|612345678/,
		)

		// Nothing is in the register until Save.
		expect(await stampedCount(api, 'contact')).toBe(0)
		await page.keyboard.press('Escape')
		await expect(create).toBeHidden()
		expect(await stampedCount(api, 'contact')).toBe(0)
	})

	test('on Clients: Use these fields opens New client prefilled, and nothing is saved before Save', async ({
		page,
	}) => {
		await gotoAppRoute(page, '/clients')
		const modal = await pasteSignature(page)
		await placeName(page, modal)

		await modal.getByTestId('contact-capture-use').click()
		const create = page.getByTestId('client-create-dialog')
		await expect(create).toBeVisible({ timeout: 15_000 })
		// ClientForm draws Name, Email and Phone once per layout; the visible one counts.
		await expect(create.getByLabel('Name', { exact: true }).first()).toHaveValue(
			NAME,
		)
		await expect(
			create.getByLabel('Email', { exact: true }).first(),
		).toHaveValue(EMAIL)
		await expect(
			create.getByLabel('Phone', { exact: true }).first(),
		).toHaveValue(/6\s?1234\s?5678|612345678/)

		expect(await stampedCount(api, 'client')).toBe(0)
		await create.getByTestId('client-create-cancel').click()
		await expect(create).toBeHidden()
		expect(await stampedCount(api, 'client')).toBe(0)
	})

	test('without an OCR provider the dialog says photos cannot be read, and paste stays', async ({
		page,
	}) => {
		const status = await api.get(
			'/index.php/apps/pipelinq/api/contact-capture/status',
		)
		expect(status.ok(), `capture status: ${status.status()}`).toBeTruthy()
		test.skip(
			(await status.json()).photo === true,
			'This instance has a recognition provider; the provider-less wording does not show.',
		)

		await gotoAppRoute(page, '/contacts')
		await clickHeaderAction(page, 'Paste or scan')
		const modal = page.getByTestId('contact-capture-modal')
		await expect(modal.getByTestId('contact-capture-no-photo')).toBeVisible()
		await expect(modal.getByTestId('contact-capture-photo')).toHaveCount(0)
		await expect(
			modal.getByTestId('contact-capture-text').locator('textarea'),
		).toBeEditable()
	})
})
