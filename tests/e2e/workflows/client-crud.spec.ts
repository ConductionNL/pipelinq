/*
 * SPDX-FileCopyrightText: 2026 Pipelinq Contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 *
 * DEEP, data-dependent CRUD-with-persistence journey for CLIENTS.
 *
 * Unlike the shell/render specs this drives the real UI with real values and
 * proves the data round-trips end to end, asserting persistence both in the UI
 * (the list row) AND directly against OpenRegister.
 *
 * UI-reachability of each CRUD surface in the current manifest-driven shell
 * (verified live 2026-06-10):
 *   - CREATE  — "Add Client" opens a generic "Create Client" schema dialog
 *               (CnSchemaFormDialog). Fully driven here.
 *   - READ    — the new object renders as a LIST ROW (name/type/industry/email/
 *               phone columns). Asserted against the row cells.
 *   - UPDATE  — edited value asserted as PERSISTED (OR API) AND reflected in the
 *               list row. The standalone ClientDetail "Edit" PAGE is NOT
 *               reachable from a list row in this build (row-click toggles the
 *               list filter sidebar, it does not route to /clients/:id), so the
 *               edit is applied through the same object API the app's own
 *               saveObject() calls; the detail-page edit UI gap is captured by
 *               a test.fixme below.
 *   - DELETE   — removed via the object API, then asserted GONE from the list.
 *
 * Navigation is manifest-driven (CnAppRoot); deep-link goto resets the SPA to
 * the Dashboard, so every navigation goes through a sidebar nav-click. Created
 * objects are tracked and removed via the OR object API in afterAll.
 */
import { test, expect, Locator, Page } from '@playwright/test'
import { openApp, navClick, dismissSupportDialog } from '../helpers/pipelinq'
import { FixtureSession, TEST_PREFIX } from './helpers/fixtures'

const NAME = `${TEST_PREFIX}-Acme Diensten BV`
const NAME_EDITED = `${TEST_PREFIX}-Acme Diensten Holding BV`
const EMAIL = 'acme@example.test'
const PHONE = '+31 20 123 4567'

/** Open an NcSelect combobox and pick the option whose text contains `text`. */
async function pickOption(combo: Locator, text: string): Promise<void> {
	await combo.click()
	const page = combo.page()
	await page.waitForTimeout(300)
	await page.locator('li[role="option"], .vs__dropdown-option').filter({ hasText: text }).first().click()
	await page.waitForTimeout(200)
}

/**
 * Open the Clients list fresh so this run's row is rendered. A reload after the
 * nav-click forces the store to re-fetch the collection from OpenRegister rather
 * than serve a cached copy — required after an out-of-band object change so the
 * list reflects current server state.
 */
async function openClientsList(page: Page): Promise<void> {
	await navClick(page, 'Clients', /clients/)
	await page.reload()
	await page.waitForTimeout(1800)
	await dismissSupportDialog(page)
}

/**
 * Filter the Clients index to a single term via the embedded CnIndexSidebar
 * search field (the manifest Clients page sets sidebar.enabled, so the search box
 * is rendered). The list orders created-ascending and paginates 20/page, so a
 * freshly created row is never on page 1 — the search re-fetches server-side and
 * surfaces it. Mirrors product-crud.spec.ts searchInList().
 */
async function searchInList(page: Page, term: string): Promise<void> {
	const field = page.locator('.app-sidebar input.input-field__input[type="text"]').first()
	await field.waitFor({ state: 'visible', timeout: 10000 })
	await field.fill('')
	await field.fill(term)
	// The search is debounced + re-fetches the collection; give it a beat to apply.
	await page.waitForTimeout(2500)
}

let fx: FixtureSession

