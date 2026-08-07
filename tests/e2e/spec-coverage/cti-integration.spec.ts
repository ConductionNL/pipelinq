/*
 * SPDX-FileCopyrightText: 2026 Pipelinq Contributors
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Gate-19 behavioral e2e coverage for the CTI screen-pop adapter
 * (openspec/changes/cti-screenpop-adapter/specs.md).
 *
 * RETARGETED 2026-08-06 — there is no `/settings/cti` in-app route and no "CTI
 * integration" / "CTI event log" sidebar entry. src/menu-layout.json records the
 * decision:
 *
 *   "Genuinely-admin configuration no longer lives in the app nav at all — not
 *    even in the gear foldout … It lives on the Nextcloud admin page
 *    (/settings/admin/pipelinq …). Moved there: Messaging …, CTI telephony
 *    settings, Payment providers (PSP), POS tender types, POS medewerkers, POS
 *    rollen. Their in-app pages and nav entries are gone; the Vue components are
 *    mounted as sections on the admin page instead."
 *
 * Concretely: `src/views/settings/Settings.vue` renders `<CtiPage>` (guarded by
 * `isAdmin && isConfigured`), and CtiPage stacks `CtiSettings` (name "CTI
 * integration", Test connection + Save) over `CtiEventLog` (name "CTI webhook
 * event log", Reload + `[data-testid="cti-event-log-table"]`). All four tests
 * previously died in the sidebar navigation helper.
 *
 * These run against the NC admin page, which is where an administrator actually
 * goes — and because both sections are gated on `isConfigured`, a failure here
 * also catches a settings page that mounted without a provisioned register.
 */
import { test, expect } from '@playwright/test'
import { nextcloudErrorPage } from '../helpers/pipelinq'

const ADMIN_SETTINGS = '/settings/admin/pipelinq'

// @e2e openspec/changes/cti-screenpop-adapter/specs.md#cti-settings-page
test('CTI integration: the settings section renders on the pipelinq admin page', async ({ page }) => {
	const response = await page.goto(ADMIN_SETTINGS)
	expect(response?.status(), 'admin settings page must be served').toBe(200)
	await expect(nextcloudErrorPage(page)).toHaveCount(0)

	const section = page.locator('#pipelinq-settings')
	await expect(section).toBeVisible({ timeout: 15000 })
	await expect(section.getByText('CTI integration').first()).toBeVisible({ timeout: 15000 })
})

// @e2e openspec/changes/cti-screenpop-adapter/specs.md#cti-settings-actions
test('CTI integration: exposes Test connection + Save controls', async ({ page }) => {
	await page.goto(ADMIN_SETTINGS)
	const section = page.locator('#pipelinq-settings')
	await expect(section).toBeVisible({ timeout: 15000 })

	// SCOPED 2026-08-06 — page-wide this was a strict-mode violation: the admin
	// page carries TWO "Test connection" buttons, CtiSettings' and the XWiki
	// section's (Settings.vue line ~229). Matching both and taking `.first()`
	// would have been worse than the error: whichever section happens to render
	// first would have carried the assertion, so the CTI section could vanish
	// entirely and this test would still be green. Scope to the section whose
	// NcSettingsSection is named "CTI integration" instead.
	const cti = section.locator('[data-testid="cti-settings"]')
	await expect(cti).toBeVisible({ timeout: 15000 })
	await expect(cti.getByRole('button', { name: 'Test connection' })).toBeVisible({ timeout: 15000 })
	await expect(cti.getByRole('button', { name: 'Save', exact: true }).first()).toBeVisible()
})

// @e2e openspec/changes/cti-screenpop-adapter/specs.md#cti-event-log-page
test('CTI event log: the event-log section and its table render', async ({ page }) => {
	await page.goto(ADMIN_SETTINGS)
	const section = page.locator('#pipelinq-settings')
	await expect(section).toBeVisible({ timeout: 15000 })

	await expect(section.getByText('CTI webhook event log').first()).toBeVisible({ timeout: 15000 })
	// CtiEventLog.vue renders its own table with a stable test id, so this is a
	// positive signal about THAT component rather than about any table.
	await expect(section.locator('[data-testid="cti-event-log-table"]')).toBeVisible()
})

// @e2e openspec/changes/cti-screenpop-adapter/specs.md#cti-event-log-reload
test('CTI event log: exposes a Reload action', async ({ page }) => {
	await page.goto(ADMIN_SETTINGS)
	const section = page.locator('#pipelinq-settings')
	await expect(section).toBeVisible({ timeout: 15000 })

	await expect(section.getByRole('button', { name: 'Reload' })).toBeVisible({ timeout: 15000 })
})

/*
 * Backend / data-dependent scenarios excluded — covered by PHPUnit / Newman:
 * @e2e exclude cti-test-connection-probe — outbound call to PBX; live env / mocked in PHPUnit
 * @e2e exclude cti-screenpop-on-inbound-webhook — webhook ingress; covered by Newman
 * @e2e exclude cti-credentials-persistence — server-side; covered by PHPUnit
 * @e2e exclude cti-event-log-row-detail — requires seeded webhook event
 */
