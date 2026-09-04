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
import {
	dismissSupportDialog,
	dismissWalkthrough,
	openApp,
} from '../helpers/pipelinq.ts'

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
		await page.goto('/apps/pipelinq/#/blasts/new')
		await expect(page.locator('#content-vue')).toBeVisible({ timeout: 15000 })
		await dismissWalkthrough(page)
		await dismissSupportDialog(page)

		const form = page.locator('.blast-form')
		await expect(form.getByRole('heading', { name: 'New blast' })).toBeVisible({
			timeout: 20000,
		})

		// The breadcrumb names every step, including the one this change adds.
		const steps = form.locator('.blast-form__steps li')
		await expect(steps).toHaveCount(7)
		await expect(steps.filter({ hasText: 'Transport' })).toBeVisible()

		// Name — required before Next is enabled.
		await form.locator('#blast-form-name').fill('E2E gate-19 transport step')
		await form.getByRole('button', { name: 'Next' }).first().click()

		// Segment — same NcSelect-appended-to-body pattern as the sibling
		// wizard test in marketing.spec.ts.
		const segmentPicker = form.locator('.vs__dropdown-toggle').first()
		await expect(segmentPicker).toBeVisible({ timeout: 20000 })
		await segmentPicker.click()
		await page
			.locator('li[role="option"], .vs__dropdown-option')
			.filter({ hasText: 'Gemeente Contact Blast' })
			.first()
			.click()
		await form.getByRole('button', { name: 'Next' }).first().click()

		// Template — pick the seeded email-channel template.
		const templatePicker = form.locator('.vs__dropdown-toggle').first()
		await expect(templatePicker).toBeVisible({ timeout: 20000 })
		await templatePicker.click()
		await page
			.locator('li[role="option"], .vs__dropdown-option')
			.filter({ hasText: 'Q4 Product Launch' })
			.first()
			.click()
		await form.getByRole('button', { name: 'Next' }).first().click()

		// Channel — 'email' is selected by default, so Next advances straight
		// through to the transport step.
		await form.getByRole('button', { name: 'Next' }).first().click()

		// Transport step: the "Send through" NcSelect is visible, and the
		// seeded default transport (Instance mail server) is pre-selected —
		// proves the wizard resolves and offers a default without any pick.
		await expect(form.locator('.blast-form__hint')).toContainText(
			'Leave empty to send through the default transport.',
			{ timeout: 15000 },
		)
		const transportToggle = form.locator('.vs__dropdown-toggle').first()
		await expect(transportToggle).toContainText('Instance mail server', {
			timeout: 15000,
		})
	})
})
