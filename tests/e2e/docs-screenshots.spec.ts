/*
 * SPDX-FileCopyrightText: 2026 Pipelinq Contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 *
 * Documentation screenshot capture suite — pipelinq.
 *
 * This spec is *not* a regression test. It drives the Pipelinq UI
 * through every flow documented under
 * `docs/tutorials/{user,admin}/*.md` and writes a fresh PNG into
 * `docs/static/screenshots/tutorials/<track>/<file>.png` for each
 * step the markdown references.
 *
 * Run manually whenever the UI changes and tutorial screenshots need
 * to be refreshed:
 *
 *     NEXTCLOUD_URL=http://localhost:8080 \
 *       npx playwright test --project docs-capture
 *
 * Excluded from the default `npm run test:e2e` via the `docs-capture`
 * project flag in `playwright.config.ts` so PR pipelines don't
 * reshoot on every push.
 *
 * The tests below are SKELETONS — selectors are TODOs the team fills
 * in once the relevant Vue components have stable `data-testid`
 * attributes. Use `/journeydoc-instrument <file>` to add testids
 * before writing the spec body.
 *
 * Pattern reference: ADR-030 (hydra/openspec/architecture/).
 */

import { test, type Page } from '@playwright/test'
import * as path from 'path'
import * as fs from 'fs'

const SHOT_ROOT = path.resolve(__dirname, '..', '..', 'docs', 'static', 'screenshots', 'tutorials')

/**
 * Save a screenshot under
 * `docs/static/screenshots/tutorials/<track>/<file>`.
 */
async function shoot(page: Page, track: 'user' | 'admin', file: string): Promise<void> {
	const dir = path.join(SHOT_ROOT, track)
	if (!fs.existsSync(dir)) {
		fs.mkdirSync(dir, { recursive: true })
	}
	await page.screenshot({
		path: path.join(dir, file),
		fullPage: false,
		type: 'png',
	})
}

test.describe.configure({ mode: 'default' })

test.beforeEach(async ({ page }) => {
	page.setViewportSize({ width: 1280, height: 800 })
	await page.goto('/apps/pipelinq/')
})

// ---------------------------------------------------------------------------
// USER TRACK — see docs/tutorials/user/
// ---------------------------------------------------------------------------

test.describe('docs: user track', () => {
	test('U1 first launch — overview', async ({ page }) => {
		// docs/tutorials/user/01-first-launch.md
		// TODO: capture each numbered step. Add data-testids first via
		// `/journeydoc-instrument`.
		await shoot(page, 'user', '01-first-launch.png')
	})

	test('U2 add a new client — button + form + saved', async ({ page }) => {
		// docs/tutorials/user/02-add-client.md
		// TODO: open Clients view, click + Add, fill form, save
		await shoot(page, 'user', '02-add-client-button.png')
		// await page.locator('[data-testid="add-client"]').click()
		// await shoot(page, 'user', '02-type-picker.png')
		// await shoot(page, 'user', '02-form-filled.png')
		// await shoot(page, 'user', '02-saved.png')
	})

	test('U3 link contact person to organisation', async ({ page }) => {
		// docs/tutorials/user/03-link-contact-person.md
		// TODO: open org detail, contacts tab, link picker
		await shoot(page, 'user', '03-org-detail.png')
	})

	test('U4 move a lead through the pipeline', async ({ page }) => {
		// docs/tutorials/user/04-move-lead.md
		// TODO: open pipeline, drag a lead, screenshot mid-drag and after
		await shoot(page, 'user', '04-pipeline-view.png')
	})

	test('U5 log a contact moment', async ({ page }) => {
		// docs/tutorials/user/05-log-contact-moment.md
		await shoot(page, 'user', '05-add-button.png')
	})

	test('U6 capture a request', async ({ page }) => {
		// docs/tutorials/user/06-capture-request.md
		await shoot(page, 'user', '06-mywork.png')
	})

	test('U7 sync with Nextcloud Contacts', async ({ page }) => {
		// docs/tutorials/user/07-sync-contacts.md
		await shoot(page, 'user', '07-settings.png')
	})

	test('U8 resolve a duplicate-detection warning', async ({ page }) => {
		// docs/tutorials/user/08-resolve-duplicate.md
		// Setup: trigger a duplicate by attempting to create a clone
		await shoot(page, 'user', '08-warning.png')
	})

	test('U9 client-360-view — REPLACE WITH ACTUAL FLOW', async ({ page }) => {
		// docs/tutorials/user/09-client-360-view.md
		// Step 1: open the client's detail page (the 360° / klantbeeld view)
		// await page.locator('[data-testid="client-row"]').first().click()
		await shoot(page, 'user', '09-klantbeeld.png')

		// Step 3: scan the panels (contacts, pipeline, requests, timeline, callbacks)
		// await page.locator('[data-testid="client-panels"]').scrollIntoViewIfNeeded()
		// await shoot(page, 'user', '09-panels.png')
	})

	test('U10 callbacks — REPLACE WITH ACTUAL FLOW', async ({ page }) => {
		// docs/tutorials/user/10-callbacks.md
		// Step 2: on the client detail page, click "+ Schedule callback"
		// await page.locator('[data-testid="schedule-callback"]').click()
		await shoot(page, 'user', '10-schedule.png')

		// Step 3: set when / who / note
		// await shoot(page, 'user', '10-form.png')

		// Step 5: work it off the My Work queue, mark done
		// await page.goto('/apps/pipelinq/#/my-work')
		// await shoot(page, 'user', '10-work.png')
	})

	test('U11 register-complaint — REPLACE WITH ACTUAL FLOW', async ({ page }) => {
		// docs/tutorials/user/11-register-complaint.md
		// Step 2: choose "Register complaint" (My Work "+ New request" type=complaint, or "+ Add" menu)
		// await page.locator('[data-testid="register-complaint"]').click()
		await shoot(page, 'user', '11-intake.png')

		// Step 3: fill the complaint form
		// await shoot(page, 'user', '11-form.png')

		// Step 4: submit and route to a handler
		// await shoot(page, 'user', '11-route.png')
	})

	test('U12 dashboard — REPLACE WITH ACTUAL FLOW', async ({ page }) => {
		// docs/tutorials/user/12-dashboard.md
		// Step 1: open Pipelinq (dashboard is the default landing page)
		// await page.goto('/apps/pipelinq/#/dashboard')
		await shoot(page, 'user', '12-dashboard.png')

		// Step 2: read the headline widgets (pipeline, requests, callbacks today, recent activity)
		// await page.locator('[data-testid="dashboard-widgets"]').scrollIntoViewIfNeeded()
		// await shoot(page, 'user', '12-widgets.png')
	})
})

