/*
 * SPDX-FileCopyrightText: 2026 Pipelinq Contributors
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Gate-19 behavioural e2e coverage for openspec/specs/cti-screenpop-adapter/spec.md.
 *
 * WHAT IS OBSERVABLE THROUGH A BROWSER, AND WHAT IS NOT
 * ----------------------------------------------------
 * This capability has exactly ONE browser surface, and it is the administrator's:
 * the CTI configuration form and the webhook event log, stacked by
 * `src/views/settings/CtiPage.vue` onto the Nextcloud admin page
 * `/settings/admin/pipelinq`. Everything asserted in this file lives there.
 *
 * Everything else in the spec is driven by an inbound webhook from a telephony
 * platform that the CI instance does not provision, or by FOUR Vue components
 * that NOTHING in `src/` imports — `CtiClickToDialButton.vue` (the phone icon on
 * every phone-number field), `CtiDispositionModal.vue` (the post-call
 * disposition form), `ScreenPopModal.vue` (the multi-match chooser) and
 * `NewContactIntakeModal.vue` (the no-match intake form). All four are written,
 * none is mounted. They are product gaps, reported in the spec's `@e2e exclude`
 * lines rather than annotated away as if covered; verify with
 *   grep -rn "CtiClickToDialButton\|CtiDispositionModal\|ScreenPopModal\|NewContactIntakeModal" src/
 * which returns only the four files' own definitions.
 *
 * WHY THE ADMIN PAGE AND NOT AN IN-APP ROUTE. `src/menu-layout.json`
 * (`_adminSettingsNote`) records the 2026-07 nav-ia-cleanup decision: genuinely
 * admin configuration left the app nav entirely — "Moved there: Messaging …,
 * CTI telephony settings, Payment providers (PSP), POS tender types …". There is
 * no `#/settings/cti` route and no CTI nav entry; `CtiPage` is mounted as a
 * section on the admin page instead, gated on `isAdmin && isConfigured`. That
 * gate is load-bearing for these tests: a settings page that mounted without a
 * provisioned register renders no CTI section at all, so every assertion here
 * also fails loudly on a half-provisioned instance rather than passing over one.
 *
 * RELATIONSHIP TO cti-integration.spec.ts. That file smoke-tests the same page
 * (section renders / Test connection + Save exist / event-log table exists /
 * Reload exists). This file asserts the SCENARIOS: the full declared control
 * set, the connectivity probe actually producing a verdict, the log's declared
 * columns, its filters being applied, and its retention statement.
 */
import { test, expect, Page } from '@playwright/test'

import { openApp, nextcloudErrorPage, assertNoHardError } from '../helpers/pipelinq'

/** The Nextcloud admin settings page that hosts pipelinq's admin sections. */
const ADMIN_SETTINGS = '/settings/admin/pipelinq'

/**
 * Open the pipelinq admin settings page and return the `#pipelinq-settings`
 * root once it has mounted.
 *
 * The HTTP status is asserted here rather than left implicit: `#pipelinq-settings`
 * simply not appearing is what BOTH a 404 and a Vue mount failure look like, and
 * only the status code separates them. `nextcloudErrorPage()` covers the third
 * case — NC serving its own error chrome instead of the settings page.
 */
async function openAdminSettings(page: Page) {
	const response = await page.goto(ADMIN_SETTINGS)
	expect(response, 'the admin settings page produced no response').not.toBeNull()
	expect(response?.status(), 'the pipelinq admin settings page must be served').toBe(200)
	await expect(nextcloudErrorPage(page)).toHaveCount(0)

	const section = page.locator('#pipelinq-settings')
	await expect(section).toBeVisible({ timeout: 15000 })
	return section
}

/**
 * The CTI configuration form. Scoped by `data-testid="cti-settings"`, which
 * CtiSettings.vue sets on its `<form>` precisely because the admin page carries
 * a SECOND "Test connection" button (XWiki's) — an unscoped match would let the
 * whole CTI section disappear while the assertion stayed green on XWiki's.
 */
function ctiSettings(section: ReturnType<Page['locator']>) {
	return section.locator('[data-testid="cti-settings"]')
}

