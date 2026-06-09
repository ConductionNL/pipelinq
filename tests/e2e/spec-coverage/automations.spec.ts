/*
 * SPDX-FileCopyrightText: 2026 Pipelinq Contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 *
 * Gate-19 behavioral e2e coverage for the Automations page (/automations).
 * Maps to openspec/specs/crm-workflow-automation/spec.md.
 *
 * KNOWN BUG (confirmed 2026-06-09): the automations list never registers its
 * object type in the cn-vue store, so the page logs:
 *   Error: Object type "automation" is not registered in the store.
 *   Call registerObjectType('automation', schemaId, registerId) first.
 * The index chrome renders but the data table stays empty / no heading. The
 * data-surface assertion is a test.fixme until src/registry.js registers
 * `automation`.
 */
import { test, expect } from '@playwright/test'
import { openApp, navClick, assertNoHardError } from '../helpers/pipelinq'

// @e2e openspec/specs/crm-workflow-automation/spec.md#automations-page
test('Automations: navigates from sidebar without a hard server error', async ({ page }) => {
	await openApp(page)
	await navClick(page, 'Automations', /\/automations/)

	await assertNoHardError(page)
	await expect(page.locator('[data-testid="cn-index-page"]').first()).toBeVisible()
})

// @e2e openspec/specs/crm-workflow-automation/spec.md#automations-add-item
test('Automations: primary "Add Item" action is present', async ({ page }) => {
	await openApp(page)
	await navClick(page, 'Automations', /\/automations/)

	await expect(page.locator('#content-vue').getByRole('button', { name: 'Add Item' })).toBeVisible()
})

// @e2e openspec/specs/crm-workflow-automation/spec.md#automations-list
test.fixme('Automations: rule list data surface renders', async ({ page }) => {
	// BUG: object type "automation" is not registered in the cn-vue store, so
	// the collection fetch throws and the table never populates. Re-enable once
	// src/registry.js calls registerObjectType('automation', ...).
	await openApp(page)
	await navClick(page, 'Automations', /\/automations/)
	await expect(page.locator('#content-vue').getByRole('heading').first()).toBeVisible()
	await expect(page.locator('#content-vue table, #content-vue .cn-data-table').first()).toBeVisible()
})

/*
 * Backend / data-dependent scenarios excluded — covered by PHPUnit / Newman:
 * @e2e exclude automation-trigger-fires — BackgroundJob; covered by PHPUnit
 * @e2e exclude automation-condition-evaluation — server-side; covered by PHPUnit
 * @e2e exclude automation-detail-view — requires seeded record + store registration
 */
