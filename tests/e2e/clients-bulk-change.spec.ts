/*
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Change a field on many clients at once, then undo it from Bulk changes.
 *
 * The journey runs through the UI end to end: three clients are ticked on
 * /clients, Change a field sets Industry to Healthcare through OpenRegister's
 * bulk job (preview, then commit), the table shows the new value, the job is
 * listed on /bulk-changes with its field and count, and Undo writes the old
 * values back. What the UI shows is cross-checked against the register, so a
 * table that merely re-renders a stale cache cannot pass.
 *
 * Base URL and instance come from the environment (tests/e2e/base-url.ts);
 * the run's own clients are name-stamped and deleted afterwards.
 *
 * Covers, from openspec/changes/clients-bulk-edit-and-undo:
 *   task 2.2 "Confirm commits" (sets industry on three clients, reads it in the table)
 *   task 3.1 "Bulk changes page" (the job is listed with its property and count)
 *   task 3.2 "Undo" (the old industry values are read back)
 *
 * @spec openspec/changes/clients-bulk-edit-and-undo/specs/client-bulk-changes/spec.md
 */
import type { Page } from '@playwright/test'

import { expect, test } from '@playwright/test'
import { gotoAppRoute, openIndexSearch } from './helpers/pipelinq.ts'

/** Distinctive enough that finding it proves the read, not a coincidence. */
const STAMP = `E2E-BULK-${Date.now()}`

/** The old value each seeded client starts with. */
const OLD_INDUSTRY = ['Retail']

/** The value the bulk change writes. */
const NEW_INDUSTRY = 'Healthcare'

const OBJECTS = '/index.php/apps/openregister/api/objects/pipelinq/client'

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
						document
							.querySelector('head[data-requesttoken]')
							?.getAttribute('data-requesttoken') ?? '',
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
 * The industry a client holds in the register.
 *
 * @param page The page to run in.
 * @param id   The client id.
 *
 * @return The industry list.
 */
async function industryOf(page: Page, id: string): Promise<unknown> {
	const res = await api(page, 'GET', `${OBJECTS}/${id}`)
	expect(res.status, `could not read client ${id}`).toBeLessThan(400)
	return res.body.industry
}

test.describe('bulk change a field on clients and undo it', () => {
	test.describe.configure({ mode: 'serial' })
	// Three register writes, two bulk jobs, and three page loads.
	test.setTimeout(240_000)

	const ids: string[] = []

	test.afterAll(async ({ browser }) => {
		if (ids.length === 0) return
		const page = await browser.newPage()
		await gotoAppRoute(page, '/clients')
		for (const id of ids) {
			await api(page, 'DELETE', `${OBJECTS}/${id}`)
		}
		await page.close()
	})

	test('Change a field sets Industry on three clients, and Undo puts the old value back', async ({
		page,
	}) => {
		await gotoAppRoute(page, '/clients')

		for (const suffix of ['A', 'B', 'C']) {
			const res = await api(page, 'POST', OBJECTS, {
				name: `${STAMP} ${suffix}`,
				type: 'organization',
				industry: OLD_INDUSTRY,
			})
			expect(
				res.status,
				`could not seed a client — ${JSON.stringify(res.body).slice(0, 300)}`,
			).toBeLessThan(400)
			const created = res.body as Record<string, never>
			ids.push((created?.id ?? created?.['@self']?.id) as string)
		}

		// Narrow the list to this run's three clients.
		await page.reload()
		const search = await openIndexSearch(page)
		await search.fill(STAMP)
		const rows = page.getByRole('row').filter({ hasText: STAMP })
		await expect(rows).toHaveCount(3, { timeout: 20_000 })

		for (let i = 0; i < 3; i++) {
			await rows.nth(i).getByRole('checkbox', { name: /select row/i }).check({ force: true })
		}
		await expect(page.getByTestId('cn-selection-strip')).toBeVisible()
		await page.getByTestId('cn-bulk-action-change-field').click()

		// The dialog: pick Industry, type Healthcare, preview, confirm.
		const dialog = page.getByTestId('bulk-change-field-dialog')
		await expect(dialog).toBeVisible()
		const field = dialog.getByTestId('bulk-change-property').locator('input')
		await field.click()
		await field.fill('Industry')
		await page.getByRole('option', { name: 'Industry', exact: true }).click()
		await dialog.getByTestId('bulk-change-value-text').locator('input').fill(NEW_INDUSTRY)

		await dialog.getByTestId('bulk-change-preview').click()
		await expect(dialog.getByTestId('bulk-change-summary')).toBeVisible({ timeout: 20_000 })
		await expect(dialog.getByTestId('bulk-change-refusal')).toHaveCount(0)

		// Preview writes nothing.
		for (const id of ids) {
			expect(await industryOf(page, id)).toEqual(OLD_INDUSTRY)
		}

		await dialog.getByTestId('bulk-change-confirm').click()
		await expect(dialog).toBeHidden({ timeout: 30_000 })

		// The table reads the new value, and so does the register.
		for (let i = 0; i < 3; i++) {
			await expect(rows.nth(i)).toContainText(NEW_INDUSTRY, { timeout: 20_000 })
		}
		for (const id of ids) {
			expect(await industryOf(page, id)).toEqual([NEW_INDUSTRY])
		}

		// Bulk changes lists the job, newest first, with its field and count.
		await gotoAppRoute(page, '/bulk-changes')
		await expect(page.getByRole('heading', { name: 'Bulk changes' })).toBeVisible()
		const listed = page
			.locator('tr[data-job]')
			.filter({ has: page.getByRole('cell', { name: 'industry', exact: true }) })
			.filter({ has: page.getByRole('cell', { name: '3', exact: true }) })
			.first()
		await expect(listed).toBeVisible({ timeout: 20_000 })
		// Pin the row by its job id: the reversal is listed above it once it runs.
		const jobId = await listed.getAttribute('data-job')
		const job = page.locator(`tr[data-job="${jobId}"]`)

		// Undo previews the reversal, then writes the old values back.
		await job.getByRole('button', { name: 'Undo' }).click()
		const undo = page.getByRole('dialog', { name: 'Undo bulk change' })
		await expect(undo).toBeVisible({ timeout: 20_000 })
		await expect(undo).toContainText('3 records get their old value back.')
		await undo.getByRole('button', { name: 'Undo' }).click()
		await expect(undo).toBeHidden({ timeout: 30_000 })
		await expect(job).toContainText('Undone', { timeout: 20_000 })

		for (const id of ids) {
			expect(await industryOf(page, id)).toEqual(OLD_INDUSTRY)
		}
	})
})
