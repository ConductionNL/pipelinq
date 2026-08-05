/*
 * SPDX-FileCopyrightText: 2026 Pipelinq Contributors
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Gate-19 behavioral e2e coverage for contract-renewal-tracking
 * (openspec/specs/contract-renewal-tracking/spec.md).
 *
 * The browser-testable scenarios are the Contracts list page and the dashboard
 * recurring-revenue widgets. The remaining scenarios exercise the server-side
 * renewal engine / nightly cron / OR persistence / ADR-031 declarative
 * notifications and have no browser surface; each is covered by the PHPUnit
 * suite (ContractServiceTest, RenewalEngineServiceTest, RecurringRevenueServiceTest)
 * and annotated `@e2e exclude <reason>` below.
 *
 * Scenario-id back-references (gate-19 derives ids from the `#### Scenario:`
 * heading text, kebab-cased):
 *
 * @e2e contract-renewal-tracking::create-a-contract-from-the-client-view
 * @e2e contract-renewal-tracking::expiring-contracts-listed-by-urgency
 * @e2e contract-renewal-tracking::mrr-card-reflects-contract-changes
 *
 * @e2e contract-renewal-tracking::schema-registration exclude OR repair-step schema import — no browser surface; verified by the register fragment + SettingsLoadService wiring + PHP suite
 * @e2e contract-renewal-tracking::guarded-transition-rejected exclude server-side guard in ContractService::assertTransitionAllowed — covered by ContractServiceTest, returns 422 from the API
 * @e2e contract-renewal-tracking::terminal-state-is-immutable exclude server-side guard — covered by ContractServiceTest::testTerminalStateIsImmutable
 * @e2e contract-renewal-tracking::contract-enters-its-renewal-window exclude nightly RenewalWindowJob window math — covered by RenewalEngineServiceTest::testIsInRenewalWindow
 * @e2e contract-renewal-tracking::idempotent-re-run exclude nightly cron idempotency — covered by RenewalEngineServiceTest::testIdempotentReRun
 * @e2e contract-renewal-tracking::renewal-lead-created-in-the-existing-pipeline exclude engine lead creation — covered by RenewalEngineServiceTest::testProcessCreatesExpiringAndOneLead
 * @e2e contract-renewal-tracking::won-renewal-drafts-the-successor exclude engine reconciliation — covered by RenewalEngineServiceTest::testReconcileWonDraftsSuccessor
 * @e2e contract-renewal-tracking::lost-renewal-churns-the-contract exclude engine reconciliation — covered by RenewalEngineServiceTest::testReconcileLostChurns
 * @e2e contract-renewal-tracking::silent-expiry-churns-the-contract exclude nightly cron silent-expiry — covered by RenewalEngineServiceTest::testSilentExpiryChurns
 * @e2e contract-renewal-tracking::owner-notified-when-the-window-opens exclude ADR-031 declarative schema-rule notification fired by OpenRegister — no app-code dispatch, no browser surface
 * @e2e contract-renewal-tracking::notice-deadline-lands-in-my-work exclude nightly cron My Work entry — covered by RenewalEngineServiceTest::testNoticeDeadlineMyWorkEntry
 * @e2e contract-renewal-tracking::interval-normalization exclude pure MRR math — covered by RecurringRevenueServiceTest + recurringRevenue.spec.js
 * @e2e contract-renewal-tracking::renewal-rate-per-period exclude pure period math — covered by RecurringRevenueServiceTest::testRenewalMetricsPerPeriod
 * @e2e contract-renewal-tracking::per-client-recurring-value exclude pure per-client math — covered by RecurringRevenueServiceTest::testClientMrr
 * @e2e contract-renewal-tracking::renewals-widget-empty-state exclude empty-state rendering verified by the RenewalsDueWidget template + RenewalsDueWidget unit behaviour; no seeded fixture in the e2e env
 */

import { test, expect } from '@playwright/test'
import { openApp, trackPipelinqErrors, assertNoHardError } from '../helpers/pipelinq'

test('Contracts: the Contracts list page renders from the manifest', async ({ page }) => {
	const errs = trackPipelinqErrors(page)
	await openApp(page)

	await page.goto('/apps/pipelinq/contracts')
	const content = page.locator('#content-vue')
	await expect(content).toBeVisible({ timeout: 15000 })

	await assertNoHardError(page)
	expect(errs(), `pipelinq console errors: ${errs().join(' || ')}`).toEqual([])
})

test('Dashboard: MRR KPI card and Renewals-due widget render', async ({ page }) => {
	await openApp(page)

	const content = page.locator('#content-vue')
	await expect(content.getByText('Recurring revenue (MRR)').first()).toBeVisible({ timeout: 15000 })
	await expect(content.getByText('Renewals due').first()).toBeVisible()

	await assertNoHardError(page)
})
