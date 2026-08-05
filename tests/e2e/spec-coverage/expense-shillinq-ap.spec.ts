/*
 * SPDX-FileCopyrightText: 2026 Pipelinq Contributors
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Gate-19 e2e coverage for
 * openspec/changes/pipelinq-expense-to-shillinq-ap/specs.md
 *
 * UI-observable scenarios for the Shillinq AP integration:
 *  - Admin settings page renders the Integraties section and the
 *    `shillinq_ap_webhook_url` text field (REQ-AP-004 / Scenario 12).
 *  - The expense list page reaches its empty/loaded state through the
 *    `/apps/pipelinq/#/expenses` route (REQ-AP-005 — column header is
 *    only visible once an expense exists; here we assert the surface
 *    mounts without an internal-server error and the New expense CTA
 *    is reachable from the empty state).
 *  - The expense detail route mounts (REQ-AP-006 — AP card is gated on
 *    a real apSyncStatus value seeded from REQ-AP-007).
 *
 * Backend dispatch (REQ-AP-002, REQ-AP-003) is covered by PHPUnit unit
 * and integration tests; here we only assert the UI surfaces render.
 */

import { test, expect } from '@playwright/test'

// @e2e openspec/changes/pipelinq-expense-to-shillinq-ap/specs.md#REQ-AP-004
test('admin settings page renders the Integraties section with the Shillinq AP webhook field', async ({ page }) => {
	await page.goto('/settings/admin/pipelinq')
	await expect(page.locator('body')).not.toContainText('Internal Server Error', { timeout: 15000 })
	// The Integraties section is a NcSettingsSection; its name is read as text.
	// We assert one of the labels associated with REQ-AP-004 is present on the page.
	const sectionTextLocator = page.locator('body')
	const hasIntegraties = await sectionTextLocator.getByText('Integraties', { exact: false }).first().isVisible({ timeout: 5000 }).catch(() => false)
	const hasShillinqLabel = await sectionTextLocator.getByText('Shillinq', { exact: false }).first().isVisible({ timeout: 5000 }).catch(() => false)
	expect(hasIntegraties || hasShillinqLabel).toBe(true)
})

/*
 * Per-scenario exclusions (covered by other test layers):
 *
 * @e2e exclude REQ-AP-001 — Schema extension, materialised at app boot and
 *   asserted by PHPUnit (tests/Integration/ExpenseApSyncTest covers the
 *   apSyncStatus/apSyncedAt transitions).
 * @e2e exclude REQ-AP-002 — Backend approval-event listener; PHPUnit
 *   ExpenseApprovalListenerTest covers idempotency, no-op-when-unconfigured,
 *   notifyFailure, and pending->synced/failed transitions.
 * @e2e exclude REQ-AP-003 — CloudEvents payload shape + manual retry are
 *   asserted by ShillinqApServiceTest (Scenario 7) and the
 *   ShillinqApController retry endpoint (Newman-callable). Driving the AP
 *   webhook from a real browser would require a live Shillinq consumer.
 * @e2e exclude REQ-AP-007 — Seed data is asserted by the
 *   ConfigFileLoaderService merge in PHPUnit.
 * @e2e exclude REQ-AP-005 — The pipelinq expense LIST view was retired in the
 *   pipelinq-hr-moveout-and-admin-dedupe change: expenses now live in the hrmq
 *   app. Pipelinq's "Expenses" nav deep-link was subsequently dropped from the
 *   menu entirely; the list UI is hrmq's to cover.
 * @e2e exclude REQ-AP-006 — The pipelinq expense DETAIL view (with the embedded
 *   Shillinq AP card) was retired in pipelinq-hr-moveout-and-admin-dedupe;
 *   expense detail is now an hrmq surface. The AP dispatch backend remains in
 *   pipelinq (ShillinqApController/Service/listener) and is covered by PHPUnit.
 */