// @e2e openspec/specs/cti-screenpop-adapter/spec.md#admin-accesses-cti-settings-page
test('CTI config: the admin form exposes every declared configuration control', async ({ page }) => {
	const section = await openAdminSettings(page)
	const cti = ctiSettings(section)
	await expect(cti).toBeVisible({ timeout: 15000 })

	// The scenario enumerates the sections the form MUST offer. Each is asserted
	// by its rendered label rather than by input order, so re-ordering the form
	// is free but DROPPING a control fails. `credentials_ref` is the
	// "Credentials selector" of the scenario: CtiSettings.vue implements it as an
	// OpenConnector source reference field rather than as a populated dropdown +
	// "Link credentials" button, which is noted here rather than asserted as if
	// the dropdown existed.
	for (const label of [
		'Platform',
		'API base URL',
		'Auth method',
		'OpenConnector credential reference',
		'Default outbound caller ID',
		'Screen-pop delay (ms)',
		'Default country code (ISO-3166)',
		'Enable inbound screen-pop',
		'Enable outbound click-to-dial',
	]) {
		await expect(cti.getByText(label, { exact: true }).first(), `CTI config control "${label}"`)
			.toBeVisible({ timeout: 15000 })
	}

	await assertNoHardError(page)
})

// @e2e openspec/specs/cti-screenpop-adapter/spec.md#admin-tests-platform-connectivity
test('CTI config: "Test connection" runs the probe and reports a verdict', async ({ page }) => {
	const section = await openAdminSettings(page)
	const cti = ctiSettings(section)
	await expect(cti).toBeVisible({ timeout: 15000 })

	// Nothing is rendered in `.cti-settings__status` until the probe has run —
	// CtiSettings.vue initialises `status: ''` and clears it again at the top of
	// `test()`. So its appearance is a positive signal that the round-trip to
	// `GET /api/cti/test-connection` completed, not merely that a paragraph exists.
	const status = cti.locator('.cti-settings__status')
	await expect(status).toHaveCount(0)

	await cti.getByRole('button', { name: 'Test connection' }).click()

	// The verdict is asserted as "a non-empty verdict", deliberately, and not as
	// one particular sentence. CtiService::testConnection() resolves the
	// configured adapter and reports success or the failure reason; which branch a
	// given instance takes depends on whether a platform has been configured on
	// it, and BOTH branches are the scenario being satisfied ("✓ Connected …" /
	// "✗ Connection failed: [error message]"). What would NOT satisfy it is the
	// button doing nothing, which is exactly what this fails on.
	await expect(status).toBeVisible({ timeout: 20000 })
	await expect(status).not.toHaveText('')

	await assertNoHardError(page)
})

// @e2e openspec/specs/cti-screenpop-adapter/spec.md#event-log-displays-webhook-history
test('CTI event log: the webhook history table renders with its declared columns', async ({ page }) => {
	const section = await openAdminSettings(page)

	await expect(section.getByText('CTI webhook event log').first()).toBeVisible({ timeout: 15000 })

	const table = section.locator('[data-testid="cti-event-log-table"]')
	await expect(table).toBeVisible({ timeout: 15000 })

	// SPEC/IMPLEMENTATION DIVERGENCE, reported not fixed: the scenario asks for a
	// single "Status (✓ Processed | ✗ Error)" column; CtiEventLog.vue renders the
	// same information as TWO columns, "Signature" (✓/✗) and "Error". The columns
	// asserted below are the ones the implementation actually guarantees.
	for (const header of ['Received at', 'Platform', 'Event type', 'Call ID', 'Signature', 'Error']) {
		await expect(table.locator('thead th').filter({ hasText: header }).first(), `column "${header}"`)
			.toBeVisible()
	}

	// Newest-first ordering is a server-side `sort: ['received_at' => 'desc']` in
	// CtiService::listEventLog() over rows only an inbound platform webhook can
	// create, and the CI instance provisions no platform and seeds no ctiEventLog
	// objects (lib/Settings/register.d/70-cti.json has no `components.objects[]`).
	// So the table's DATA state is asserted as "settled into one of its two legal
	// states" — a populated body or the explicit empty row — rather than as a row
	// count that would only ever be reachable on a live telephony instance.
	await expect(
		table.locator('tbody tr td.cti-event-log__actions')
			.or(table.getByText('No webhook events in the selected range.'))
			.first(),
	).toBeVisible({ timeout: 15000 })

	await assertNoHardError(page)
})

