/*
 * SPDX-FileCopyrightText: 2026 Pipelinq Contributors
 * SPDX-License-Identifier: EUPL-1.2
 *
 * A BI export job the system switched off shows why on the BI export page,
 * and switching it on again clears the reason (board PqBiExport).
 *
 * The counting, the switch-off and the notification run on the server and
 * are asserted in ExportFailureSwitchTest; this file reads what an
 * administrator sees. The job is seeded in its switched-off state through
 * the register, as the run path leaves it.
 *
 * Covers, from openspec/changes/exports-switch-off-after-repeated-failure:
 *   REQ-EFS-003 "The reason shows in the list"
 *   REQ-EFS-003 "Switching the job back on starts over"
 */
import type { Page } from '@playwright/test'

import { expect, test } from '@playwright/test'
import { APP_LOAD_BUDGET_MS, gotoAppRoute, openApp } from './helpers/pipelinq.ts'

/** Distinctive enough that finding it proves the read, not a coincidence. */
const STAMP = Date.now()

/**
 * Store one object through the register, carrying the session.
 *
 * @param page The page to run in.
 * @param schema The pipelinq schema slug.
 * @param data The object's fields.
 *
 * @return The created id.
 */
async function seed(
	page: Page,
	schema: string,
	data: Record<string, unknown>,
): Promise<string> {
	const res = await page.evaluate(async ({ schema, body }) => {
		const response = await fetch(
			`/index.php/apps/openregister/api/objects/pipelinq/${schema}`,
			{
				method: 'POST',
				headers: {
					'Content-Type': 'application/json',
					requesttoken:
						document
							.querySelector('head[data-requesttoken]')
							?.getAttribute('data-requesttoken') ?? '',
				},
				body: JSON.stringify(body),
			},
		)
		const text = await response.text()
		try {
			return { status: response.status, body: JSON.parse(text) }
		} catch {
			return { status: response.status, body: { raw: text.slice(0, 300) } }
		}
	}, { schema, body: data })
	expect(
		res.status,
		`could not store a ${schema}: ${JSON.stringify(res.body).slice(0, 300)}`,
	).toBeLessThan(400)
	const id = res.body?.id ?? res.body?.['@self']?.id
	expect(id, `the register returned no id for the ${schema}`).toBeTruthy()
	return id as string
}

/**
 * Seed a valid destination and a job switched off after three failed runs.
 *
 * @param page The page to run in.
 * @param name The job name.
 *
 * @return The job id.
 */
async function seedSwitchedOffJob(page: Page, name: string): Promise<string> {
	const destinationId = await seed(page, 'exportDestination', {
		name: `Warehouse ${STAMP}`,
		type: 'postgres',
		connectorSourceId: 'warehouse',
		pathTemplate: '{dataset}/{run_id}',
		validationStatus: 'valid',
	})
	return seed(page, 'exportJob', {
		name,
		sourceSchemas: ['lead'],
		destinationId,
		format: 'jsonl',
		mode: 'full',
		scheduleCron: '@daily',
		createdBy: 'admin',
		createdAt: new Date().toISOString(),
		enabled: false,
		lastRunStatus: 'failed',
		lastRunAt: new Date().toISOString(),
		lastRunError: 'connection refused',
		consecutiveFailures: 3,
		pausedReason: 'Switched off after 3 failed runs in a row',
		pausedAt: new Date().toISOString(),
	})
}

test.describe('an export job switched off after failed runs', () => {
	// Register writes plus two shell loads.
	test.setTimeout(180000)

	// @e2e export-failure-switch-off::the-reason-shows-in-the-list
	test('the Last run cell says why, and the failed view lists the job', async ({ page }) => {
		await openApp(page)
		const name = `Leads and pipeline value ${STAMP}`
		await seedSwitchedOffJob(page, name)

		await gotoAppRoute(page, '/export/jobs')
		const row = page.getByRole('row', { name: new RegExp(name) })
		await expect(row).toBeVisible({ timeout: APP_LOAD_BUDGET_MS })
		await expect(row).toContainText('Failed')
		await expect(row).toContainText('connection refused')
		await expect(row).toContainText('Switched off after 3 failed runs in a row')

		await page.getByRole('button', { name: /Last run failed/ }).click()
		await expect(page.getByRole('row', { name: new RegExp(name) })).toBeVisible()
	})

	// @e2e export-failure-switch-off::switching-the-job-back-on-starts-over
	test('switching the job on clears the reason', async ({ page }) => {
		await openApp(page)
		const name = `Contact moments ${STAMP}`
		await seedSwitchedOffJob(page, name)

		await gotoAppRoute(page, '/export/jobs')
		const row = page.getByRole('row', { name: new RegExp(name) })
		await expect(row).toContainText('Switched off after 3 failed runs in a row', { timeout: APP_LOAD_BUDGET_MS })

		await row.getByLabel(`Run ${name} on its schedule`).click()
		await page.reload()
		const reloaded = page.getByRole('row', { name: new RegExp(name) })
		await expect(reloaded).toBeVisible({ timeout: APP_LOAD_BUDGET_MS })
		await expect(reloaded).not.toContainText('Switched off after')
	})
})