// ---------------------------------------------------------------------------
// ADMIN TRACK — see docs/tutorials/admin/
// ---------------------------------------------------------------------------

test.describe('docs: admin track', () => {
	test.beforeEach(async ({ page }) => {
		await page.goto('/settings/admin/pipelinq')
		await page.waitForLoadState('networkidle')
	})

	test('A1 configure pipeline stages', async ({ page }) => {
		// docs/tutorials/admin/01-pipeline-stages.md
		await shoot(page, 'admin', '01-admin-settings.png')
		// TODO: scroll the Pipeline stages section, screenshot the editor
	})

	test('A2 configure request types', async ({ page }) => {
		// docs/tutorials/admin/02-request-types.md
		// TODO: scroll to Request types section
		await shoot(page, 'admin', '02-request-types.png')
	})

	test('A3 manage user / group permissions', async ({ page }) => {
		// docs/tutorials/admin/03-permissions.md
		// TODO: scroll to Permissions / Roles section, role-add modal
		await shoot(page, 'admin', '03-permissions.png')
	})

	test('A4 configure-automation — REPLACE WITH ACTUAL FLOW', async ({ page }) => {
		// docs/tutorials/admin/04-configure-automation.md
		// Step 1: open Pipelinq admin settings
		await shoot(page, 'admin', '04-admin-settings.png')

		// Step 3: define handling states per request type
		// await page.locator('[data-testid="workflow-states"]').scrollIntoViewIfNeeded()
		// await shoot(page, 'admin', '04-states.png')

		// Step 4: add an automation rule (trigger / condition / action)
		// await page.locator('[data-testid="add-automation-rule"]').click()
		// await shoot(page, 'admin', '04-rule.png')
	})

	test('A5 configure-sync — REPLACE WITH ACTUAL FLOW', async ({ page }) => {
		// docs/tutorials/admin/05-configure-sync.md
		// Step 1: open Pipelinq admin settings
		await shoot(page, 'admin', '05-admin-settings.png')

		// Step 3: choose eligible address books
		// await page.locator('[data-testid="address-book-picker"]').scrollIntoViewIfNeeded()
		// await shoot(page, 'admin', '05-address-books.png')

		// Step 4: enable calendar sync for callbacks
		// await shoot(page, 'admin', '05-calendar.png')
	})

	test('A6 admin-settings — REPLACE WITH ACTUAL FLOW', async ({ page }) => {
		// docs/tutorials/admin/06-admin-settings.md
		// Step 1: open Pipelinq admin settings
		await shoot(page, 'admin', '06-overview.png')

		// Step 2: check the OpenRegister wiring (register / schema selectors)
		// await page.locator('[data-testid="register-settings"]').scrollIntoViewIfNeeded()
		// await shoot(page, 'admin', '06-register.png')

		// Step 3: review the global options
		// await shoot(page, 'admin', '06-options.png')
	})
})
