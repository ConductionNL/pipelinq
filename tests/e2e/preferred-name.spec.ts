/*
 * SPDX-FileCopyrightText: 2026 Pipelinq Contributors
 * SPDX-License-Identifier: EUPL-1.2
 *
 * A person's preferred name shows in the header of their contact page.
 *
 * WHY THIS FILE EXISTS. The field, the sync and the mail greeting each have a
 * unit test, and none of those opens a page. This one stores Jan on Johannes
 * Jansen through the register and reads the header a KCC agent sees.
 *
 * THE FAILURE THIS GUARDS is a header that shows a name nobody stored. So the
 * second test opens a person WITHOUT a preferred name and expects no chip:
 * a chip that showed the full name twice would pass the first test alone.
 *
 * Covers, from openspec/changes/clients-preferred-name:
 *   REQ-CPN-001 "A KCC agent records a roepnaam"
 */
import type { Page } from '@playwright/test'

import { expect, test } from '@playwright/test'
import { APP_LOAD_BUDGET_MS, gotoAppRoute, openApp } from './helpers/pipelinq.ts'

/** Distinctive enough that finding it proves the read, not a coincidence. */
const STAMP = Date.now()

/**
 * Store one contact person through the register, carrying the session.
 *
 * @param page The page to run in.
 * @param data The contact's fields.
 *
 * @return The created id.
 */
async function seedContact(
	page: Page,
	data: Record<string, unknown>,
): Promise<string> {
	const res = await page.evaluate(async (body) => {
		const response = await fetch(
			'/index.php/apps/openregister/api/objects/pipelinq/contact',
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
	}, data)
	expect(
		res.status,
		`could not store a contact: ${JSON.stringify(res.body).slice(0, 300)}`,
	).toBeLessThan(400)
	const id = res.body?.id ?? res.body?.['@self']?.id
	expect(id, 'the register returned no id for the contact').toBeTruthy()
	return id as string
}

test.describe('preferred name on a contact person', () => {
	// Register writes plus two shell loads.
	test.setTimeout(180000)

	// @e2e client-preferred-name::a-kcc-agent-records-a-roepnaam
	test('the header shows Jan under Johannes Jansen', async ({ page }) => {
		await openApp(page)
		const id = await seedContact(page, {
			name: `Johannes Jansen ${STAMP}`,
			preferredName: 'Jan',
			email: `johannes.${STAMP}@example.com`,
		})

		await gotoAppRoute(page, `/contacts/${id}`)

		await expect(
			page.getByRole('heading', { name: `Johannes Jansen ${STAMP}` }),
		).toBeVisible({
			timeout: APP_LOAD_BUDGET_MS,
		})
		await expect(
			page.getByTestId('cn-detail-header-chip-preferredName'),
		).toContainText('Jan')
	})

	// @e2e client-preferred-name::a-kcc-agent-records-a-roepnaam
	test('a person without a preferred name shows no chip', async ({ page }) => {
		await openApp(page)
		const id = await seedContact(page, {
			name: `Hanna de Wit ${STAMP}`,
			email: `hanna.${STAMP}@example.com`,
		})

		await gotoAppRoute(page, `/contacts/${id}`)

		await expect(
			page.getByRole('heading', { name: `Hanna de Wit ${STAMP}` }),
		).toBeVisible({
			timeout: APP_LOAD_BUDGET_MS,
		})
		await expect(
			page.getByTestId('cn-detail-header-chip-preferredName'),
		).toHaveCount(0)
	})
})
