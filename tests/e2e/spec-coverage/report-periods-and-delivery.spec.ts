/*
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Gate-19 e2e coverage for openspec/specs/report-periods-and-delivery/spec.md
 * (archived change reports-compare-periods-and-scheduled-delivery).
 *
 * The schedule dialog scenario runs through the BI export page as a user
 * would. The group "Team leads" is made through the provisioning API first, so
 * the test does not depend on a seeded instance. Delivering the mail itself
 * (REQ-RPD-002) runs in the background scheduler and is covered by
 * ReportMailDeliveryTest and ExportExecutionMailTest; the period comparison
 * (REQ-RPD-001) by AnalyticsServiceTest and commercialTilesComparison.spec.js.
 *
 * @e2e report-periods-and-delivery::setting-up-a-mailed-report
 */
import type { Page } from '@playwright/test'

import { expect, test } from '@playwright/test'
import { dismissSupportDialog, dismissWalkthrough, gotoAppRoute } from '../helpers/pipelinq.ts'

/** Distinctive enough that finding the job proves this run made it. */
const STAMP = Date.now()

/**
 * Call a route from inside the page, carrying the session and its token.
 *
 * @param page   The page to run in.
 * @param method The HTTP verb.
 * @param url    The url.
 *
 * @return The status and the body as text.
 */
async function call(page: Page, method: string, url: string): Promise<{ status: number; text: string }> {
	return await page.evaluate(
		async ({ method, url }) => {
			const res = await fetch(url, {
				method,
				headers: {
					'OCS-APIRequest': 'true',
					requesttoken:
						document.querySelector('head[data-requesttoken]')?.getAttribute('data-requesttoken') ?? '',
				},
			})
			return { status: res.status, text: await res.text() }
		},
		{ method, url },
	)
}

/**
 * Pick an option in the NcSelect named by its input label.
 *
 * @param page   The page.
 * @param label  The select's input label.
 * @param search Text to type to search, or empty to pick from the open list.
 * @param option The option's visible text.
 *
 * @return Nothing.
 */
async function pick(page: Page, label: string, search: string, option: string): Promise<void> {
	const input = page.getByLabel(label, { exact: true })
	await input.click()
	if (search !== '') {
		await input.fill(search)
	}

	await page.getByRole('option', { name: option, exact: true }).first().click()
}

test.describe('report-periods-and-delivery', () => {
	// @e2e report-periods-and-delivery::setting-up-a-mailed-report
	test('setting up a mailed report names its schedule and recipients, and saves an enabled job', async ({ page }) => {
		await gotoAppRoute(page, '/export/jobs/new')
		await dismissWalkthrough(page)
		await dismissSupportDialog(page)

		// The group the report goes to; creating it again answers "exists", which is fine.
		const group = await call(page, 'POST', '/ocs/v2.php/cloud/groups?groupid=Team%20leads')
		expect([200, 102, 400], group.text.slice(0, 200)).toContain(group.status)

		const name = `E2E Open tickets ${STAMP}`
		await page.getByLabel('Name', { exact: true }).fill(name)
		await pick(page, 'Delivery', '', 'Mail')
		await pick(page, 'Recipients', 'Team', 'Team leads')

		const schedule = page.getByLabel('Schedule (cron)', { exact: true })
		await schedule.fill('0 7 * * 1')
		await expect(page.getByText('Every Monday at 07:00 to Team leads')).toBeVisible({ timeout: 15000 })

		// The form saves the mail destination and the job through the object store.
		await page.getByRole('button', { name: 'Save', exact: true }).click()

		let job: Record<string, unknown> | undefined
		await expect.poll(async () => {
			const jobs = await call(page, 'GET', '/index.php/apps/pipelinq/api/export/jobs')
			const body = JSON.parse(jobs.text)
			const rows: Array<Record<string, unknown>> = body.jobs ?? body.data ?? body.results ?? []
			job = rows.find((row) => row.name === name)
			return job !== undefined
		}, { message: `the saved job ${name} is listed`, timeout: 20000 }).toBe(true)

		expect(job?.enabled).toBe(true)
		expect(job?.scheduleCron).toBe('0 7 * * 1')
	})
})
