/*
 * SPDX-FileCopyrightText: 2026 Pipelinq Contributors
 * SPDX-License-Identifier: EUPL-1.2
 *
 * A ticket's Decisions tab prepares a decision in decidiq (REQ-TDT-001).
 *
 * The assertion is POSITIVE: the proposal created from the tab must show up
 * in the tab's list, under the title this run gave it. A check that the tab
 * merely exists would pass with a leaf that never writes.
 *
 * Needs decidiq. Without it the Decisions tab is absent by design
 * (`visibleWhen: { appInstalled: decidiq }`), so the test skips and says why;
 * the skip is decided on the instance's own list of enabled apps, not on the
 * missing tab, so a tab that vanished with decidiq present still fails.
 *
 * @spec openspec/specs/ticket-decisions/spec.md#requirement-the-ticket-page-has-a-decisions-tab-req-tdt-001
 */
import { expect, test } from '@playwright/test'
import {
	appUrl,
	dismissSupportDialog,
	dismissWalkthrough,
} from './helpers/pipelinq.ts'
import { FixtureSession, TEST_PREFIX } from './workflows/helpers/fixtures.ts'

test('a proposal created on the Decisions tab is listed there', async ({ page }) => {
	test.setTimeout(120_000)
	const fx = new FixtureSession(page)
	try {
		await page.goto(appUrl('/tickets'))
		const decidiq = await page.evaluate(() =>
			Boolean((window as any).OC?.appswebroots?.decidiq),
		)
		test.skip(
			!decidiq,
			'decidiq is not enabled on this instance, so the Decisions tab is absent by design',
		)

		const ticket = await fx.create('ticket', {
			title: `${TEST_PREFIX} decisions tab`,
			ticketType: 'request',
			status: 'new',
			channel: 'phone',
		})
		const ticketId = ticket.id || ticket['@self']?.id

		await page.goto(appUrl(`/tickets/${ticketId}`))
		await dismissSupportDialog(page)
		await dismissWalkthrough(page)
		await page.getByRole('tab', { name: /^(Decisions|Besluiten)$/ }).click()

		const proposalTitle = `${TEST_PREFIX} proposal`
		await page
			.getByRole('button', {
				name: /Create proposal for this object|Voorstel aanmaken voor dit object/,
			})
			.first()
			.click()
		const dialog = page.getByRole('dialog')
		await dialog
			.getByRole('textbox', { name: /^(Title|Titel)/ })
			.fill(proposalTitle)
		// decidiq's decision schema requires `text`, which the form labels as
		// the explanation; without it the save is refused with a 400.
		await dialog
			.getByRole('textbox', { name: /^(Explanation|Toelichting)/ })
			.fill('Prepared from the ticket by the e2e suite.')
		await dialog
			.getByRole('button', { name: /^(Create|Save|Aanmaken|Opslaan)$/ })
			.last()
			.click()

		await expect(page.getByText(proposalTitle)).toBeVisible({ timeout: 30_000 })
	} finally {
		await fx.cleanup()
	}
})