// @e2e openspec/specs/cti-screenpop-adapter/spec.md#event-log-filters-by-platform-and-event-type
test('CTI event log: platform and event-type filters are applied to the log', async ({ page }) => {
	const section = await openAdminSettings(page)

	const filters = section.locator('.cti-event-log__filters')
	await expect(filters).toBeVisible({ timeout: 15000 })
	await expect(filters.getByText('Platform', { exact: true }).first()).toBeVisible()
	await expect(filters.getByText('Event type', { exact: true }).first()).toBeVisible()

	const table = section.locator('[data-testid="cti-event-log-table"]')
	await expect(table).toBeVisible({ timeout: 15000 })

	// Each NcSelect is reached by the label INSIDE its own `.v-select` root, not
	// by position — the pattern workflows/client-crud.spec.ts already uses against
	// @nextcloud/vue 9 ("Client type"). Position would silently pick the wrong
	// control if the filter row ever gained a third select.
	const platformSelect = filters.locator('.v-select').filter({ hasText: /Platform/i }).first()
	const eventTypeSelect = filters.locator('.v-select').filter({ hasText: /Event type/i }).first()

	// NcSelect appends its dropdown to the document body, so the option list is
	// matched page-wide rather than inside the section — the same pattern
	// spec-coverage/appointment-booking.spec.ts uses for the client-type select.
	await platformSelect.click()
	await page.locator('li[role="option"], .vs__dropdown-option')
		.filter({ hasText: 'CallVoip' }).first().click()

	// The selection is held (so `filters.platform` really changed), which is what
	// the component's `watch` keys off to re-issue `GET /api/cti/event-log` with
	// `platform=callvoip`.
	await expect(filters.getByText('CallVoip').first()).toBeVisible({ timeout: 10000 })

	await eventTypeSelect.click()
	await page.locator('li[role="option"], .vs__dropdown-option')
		.filter({ hasText: 'answered' }).first().click()
	await expect(filters.getByText('answered').first()).toBeVisible({ timeout: 10000 })

	// The refetch completed rather than hanging: the Reload control is back to its
	// idle label ("Reloading…" while `loading` is true), and the table is still
	// mounted underneath the applied filters.
	await expect(filters.getByRole('button', { name: 'Reload' })).toBeVisible({ timeout: 20000 })
	await expect(table).toBeVisible()

	await assertNoHardError(page)
})

// @e2e openspec/specs/cti-screenpop-adapter/spec.md#event-log-retention-is-30-days
test('CTI event log: the view states its 30-day retention window', async ({ page }) => {
	const section = await openAdminSettings(page)

	const table = section.locator('[data-testid="cti-event-log-table"]')
	await expect(table).toBeVisible({ timeout: 15000 })

	// The scenario's assertion is literally that the view SAYS so — "the view
	// shows: 'Showing events from the last 30 days.'" — which is the one half of
	// this requirement a browser can settle. The other half (that rows older than
	// 30 days are absent) is enforced by lib/BackgroundJob/CtiEventLogCleanupJob.php
	// against rows no browser session can create.
	await expect(section.getByText('Showing events from the last 30 days.')).toBeVisible({ timeout: 15000 })
	// The section's own description carries the same window, so a stale note left
	// behind after a retention change cannot pass alone.
	await expect(section.getByText('Last 30 days of inbound webhook events grouped by platform.')).toBeVisible()

	await assertNoHardError(page)
})

/*
 * Not anchored to a scenario, deliberately.
 *
 * The spec's last requirement ("CTI administration on one Settings page") is
 * carried at requirement level by an `@e2e exclude` because both of its
 * scenarios name locations that no longer exist: a "CTI (telephony)" entry in
 * the app's left-nav Settings section, and the legacy route
 * `/settings/cti/event-log`. Neither survived nav-ia-cleanup — the app has no
 * router of its own and no manifest page declares a CTI route.
 *
 * What the requirement is actually FOR, though, does hold and is worth a guard:
 * the two former Administration entries were MERGED, so exactly one page carries
 * both the configuration and the event log, and neither reappears as a separate
 * nav entry. That is asserted here. Splitting them again — or leaving a stray
 * "CTI integration" leaf behind in the app nav — fails this test.
 */
test('CTI administration is ONE page: config + event log together, and no split nav entries', async ({ page }) => {
	await openApp(page)

	// `revealNavEntry()` is deliberately NOT used: it waits for an entry to become
	// attached, and the assertion here is that no such entry exists at all. A
	// count over every nav link — collapsed groups included, since they are in the
	// DOM either way — is the correct shape for a negative claim.
	for (const stale of ['CTI integration', 'CTI event log', 'CTI (telephony)']) {
		await expect(
			page.locator('#app-navigation-vue a').filter({ hasText: stale }),
			`"${stale}" must not be a separate app-nav entry`,
		).toHaveCount(0)
	}

	const section = await openAdminSettings(page)
	// Exactly one of each, on the same page: the merge, stated as a count.
	await expect(section.locator('[data-testid="cti-settings"]')).toHaveCount(1)
	await expect(section.locator('[data-testid="cti-event-log-table"]')).toHaveCount(1)
	await expect(section.getByText('CTI integration').first()).toBeVisible({ timeout: 15000 })
	await expect(section.getByText('CTI webhook event log').first()).toBeVisible()

	await assertNoHardError(page)
})
