/*
 * SPDX-FileCopyrightText: 2026 Pipelinq Contributors
 * SPDX-License-Identifier: EUPL-1.2
 *
 * An install without a telephony platform shows no incoming-call card.
 *
 * WHY THIS FILE EXISTS. The card is mounted in the app shell, so it is on
 * every page of every install, and almost no install has telephony. The unit
 * tests prove the card stays silent when the endpoint says "not enabled". Only
 * a browser proves the endpoint is routed, answers a signed-in agent, and that
 * the shell with the card in it still renders.
 *
 * THE FAILURE THIS GUARDS is a card that breaks or nags the installs that have
 * no telephony: a 404 or 500 on every page load, or a card with no call in it.
 *
 * Covers, from openspec/changes/screen-pop-incoming-call:
 *   REQ-CTI-012 "An install without telephony shows no card"
 */
import { expect, test } from '@playwright/test'
import { APP_LOAD_BUDGET_MS, openApp } from './helpers/pipelinq.ts'

test.describe('incoming-call card without telephony', () => {
	test.setTimeout(APP_LOAD_BUDGET_MS + 30_000)

	// @e2e cti-screenpop-adapter::an-install-without-telephony-shows-no-card
	test('no card shows and the endpoint says telephony is off', async ({
		page,
	}) => {
		const asked: number[] = []
		page.on('response', (response) => {
			if (response.url().includes('/api/cti/incoming-call')) {
				asked.push(response.status())
			}
		})

		await openApp(page)

		await expect.poll(() => asked.length, { timeout: 15_000 }).toBeGreaterThan(0)
		expect(asked).toEqual([200])
		await expect(page.locator('[data-testid="incoming-call-card"]')).toHaveCount(
			0,
		)

		const answer = await page.evaluate(async () => {
			const response = await fetch(
				'/index.php/apps/pipelinq/api/cti/incoming-call',
				{
					headers: {
						requesttoken: (
							window as unknown as { OC: { requestToken: string } }
						).OC.requestToken,
					},
				},
			)
			return { status: response.status, body: await response.json() }
		})
		expect(answer.status).toBe(200)
		expect(answer.body).toEqual({ enabled: false, delayMs: 0, call: null })
	})
})
