/*
 * SPDX-FileCopyrightText: 2026 Pipelinq Contributors
 * SPDX-License-Identifier: EUPL-1.2
 *
 * A resident's client page says how they found us, in the Identity card as
 * PqKlant draws it: the form and when, the landing page, and the campaign
 * with its source.
 *
 * The touches are written by the form intake on the server and asserted in
 * TouchpointServiceTest and SubmittedLeadIntakeTest; this file reads what a
 * marketer sees. The client is seeded with a last touch through the register.
 *
 * Covers, from openspec/changes/marketing-campaign-on-client:
 *   REQ-MOC-001 "A marketer sees where a new client came from"
 */
import { expect, test } from '@playwright/test'
import { APP_LOAD_BUDGET_MS, gotoAppRoute, openApp } from './helpers/pipelinq.ts'

/** Distinctive enough that finding it proves the read, not a coincidence. */
const STAMP = Date.now()

test.describe('how a resident found us', () => {
	// One register write plus two shell loads.
	test.setTimeout(180000)

	// @e2e marketing-origin-on-client::a-marketer-sees-where-a-new-client-came-from
	test('the Identity card shows the form, the landing page and the campaign', async ({ page }) => {
		await openApp(page)
		const touch = {
			campaign: 'zomer-in-de-wijk',
			source: 'nieuwsbrief',
			medium: 'email',
			landingPage: 'zuiddrecht/evenementen',
			form: 'Straatfeest aanvragen',
			at: '2026-10-02T10:00:00+02:00',
		}
		const res = await page.evaluate(async (body) => {
			const response = await fetch('/index.php/apps/openregister/api/objects/pipelinq/client', {
				method: 'POST',
				headers: {
					'Content-Type': 'application/json',
					requesttoken: document.querySelector('head[data-requesttoken]')?.getAttribute('data-requesttoken') ?? '',
				},
				body: JSON.stringify(body),
			})
			return { status: response.status, body: await response.json().catch(() => ({})) }
		}, { name: `Mila Bakker ${STAMP}`, type: 'person', firstTouch: touch, lastTouch: touch })
		expect(res.status, 'could not store the client').toBeLessThan(400)
		const id = res.body?.id ?? res.body?.['@self']?.id

		await gotoAppRoute(page, `/clients/${id}`)
		const identity = page.getByRole('region', { name: 'Identity' }).or(page.locator('[data-testid="cn-facts-widget"]').filter({ hasText: 'Landing page' }))
		await expect(identity.first()).toContainText('Straatfeest aanvragen', { timeout: APP_LOAD_BUDGET_MS })
		await expect(identity.first()).toContainText('zuiddrecht/evenementen')
		await expect(identity.first()).toContainText('zomer-in-de-wijk')
		await expect(identity.first()).toContainText('nieuwsbrief')
	})
})
