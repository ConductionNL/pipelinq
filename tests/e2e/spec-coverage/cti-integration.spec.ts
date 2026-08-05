/*
 * SPDX-FileCopyrightText: 2026 Pipelinq Contributors
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Gate-19 behavioral e2e coverage for the CTI integration settings page
 * (/settings/cti) and the CTI webhook event log (/settings/cti/event-log).
 * Maps to openspec/changes/cti-screenpop-adapter/specs.md.
 */
import { test, expect } from '@playwright/test'
import { openApp, navClick, trackPipelinqErrors, assertNoHardError } from '../helpers/pipelinq'

// @e2e openspec/changes/cti-screenpop-adapter/specs.md#cti-settings-page
test('CTI integration: navigates from sidebar and shows the settings surface', async ({ page }) => {
	const errs = trackPipelinqErrors(page)
	await openApp(page)
	await navClick(page, 'CTI integration', /\/settings\/cti(\?|$|\/)/)

	await expect(page.locator('#content-vue').getByRole('heading', { name: 'CTI integration' }).first()).toBeVisible()
	await assertNoHardError(page)
	expect(errs(), `pipelinq console errors: ${errs().join(' || ')}`).toEqual([])
})

// @e2e openspec/changes/cti-screenpop-adapter/specs.md#cti-settings-actions
test('CTI integration: exposes Test connection + Save controls', async ({ page }) => {
	await openApp(page)
	await navClick(page, 'CTI integration', /\/settings\/cti(\?|$|\/)/)

	const content = page.locator('#content-vue')
	await expect(content.getByRole('button', { name: 'Test connection' })).toBeVisible()
	await expect(content.getByRole('button', { name: 'Save' })).toBeVisible()
})

// @e2e openspec/changes/cti-screenpop-adapter/specs.md#cti-event-log-page
test('CTI event log: navigates from sidebar and shows the event-log surface', async ({ page }) => {
	const errs = trackPipelinqErrors(page)
	await openApp(page)
	await navClick(page, 'CTI event log', /\/settings\/cti\/event-log/)

	await expect(page.locator('#content-vue').getByRole('heading', { name: 'CTI webhook event log' }).first()).toBeVisible()
	await expect(page.locator('#content-vue').locator('table, .cn-data-table').first()).toBeVisible()
	await assertNoHardError(page)
	expect(errs(), `pipelinq console errors: ${errs().join(' || ')}`).toEqual([])
})

// @e2e openspec/changes/cti-screenpop-adapter/specs.md#cti-event-log-reload
test('CTI event log: exposes a Reload action', async ({ page }) => {
	await openApp(page)
	await navClick(page, 'CTI event log', /\/settings\/cti\/event-log/)

	await expect(page.locator('#content-vue').getByRole('button', { name: 'Reload' })).toBeVisible()
})

/*
 * Backend / data-dependent scenarios excluded — covered by PHPUnit / Newman:
 * @e2e exclude cti-test-connection-probe — outbound call to PBX; live env / mocked in PHPUnit
 * @e2e exclude cti-screenpop-on-inbound-webhook — webhook ingress; covered by Newman
 * @e2e exclude cti-credentials-persistence — server-side; covered by PHPUnit
 * @e2e exclude cti-event-log-row-detail — requires seeded webhook event
 */
