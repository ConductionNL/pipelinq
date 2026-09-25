/*
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Gate-19 e2e coverage for marketing-mail-transports's two UI-observable
 * requirements: the deliverability panel (admin settings) lists the seeded
 * mailTransport rows, and the blast wizard offers a transport step.
 *
 * WHAT THIS CANNOT PROVE. Transport SEND behaviour (instance mailer /
 * Mail-account / OpenConnector-source dispatch, the daily-limit roll,
 * header injection) needs a real send through IMailer/the Mail app/
 * OpenConnector, none of which the CI instance installs
 * (.github/workflows/code-quality.yml pins `additional-apps` to openregister
 * and planninq). Those scenarios are asserted by PHPUnit
 * (tests/Unit/Service/Marketing/MailTransportServiceTest.php and the
 * per-adapter test files) and carry `@e2e exclude` in the spec. This file
 * covers only what a browser can actually observe: the two rendered panels.
 *
 * A live DNS lookup ("Check now") is deliberately never clicked here — it is
 * a real network call and would make this suite flaky and offline-hostile.
 * The seeded "Zonnig Reizen" provider transport ships with a pre-cached
 * dkimVerified/dmarcStatus verdict for exactly this reason: the panel's
 * RENDERING of a cached verdict is what gets tested, not DNS itself.
 */
import { expect, test } from '@playwright/test'
import { navClick, openApp } from '../helpers/pipelinq.ts'

test.describe('Deliverability panel', () => {
	// @e2e openspec/changes/marketing-mail-transports/specs/marketing-mail-transports/spec.md#requirement-the-deliverability-panel-shows-spf-dkim-and-dmarc-status-per-sender-domain
	test('the admin settings page lists the seeded mail transports with their state', async ({
		page,
	}) => {
		await page.goto('/settings/admin/pipelinq')
		await expect(page.locator('body')).not.toContainText(
			'Internal Server Error',
			{
				timeout: 15000,
			},
		)

		const panel = page.locator('.deliverability-settings')
		await expect(panel).toBeVisible({ timeout: 20000 })

		// The instance-mailer transport is seeded and marked default — every
		// tenant gets it for free (95-marketing-mail-transports.json).
		const instanceRow = panel.locator('tbody tr', {
			hasText: 'Instance mail server',
		})
		await expect(instanceRow).toBeVisible({ timeout: 15000 })
		await expect(instanceRow).toContainText('gemeente-voorbeeld.example.nl')

		// The seeded provider transport carries a pre-cached "found" verdict —
		// proves the panel renders a cached verdict without querying DNS.
		const providerRow = panel.locator('tbody tr', {
			hasText: 'Zonnig Reizen, SendGrid bulk newsletter',
		})
		await expect(providerRow).toBeVisible()
		await expect(providerRow).toContainText('DKIM found')
		await expect(providerRow).toContainText('DMARC found')
	})
})

test.describe('Blast wizard transport step', () => {
	// @e2e openspec/changes/marketing-mail-transports/specs/marketing-mail-transports/spec.md#requirement-the-wizard-offers-a-transport-step
	test('the wizard offers a transport step, pre-selected to the default transport', async ({
		page,
	}) => {
		await openApp(page)
		await navClick(page, 'Blasts', /\/blasts$/)
		await page.locator('#content-vue [data-testid="cn-cta-primary"]').first().click()

		const dialog = page.getByRole('dialog', { name: 'New blast' })
		await expect(dialog).toBeVisible({ timeout: 20000 })

		// The stepper names every step, including Delivery, which holds the
		// transport choice.
		await expect(dialog.locator('.blast-wizard__step')).toHaveCount(6)
		await expect(
			dialog.locator('.blast-wizard__step').filter({ hasText: 'Delivery' }),
		).toBeVisible()

		// Basics: the name is required; email is the default channel.
		await dialog.locator('#blast-wizard-name').fill('E2E gate-19 transport step')
		await dialog.getByRole('button', { name: 'Next' }).click()

		// Audience. NcSelect appends its open menu to <body>.
		const segmentPicker = dialog.locator('.vs__dropdown-toggle').first()
		await expect(segmentPicker).toBeVisible({ timeout: 20000 })
		await segmentPicker.click()
		await page
			.locator('li[role="option"], .vs__dropdown-option')
			.filter({ hasText: 'Gemeente Contact Blast' })
			.first()
			.click()
		await dialog.getByRole('button', { name: 'Next' }).click()

		// Content: the seeded email template.
		const templatePicker = dialog.locator('.vs__dropdown-toggle').first()
		await expect(templatePicker).toBeVisible({ timeout: 20000 })
		await templatePicker.click()
		await page
			.locator('li[role="option"], .vs__dropdown-option')
			.filter({ hasText: 'Q4 Product Launch' })
			.first()
			.click()
		await expect(dialog.getByRole('button', { name: 'Next' })).toBeEnabled({
			timeout: 15000,
		})
		await dialog.getByRole('button', { name: 'Next' }).click()

		// Delivery: "Send through" pre-selects the seeded default transport
		// (Instance mail server) without any pick.
		await expect(dialog).toContainText(
			'Leave empty to send through the default transport.',
			{ timeout: 15000 },
		)
		await expect(dialog.locator('.blast-wizard__transport .vs__dropdown-toggle')).toContainText(
			'Instance mail server',
			{ timeout: 15000 },
		)
	})
})
