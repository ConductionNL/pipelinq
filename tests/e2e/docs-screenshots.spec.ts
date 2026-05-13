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
 * Authentication: `playwright.config.ts` wires `globalSetup` (a one-time
 * Nextcloud login → storage state) and `use.storageState`, so the
 * `page` fixture here arrives already signed in.
 *
 * Data dependency: Pipelinq stores clients / leads / requests / contact
 * moments / callbacks / complaints in OpenRegister. On an instance
 * with no seed data the list views still render (empty state) and
 * the *Add Item* dialog still opens, so structural screenshots
 * capture cleanly. Flow-detail screenshots (a populated 360° view,
 * a mid-drag pipeline card, a populated client timeline) need real
 * objects; until seed data lands those steps fall back to the
 * relevant list/empty-state view, and the markdown pages that
 * reference the as-yet-uncaptured PNGs warn under
 * `onBrokenMarkdownImages: 'warn'` rather than failing the docs build.
 *
 * Pattern reference: ADR-030 (hydra/openspec/architecture/).
 */

import { test, expect, type Page } from '@playwright/test'
import * as path from 'path'
import * as fs from 'fs'

const SHOT_ROOT = path.resolve(__dirname, '..', '..', 'docs', 'static', 'screenshots', 'tutorials')
const APP = '/apps/pipelinq'

/**
 * Save a screenshot under
 * `docs/static/screenshots/tutorials/<track>/<file>`.
 * Lives under `static/` so Docusaurus copies the PNG into the build
 * root — markdown image refs use `/screenshots/...` (root-absolute).
 */
async function shoot(page: Page, track: 'user' | 'admin', file: string): Promise<void> {
	const dir = path.join(SHOT_ROOT, track)
	if (!fs.existsSync(dir)) {
		fs.mkdirSync(dir, { recursive: true })
	}
	await page.screenshot({ path: path.join(dir, file), fullPage: false, type: 'png' })
}

/**
 * Dismiss anything that overlays the app chrome before we try to click —
 * chiefly Nextcloud's first-run wizard modal, but also any leftover
 * dialog. Best-effort: silently no-op when nothing's there.
 */
async function dismissOverlays(page: Page): Promise<void> {
	const wizard = page.locator('#firstrunwizard')
	if (await wizard.isVisible().catch(() => false)) {
		const close = wizard.getByRole('button', { name: /close|got it|finish|skip/i }).first()
		if (await close.isVisible().catch(() => false)) {
			await close.click().catch(() => {})
		} else {
			await page.keyboard.press('Escape').catch(() => {})
		}
		await wizard.waitFor({ state: 'hidden', timeout: 4000 }).catch(() => {})
	}
	const stray = page.locator('[role="dialog"]:not(#firstrunwizard)')
	if (await stray.first().isVisible().catch(() => false)) {
		await page.keyboard.press('Escape').catch(() => {})
		await page.waitForTimeout(300)
	}
}

/** Navigate to a Pipelinq route (relative joins APP) or an absolute /apps/... NC route. */
async function go(page: Page, route: string): Promise<void> {
	let url: string
	if (route.startsWith('/apps/')) {
		url = route
	} else if (route === '' || route === '/') {
		url = APP
	} else {
		const tail = route.startsWith('/') ? route : `/${route}`
		url = `${APP}${tail}`
	}
	await page.goto(url).catch(() => { /* tolerate a 404 — caller decides */ })
	await page.waitForLoadState('networkidle').catch(() => { /* idle never fires on some pages */ })
	await dismissOverlays(page)
	await page.waitForTimeout(900)
}

/**
 * Open the create dialog on a list view ("Add Item") if the button is
 * present, screenshot it, and close it again. Returns whether the
 * dialog appeared.
 */
async function captureCreateDialog(page: Page, track: 'user' | 'admin', file: string): Promise<boolean> {
	const addBtn = page.getByRole('button', { name: /Add Item/i }).first()
	if (!(await addBtn.isVisible().catch(() => false))) {
		return false
	}
	await addBtn.click().catch(() => {})
	const dialog = page.locator('[role="dialog"]:not(#firstrunwizard)').first()
	await dialog.waitFor({ state: 'visible', timeout: 5000 }).catch(() => { /* no dialog */ })
	await page.waitForTimeout(400)
	await shoot(page, track, file)
	const cancel = dialog.getByRole('button', { name: /Cancel/i }).first()
	if (await cancel.isVisible().catch(() => false)) {
		await cancel.click().catch(() => {})
	} else {
		await page.keyboard.press('Escape').catch(() => {})
	}
	await page.waitForTimeout(300)
	return true
}

test.describe.configure({ mode: 'default' })

test.beforeEach(async ({ page }) => {
	page.setViewportSize({ width: 1280, height: 800 })
})

// ---------------------------------------------------------------------------
// USER TRACK — see docs/tutorials/user/
// ---------------------------------------------------------------------------

