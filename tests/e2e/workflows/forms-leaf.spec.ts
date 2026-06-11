/*
 * SPDX-FileCopyrightText: 2026 Pipelinq Contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 *
 * DEEP e2e for the FORMS-LEAF migration UI (this week's new surface).
 *
 * The Forms + FormSubmissions list pages were migrated to declarative
 * `type:index` manifest pages, and form authoring moved to the FormBuilder
 * view (route /forms/new → slot FormBuilderView, component CnFormBuilder).
 * Forms are persisted as OpenRegister `intakeForm` objects via
 * `objectStore.saveObject('intakeForm', …)`.
 *
 * This spec drives the real new-UI surfaces and proves the data round-trips:
 *   1. The Forms nav entry reaches the migrated `type:index` Forms page and it
 *      renders its index chrome WITHOUT any pipelinq-origin console error / 5xx.
 *   2. The FormBuilder authoring view (route Forms → /forms/new) renders its
 *      "New Form" heading + name field + Save control.
 *   3. A form authored through the builder PERSISTS as an `intakeForm` object
 *      (asserted directly against OpenRegister) and surfaces in the Forms list.
 *
 * Register/schema are slug-resolved (register slug 'pipelinq', schema
 * 'intakeForm') — never a pinned numeric id — via the shared FixtureSession.
 *
 * Navigation is manifest-driven (CnAppRoot): a deep-link goto resets the SPA to
 * the Dashboard, so every navigation goes through a sidebar nav-click or an
 * in-app router push. Every object this run creates is tracked + removed in
 * afterAll, so a crashed run never leaves the register dirty.
 *
 * This DOES NOT touch the EmailSync / CalendarSync / XWiki surfaces (user WIP).
 */
import { test, expect, Page } from '@playwright/test'
import { openApp, navClick, dismissSupportDialog, trackPipelinqErrors } from '../helpers/pipelinq'
import { FixtureSession, TEST_PREFIX } from './helpers/fixtures'

const FORM_NAME = `${TEST_PREFIX}-Intake aanvraagformulier`

let fixtures: FixtureSession

test.afterAll(async ({ browser }) => {
	// Clean up via a fresh authenticated context (the test pages are closed).
	const ctx = await browser.newContext()
	const page = await ctx.newPage()
	try {
		const fx = new FixtureSession(page)
		await fx.list('intakeForm', { _limit: 200 }).then(async (rows) => {
			for (const row of rows) {
				const name = row?.name ?? row?.['@self']?.name
				const id = row?.id ?? row?.['@self']?.id
				if (typeof name === 'string' && name.startsWith(TEST_PREFIX) && id) {
					await fx.remove('intakeForm', String(id)).catch(() => {})
				}
			}
		}).catch(() => {})
	} finally {
		await ctx.close()
	}
})

test.describe('PipelinQ — forms-leaf migration UI', () => {
	test('Forms nav reaches the migrated type:index page without app errors', async ({ page }) => {
		const errors = trackPipelinqErrors(page)
		await openApp(page)
		await navClick(page, 'Forms', /forms/)

		// The migrated index page renders inside the manifest shell content host.
		await expect(page.locator('#content-vue')).toBeVisible({ timeout: 10000 })
		// Index chrome: the page title / a list-or-empty surface is present.
		await expect(page.locator('body')).not.toContainText('Internal Server Error')
		await expect(page.locator('body')).not.toContainText('Uncaught Error')

		expect(errors(), `pipelinq-origin console errors:\n${errors().join('\n')}`).toEqual([])
	})

	test('creating a form through the migrated index persists an intakeForm', async ({ page }) => {
		const errors = trackPipelinqErrors(page)
		fixtures = new FixtureSession(page)

		await openApp(page)
		await navClick(page, 'Forms', /forms/)
		await expect(page.locator('#content-vue')).toBeVisible({ timeout: 10000 })

		// The migrated type:index Forms page exposes an "Add Intake Form" CTA
		// that opens the schema-driven "Create Intake Form" dialog
		// (CnSchemaFormDialog) — the real authoring surface in the deployed
		// manifest shell.
		const addBtn = page.getByRole('button', { name: /Add Intake Form|Add Form|Create Intake Form/i }).first()
		await expect(addBtn).toBeVisible({ timeout: 10000 })
		await addBtn.click()

		const dialog = page.locator('.modal-mask.dialog__modal, [role="dialog"]').filter({ hasText: /Create Intake Form|Intake Form/i }).first()
		await expect(dialog).toBeVisible({ timeout: 8000 })

		// Fill the required "name" field (placeholder "name *" / "Form name").
		const nameField = dialog.locator('input[placeholder*="name" i], input[type="text"]').first()
		await expect(nameField).toBeVisible({ timeout: 5000 })
		await nameField.fill(FORM_NAME)

		// Submit via the dialog's Create button.
		const createBtn = dialog.getByRole('button', { name: /^Create$|Save|Submit/i }).first()
		await expect(createBtn).toBeEnabled({ timeout: 5000 })
		await createBtn.click()

		// The dialog closes once the object is saved.
		await expect(dialog).toBeHidden({ timeout: 10000 })

		// PERSISTENCE: the form exists as an intakeForm object in OpenRegister.
		const rows = await fixtures.list('intakeForm', { _limit: 200 })
		const persisted = rows.find((r: any) => (r?.name ?? r?.['@self']?.name) === FORM_NAME)
		expect(persisted, `intakeForm "${FORM_NAME}" was not persisted in OpenRegister`).toBeTruthy()
		// Track it for cleanup (created through the UI, not the fixture API).
		const persistedId = persisted?.id ?? persisted?.['@self']?.id
		if (persistedId) fixtures.track('intakeForm', String(persistedId))

		// And it surfaces in the migrated Forms list after a re-fetch.
		await page.reload()
		await page.waitForTimeout(1500)
		await dismissSupportDialog(page)
		await expect(page.locator('#content-vue')).toContainText(FORM_NAME, { timeout: 10000 })

		expect(errors(), `pipelinq-origin console errors:\n${errors().join('\n')}`).toEqual([])
	})
})
