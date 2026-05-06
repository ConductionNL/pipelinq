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
})
