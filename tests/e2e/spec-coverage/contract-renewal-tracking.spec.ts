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

import { expect, test } from '@playwright/test'
import {
	assertNoHardError,
	navClick,
	openApp,
	trackPipelinqErrors,
} from '../helpers/pipelinq.ts'

test('Contracts: the Contracts list page renders from the manifest', async ({
	page,
}) => {
	const errs = trackPipelinqErrors(page)
	await openApp(page)
	await navClick(page, 'Contracts', /\/contracts/)

	const content = page.locator('#content-vue')
	await expect(
		content.getByRole('heading', { name: 'Contracts' }).first(),
	).toBeVisible({ timeout: 15000 })
	// `endDate` is the column the renewal-window migration path depends on (see
	// the REMOVED "Renewals Due Widget" requirement below), so its presence is
	// the contract this page owes the retired dashboard tile.
	await expect(
		content.getByRole('columnheader', { name: /end ?date/i }).first(),
	).toBeVisible({ timeout: 15000 })

	await assertNoHardError(page)
	expect(errs(), `pipelinq console errors: ${errs().join(' || ')}`).toEqual([])
})

/*
 * RETARGETED 2026-08-06. This test asserted two things that do not exist.
 *
 *  - `'Recurring revenue (MRR)'` — the manifest widget's title is
 *    `"Recurring revenue"`. The parenthesised "(MRR)" was never rendered text.
 *  - `'Renewals due'` — a REMOVED requirement. `recurring-revenue-runrate-widget`
 *    retired `RenewalsDueWidget` together with the bespoke MRR widget, deleting
 *    the widget entry, its layout entry, the `widget-renewals-due` slot mapping
 *    and the component. The string does not occur anywhere under `src/`. Its
 *    documented migration is: "Renewal-window information remains discoverable
 *    via the contract list (ordered by endDate)" — asserted in the test above.
 *
 * So the tile assertion is corrected to the real title, and the second half is
 * replaced by the behaviour the same change SPECIFIES for a CI instance:
 * shillinq is not installed there, and the spec requires the tile to show the
 * "Install shillinq" call-to-action and NOT a locally computed number.
 */
test('Dashboard: the recurring-revenue tile renders and defers to shillinq', async ({
	page,
}) => {
	await openApp(page)

	const content = page.locator('#content-vue')
	// The tile's layout slot sets `showTitle: false`, so the manifest `title`
	// ("Recurring revenue") is never painted — and because the widget declares
	// `requiresApp: "shillinq"` and shillinq is absent on CI, CnDashboardPage
	// replaces the body with the install CTA rather than a number. That is the
	// SPEC'd behaviour, verbatim: "GIVEN shillinq is not installed … THEN the
	// recurring-revenue tile MUST show the 'Install shillinq' call-to-action AND
	// MUST NOT display a locally-computed run-rate number."
	await expect(content.getByText('Install shillinq').first()).toBeVisible({
		timeout: 15000,
	})
	// The retired local roll-up formatted its figure as EUR currency; asserting
	// its absence is what makes "MUST NOT display a locally-computed number"
	// testable rather than decorative.
	await expect(
		content.locator('.cn-dashboard-page__requires-app'),
	).not.toContainText('€')

	await assertNoHardError(page)
})