test.describe('docs: user track', () => {
	test('U1 first launch — overview', async ({ page }) => {
		// docs/tutorials/user/01-first-launch.md
		await go(page, '')
		await shoot(page, 'user', '01-first-launch.png')
		await shoot(page, 'user', '01-navigation.png')
		await shoot(page, 'user', '01-search.png')
		expect(page.url()).toContain('/apps/pipelinq')
	})

	test('U2 add a new client', async ({ page }) => {
		// docs/tutorials/user/02-add-client.md
		await go(page, '/clients')
		await shoot(page, 'user', '02-add-client-button.png')
		const had = await captureCreateDialog(page, 'user', '02-type-picker.png')
		if (!had) {
			await shoot(page, 'user', '02-type-picker.png')
		}
		// TODO: capture form-filled + saved client once a seed client exists.
		await shoot(page, 'user', '02-form-filled.png')
		await go(page, '/clients')
		await shoot(page, 'user', '02-saved.png')
	})

	test('U3 link a contact person to an organisation', async ({ page }) => {
		// docs/tutorials/user/03-link-contact-person.md — needs both a
		// person and an org as clients; lists stand in until seed lands.
		await go(page, '/clients')
		await shoot(page, 'user', '03-org-detail.png')
		await go(page, '/contacts')
		await shoot(page, 'user', '03-contacts-tab.png')
		const had = await captureCreateDialog(page, 'user', '03-link-picker.png')
		if (!had) {
			await shoot(page, 'user', '03-link-picker.png')
		}
	})

	test('U4 move a lead through the pipeline', async ({ page }) => {
		// docs/tutorials/user/04-move-lead.md — drag-and-drop needs lead
		// cards on the board; capture pipeline + leads list as stand-ins.
		await go(page, '/pipeline')
		await shoot(page, 'user', '04-pipeline-view.png')
		// TODO: capture mid-drag, dropped, and inline-edit once a lead
		// card exists on the board.
		await shoot(page, 'user', '04-mid-drag.png')
		await shoot(page, 'user', '04-dropped.png')
		await go(page, '/leads')
		await shoot(page, 'user', '04-inline-edit.png')
	})

	test('U5 log a contact moment', async ({ page }) => {
		// docs/tutorials/user/05-log-contact-moment.md
		await go(page, '/contactmomenten')
		await shoot(page, 'user', '05-add-button.png')
		const had = await captureCreateDialog(page, 'user', '05-form.png')
		if (!had) {
			await shoot(page, 'user', '05-form.png')
		}
		await go(page, '/contactmomenten')
		await shoot(page, 'user', '05-saved.png')
	})

	test('U6 capture a request from My Work', async ({ page }) => {
		// docs/tutorials/user/06-capture-request.md
		await go(page, '/my-work')
		await shoot(page, 'user', '06-mywork.png')
		await go(page, '/requests')
		const had = await captureCreateDialog(page, 'user', '06-form.png')
		if (!had) {
			await shoot(page, 'user', '06-form.png')
		}
		await go(page, '/requests')
		await shoot(page, 'user', '06-triage.png')
	})

	test('U7 sync with Nextcloud Contacts', async ({ page }) => {
		// docs/tutorials/user/07-sync-contacts.md — settings live under
		// /index.php/settings/user/pipelinq (personal settings panel) or
		// the admin settings page. Capture the admin page as stand-in
		// for the per-user surface until that route is wired in dev.
		await page.goto('/index.php/settings/admin/pipelinq')
		await page.waitForLoadState('networkidle').catch(() => {})
		await dismissOverlays(page)
		await page.waitForTimeout(900)
		await shoot(page, 'user', '07-settings.png')
		await shoot(page, 'user', '07-address-book.png')
		await shoot(page, 'user', '07-sync-on.png')
	})

	test('U8 resolve a duplicate-detection warning', async ({ page }) => {
		// docs/tutorials/user/08-resolve-duplicate.md — needs two near-
		// duplicate clients; capture the Clients list + add dialog as
		// stand-in (the warning banner only fires on a likely match).
		await go(page, '/clients')
		await shoot(page, 'user', '08-warning.png')
		const had = await captureCreateDialog(page, 'user', '08-actions.png')
		if (!had) {
			await shoot(page, 'user', '08-actions.png')
		}
	})

	test('U9 client 360° view', async ({ page }) => {
		// docs/tutorials/user/09-client-360-view.md — needs a client
		// detail page; capture the Clients list as stand-in.
		await go(page, '/clients')
		await shoot(page, 'user', '09-klantbeeld.png')
		await shoot(page, 'user', '09-panels.png')
	})

	test('U10 schedule and handle a callback', async ({ page }) => {
		// docs/tutorials/user/10-callbacks.md — callbacks are tasks with
		// type=Callback; capture the Tasks list + add-dialog as stand-in.
		await go(page, '/tasks')
		await shoot(page, 'user', '10-schedule.png')
		const had = await captureCreateDialog(page, 'user', '10-form.png')
		if (!had) {
			await shoot(page, 'user', '10-form.png')
		}
		await go(page, '/my-work')
		await shoot(page, 'user', '10-work.png')
	})

	test('U11 register a complaint', async ({ page }) => {
		// docs/tutorials/user/11-register-complaint.md
		await go(page, '/complaints')
		await shoot(page, 'user', '11-intake.png')
		const had = await captureCreateDialog(page, 'user', '11-form.png')
		if (!had) {
			await shoot(page, 'user', '11-form.png')
		}
		await go(page, '/complaints')
		await shoot(page, 'user', '11-route.png')
	})

	test('U12 dashboard', async ({ page }) => {
		// docs/tutorials/user/12-dashboard.md
		await go(page, '')
		await shoot(page, 'user', '12-dashboard.png')
		await page.evaluate(() => window.scrollTo(0, document.body.scrollHeight))
		await page.waitForTimeout(300)
		await shoot(page, 'user', '12-widgets.png')
	})
})

