/*
 * SPDX-FileCopyrightText: 2026 Pipelinq Contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 *
 * Gate-19 behavioral e2e coverage for contract-renewal-tracking
 * (openspec/changes/contract-renewal-tracking). Verifies the Contracts list
 * page renders from the manifest fragment and that the dashboard surfaces the
 * recurring-revenue (MRR) KPI card and the "Renewals due" widget.
 *
 * The renewal-engine lifecycle scenarios (window detection, won/lost/silent
 * reconciliation, MRR normalization, per-period renewal rate) are server-side
 * and covered by the PHPUnit suite (ContractServiceTest, RenewalEngineServiceTest,
 * RecurringRevenueServiceTest); they are annotated `@e2e exclude` below because
 * they exercise the nightly cron + OR persistence, not a browser interaction.
 */

import { test, expect } from '@playwright/test'
import { openApp, trackPipelinqErrors, assertNoHardError } from '../helpers/pipelinq'

// @e2e contract-renewal-tracking::contract-lifecycle-management
test('Contracts: the Contracts list page renders from the manifest', async ({ page }) => {
	const errs = trackPipelinqErrors(page)
	await openApp(page)

	await page.goto('/apps/pipelinq/contracts')
	const content = page.locator('#content-vue')
	await expect(content).toBeVisible({ timeout: 15000 })

	await assertNoHardError(page)
	expect(errs(), `pipelinq console errors: ${errs().join(' || ')}`).toEqual([])
})

// @e2e contract-renewal-tracking::recurring-revenue-roll-up
test('Dashboard: MRR KPI card and Renewals-due widget render', async ({ page }) => {
	await openApp(page)

	const content = page.locator('#content-vue')
	// The MRR KPI card chrome + the Renewals-due widget chrome title.
	await expect(content.getByText('Recurring revenue (MRR)').first()).toBeVisible({ timeout: 15000 })
	await expect(content.getByText('Renewals due').first()).toBeVisible()

	await assertNoHardError(page)
})

// @e2e contract-renewal-tracking::renewal-window-detection exclude server-side nightly cron (RenewalWindowJob) + OR persistence — covered by RenewalEngineServiceTest, not a browser interaction
// @e2e contract-renewal-tracking::renewal-lead-automation exclude server-side engine reconciliation (won/lost/silent) — covered by RenewalEngineServiceTest, not a browser interaction
// @e2e contract-renewal-tracking::renewal-reminders-and-notifications exclude ADR-031 declarative schema-rule notification + cron My Work entry — covered by RenewalEngineServiceTest + schema fragment, not a browser interaction
// @e2e contract-renewal-tracking::contract-schema-registration exclude OR repair-step schema import — covered by the register fragment + SettingsLoadService wiring, not a browser interaction
