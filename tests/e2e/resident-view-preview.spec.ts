/*
 * SPDX-FileCopyrightText: 2026 Pipelinq Contributors
 * SPDX-License-Identifier: EUPL-1.2
 *
 * What the resident sees, on a request ticket (portal-resident-view-preview).
 *
 * The section must show what the portal shows and nothing else: the parts
 * that can go wrong silently are an internal note leaking into the preview,
 * and a preview on a complaint the portal never serves.
 *
 * @e2e resident-view-preview::a-kcc-employee-checks-the-answer-before-setting-awaiting-customer
 * @e2e resident-view-preview::the-handlers-name-follows-the-portal-setting
 * @e2e resident-view-preview::a-complaint-ticket-has-no-preview
 */
import type { Page } from '@playwright/test'

import { expect, test } from '@playwright/test'
import { gotoAppRoute, openApp } from './helpers/pipelinq.ts'

const STAMP = Date.now()

/**
 * Seed one ticket through OpenRegister's REST API, from inside the page.
 *
 * @param page   The page to run in.
 * @param object The ticket body.
 *
 * @return The created id.
 */
async function seedTicket(
	page: Page,
	object: Record<string, unknown>,
): Promise<string> {
	const res = await page.evaluate(async (body) => {
		const r = await fetch(
			'/index.php/apps/openregister/api/objects/pipelinq/ticket',
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
		return { status: r.status, body: await r.json().catch(() => ({})) }
	}, object)
	expect(
		res.status,
		`could not seed a ticket: ${JSON.stringify(res.body).slice(0, 300)}`,
	).toBeLessThan(400)
	return (res.body?.id ?? res.body?.['@self']?.id) as string
}

test.describe('what the resident sees', () => {
	test.setTimeout(180000)

	test('a request ticket shows the resident view without the internal note', async ({
		page,
	}) => {
		await openApp(page)
		const id = await seedTicket(page, {
			ticketType: 'request',
			title: `E2E resident view ${STAMP}`,
			status: 'new',
			notes: `internal note ${STAMP}`,
			customerMessage: `We will come by on Thursday ${STAMP}`,
			assignee: 'admin',
		})

		await gotoAppRoute(page, `/tickets/${id}`)
		const section = page.getByTestId('resident-view')
		await expect(section).toBeVisible({ timeout: 30000 })
		await expect(section).toContainText(`E2E resident view ${STAMP}`)
		await expect(section).toContainText(`We will come by on Thursday ${STAMP}`)
		await expect(section).not.toContainText(`internal note ${STAMP}`)
		// The default portal setting hides the handler's name.
		await expect(section).not.toContainText('admin')
		await expect(section.locator('.resident-view__internal')).toBeVisible()
	})

	test('a complaint ticket has no resident view', async ({ page }) => {
		await openApp(page)
		const id = await seedTicket(page, {
			ticketType: 'complaint',
			title: `E2E complaint ${STAMP}`,
			status: 'new',
		})

		await gotoAppRoute(page, `/tickets/${id}`)
		await expect(page.getByText(`E2E complaint ${STAMP}`).first()).toBeVisible({
			timeout: 30000,
		})
		await expect(page.getByTestId('resident-view')).toHaveCount(0)
	})
})