// ---------------------------------------------------------------------------
// ADMIN TRACK — see docs/tutorials/admin/
// ---------------------------------------------------------------------------

test.describe('docs: admin track', () => {
	test('A1 configure pipeline stages', async ({ page }) => {
		// docs/tutorials/admin/01-pipeline-stages.md — pipelines live on
		// the admin settings page (Pipelines section) and on the in-app
		// /pipelines route. Capture both.
		await page.goto('/index.php/settings/admin/pipelinq')
		await page.waitForLoadState('networkidle').catch(() => {})
		await dismissOverlays(page)
		await page.waitForTimeout(900)
		await shoot(page, 'admin', '01-admin-settings.png')
		await go(page, '/pipelines')
		await shoot(page, 'admin', '01-stages-editor.png')
	})

	test('A2 configure request types', async ({ page }) => {
		// docs/tutorials/admin/02-request-types.md
		await page.goto('/index.php/settings/admin/pipelinq')
		await page.waitForLoadState('networkidle').catch(() => {})
		await dismissOverlays(page)
		await page.waitForTimeout(900)
		await shoot(page, 'admin', '02-request-types.png')
	})

	test('A3 manage user / group permissions', async ({ page }) => {
		// docs/tutorials/admin/03-permissions.md — Agent Profiles
		// section on the admin page.
		await page.goto('/index.php/settings/admin/pipelinq')
		await page.waitForLoadState('networkidle').catch(() => {})
		await dismissOverlays(page)
		await page.waitForTimeout(900)
		const profiles = page.getByRole('heading', { name: /Agent Profiles/i }).first()
		if (await profiles.isVisible().catch(() => false)) {
			await profiles.scrollIntoViewIfNeeded().catch(() => {})
			await page.waitForTimeout(300)
		}
		await shoot(page, 'admin', '03-permissions.png')
		await shoot(page, 'admin', '03-add-mapping.png')
	})

	test('A4 configure CRM workflows and automation', async ({ page }) => {
		// docs/tutorials/admin/04-configure-automation.md
		await page.goto('/index.php/settings/admin/pipelinq')
		await page.waitForLoadState('networkidle').catch(() => {})
		await dismissOverlays(page)
		await page.waitForTimeout(900)
		await shoot(page, 'admin', '04-admin-settings.png')
		await go(page, '/automations')
		await shoot(page, 'admin', '04-states.png')
		const had = await captureCreateDialog(page, 'admin', '04-rule.png')
		if (!had) {
			await shoot(page, 'admin', '04-rule.png')
		}
	})

	test('A5 connect contacts and calendar sync', async ({ page }) => {
		// docs/tutorials/admin/05-configure-sync.md
		await page.goto('/index.php/settings/admin/pipelinq')
		await page.waitForLoadState('networkidle').catch(() => {})
		await dismissOverlays(page)
		await page.waitForTimeout(900)
		await shoot(page, 'admin', '05-admin-settings.png')
		await shoot(page, 'admin', '05-address-books.png')
		await shoot(page, 'admin', '05-calendar.png')
	})

	test('A6 manage Pipelinq settings', async ({ page }) => {
		// docs/tutorials/admin/06-admin-settings.md
		await page.goto('/index.php/settings/admin/pipelinq')
		await page.waitForLoadState('networkidle').catch(() => {})
		await dismissOverlays(page)
		await page.waitForTimeout(900)
		await page.evaluate(() => window.scrollTo(0, 0))
		await page.waitForTimeout(200)
		await shoot(page, 'admin', '06-overview.png')
		const reg = page.getByRole('heading', { name: /Register Configuration/i }).first()
		if (await reg.isVisible().catch(() => false)) {
			await reg.scrollIntoViewIfNeeded().catch(() => {})
			await page.waitForTimeout(300)
		}
		await shoot(page, 'admin', '06-register.png')
		await page.evaluate(() => window.scrollTo(0, document.body.scrollHeight))
		await page.waitForTimeout(300)
		await shoot(page, 'admin', '06-options.png')
		expect(page.url()).toContain('/settings/admin/pipelinq')
	})
})