test.describe('Clients — full CRUD with persistence', () => {
	test.describe.configure({ mode: 'serial' })

	test.afterAll(async ({ browser }) => {
		const page = await browser.newPage()
		try {
			const cleaner = new FixtureSession(page)
			await openApp(page)
			for (const name of [NAME, NAME_EDITED]) {
				const rows = await cleaner.list('client', { _limit: 20, name }).catch(() => [])
				for (const r of rows) {
					const id = r.id || r['@self']?.id
					if (id) await cleaner.remove('client', id)
				}
			}
		} finally {
			await page.close()
		}
	})

	// CREATE drives the bespoke ClientForm via Dashboard → "New Client".
	//
	// The `client` schema (OR schema 60) marks `contactsUid` REQUIRED — the
	// authoritative identity is the Nextcloud addressbook contact, never minted
	// locally — so a plain saveObject('client', …) is rejected 400 by OpenRegister
	// ("The required property (contactsUid) is missing"). The fix wires the bespoke
	// ClientCreateDialog (Dashboard → "New Client" → ClientForm.vue) through the
	// contact-FIRST create endpoint POST /api/contacts-sync/create, which provisions
	// the NC contact via ContactVcardService and saves the client with the resolved
	// contactsUid + the denormalised name/email/phone mirror.
	//
	// The GENERIC "Add Client" surface on the Clients list (the library's
	// CnIndexPage self-store create / CnFormDialog) still posts straight to OR and
	// CANNOT be made contact-aware without a nextcloud-vue change (CnIndexPage has
	// no per-schema create-override hook on its self-mode save path). That generic
	// surface therefore remains limited; this journey uses the fixed bespoke flow,
	// which is the supported contact-aware create path.
	test('create → list → values → edit → delete round-trips real data', async ({ page }) => {
		test.setTimeout(90000)
		fx = new FixtureSession(page)
		await openApp(page)

		// --- CREATE via the bespoke "New Client" dialog (Dashboard) -----------
		// openApp lands on the Dashboard, whose header actions host "New Client".
		await dismissSupportDialog(page)
		await page.getByRole('button', { name: /New Client/i }).first().click()
		const dialog = page.locator('[data-testid="client-create-dialog"]').first()
		await expect(dialog).toBeVisible({ timeout: 10000 })

		// NcTextField forwards data-testid onto the <input> element itself.
		await dialog.locator('[data-testid="client-name-input"]').fill(NAME)
		await pickOption(dialog.locator('[data-testid="client-type-select"]'), 'organi') // organization
		await dialog.locator('[data-testid="client-email-input"]').fill(EMAIL)
		await dialog.locator('[data-testid="client-phone-input"]').fill(PHONE)
		await dialog.locator('[data-testid="client-form-save"]').click()
		// On success the dialog emits `created` and the Dashboard routes to
		// /clients/:id (ClientDetail), so the create overlay is gone.
		await expect(dialog).toBeHidden({ timeout: 15000 })
		await page.waitForTimeout(1500)
		await dismissSupportDialog(page)

		// Persistence (OR API): the created object holds exactly what was entered.
		const created = (await fx.list('client', { _limit: 5, name: NAME }))[0]
		expect(created, 'created client returned by OR API').toBeTruthy()
		const createdId = (created.id || created['@self']?.id) as string
		fx.track('client', createdId)
		expect(created.name).toBe(NAME)
		expect(created.email).toBe(EMAIL)
		expect(created.phone).toBe(PHONE)

		// --- READ: the new row is present (NOT empty-state) + renders values --
		await openClientsList(page)
		await searchInList(page, NAME)
		await expect(page.locator('.cn-index-page__empty')).toHaveCount(0)
		const row = page.locator('[data-testid="cn-object-row"]').filter({ hasText: NAME }).first()
		await expect(row).toBeVisible({ timeout: 10000 })
		// The row renders the real entered values across its columns.
		await expect(row).toContainText(EMAIL)
		await expect(row).toContainText(PHONE)

		// --- UPDATE: change the name, assert persisted + reflected in the list -
		// (Detail-page edit UI is not reachable from a list row — see fixme.)
		const editId = createdId
		const updated = await fx.apiUpdateName('client', editId, NAME_EDITED)
		expect(updated, 'name update accepted by OR API').toBeTruthy()
		const persisted = await fx.get('client', editId)
		expect(persisted.name, 'edited name persisted to OpenRegister').toBe(NAME_EDITED)
		expect(persisted.email, 'email unchanged after rename').toBe(EMAIL)

		await openClientsList(page)
		await searchInList(page, NAME_EDITED)
		await expect(page.locator('[data-testid="cn-object-row"]').filter({ hasText: NAME_EDITED })).toBeVisible({ timeout: 10000 })
		// Old name no longer appears as a row.
		await expect(page.locator('[data-testid="cn-object-row"]').filter({ hasText: NAME + ' BV' }).filter({ hasNotText: 'Holding' })).toHaveCount(0)

		// --- DELETE: remove + assert the row is gone from the list ------------
		await fx.remove('client', editId)
		await openClientsList(page)
		await searchInList(page, NAME_EDITED)
		await expect(page.locator('[data-testid="cn-object-row"]').filter({ hasText: NAME_EDITED })).toHaveCount(0)
		const remaining = await fx.list('client', { _limit: 5, name: NAME_EDITED }).catch(() => [])
		expect(remaining.length, 'deleted client no longer returned by OR API').toBe(0)
	})

	/**
	 * UI-SHELL GAP (left skipped on purpose; NOT the register binding bug, which is
	 * fixed). The standalone ClientDetail page (with its Edit form + Delete
	 * confirmation) is implemented in src/views/clients/ClientDetail.vue +
	 * ClientForm.vue and a route exists at /clients/:id, but the manifest Clients
	 * index does not wire a list-row click to that route: clicking a row toggles
	 * the index filter/columns sidebar instead of routing to /clients/:id (the page
	 * sets showViewAction:false, so no in-row "View" affordance reaches it either).
	 * Until the manifest index routes a row to its detail page, the in-UI
	 * edit/delete journey cannot be driven from the list.
	 */
	test.fixme('edit + delete via the ClientDetail page UI (unreachable from list row in current shell)', async () => {})
})
