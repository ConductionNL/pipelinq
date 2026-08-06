/*
 * SPDX-FileCopyrightText: 2026 Pipelinq Contributors
 * SPDX-License-Identifier: EUPL-1.2
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
import { openApp, navClick, dismissSupportDialog, openIndexSearch } from '../helpers/pipelinq'
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
	// FIXED 2026-08-06 — the field is inside the Search/Columns sidebar, which
	// CnIndexPage mounts CLOSED (`sidebarOpen: false`, "opened on demand via the
	// actions-bar toggle"). Reaching straight for the input waited 10s against an
	// element that a user has to open first. openIndexSearch() drives the toggle.
	const field = await openIndexSearch(page)
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
	// CnIndexPage self-store create / CnFormDialog) is now ALSO contact-aware:
	// the manifest Clients page declares `config.createOverride:
	// "createClientContactAware"` (resolved by CnPageRenderer to the registry
	// handler that posts to /api/contacts-sync/create) plus
	// `config.fieldOverrides` that un-skip the schema-readOnly name/email/phone so
	// they can be collected on create. See the dedicated generic-surface test
	// below. This bespoke-flow journey remains the deep CRUD round-trip.
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

		// --- UPDATE ------------------------------------------------------------
		// REWRITTEN 2026-08-06. This leg used to rename the client
		// (`apiUpdateName(... NAME_EDITED)`) and asserted only `toBeTruthy()` on a
		// helper that collapses any non-2xx into `null`, so when it started
		// failing the message was the uninformative "Received: false".
		//
		// The rename could not have succeeded on a correct instance:
		// register.d/15-unify-client-contact.json declares `client.name` as
		// `readOnly: true`, "Denormalised read-only mirror of the NC contact name
		// (vCard FN). The Nextcloud Contact is authoritative … Edit identity in
		// the addressbook." Renaming a client through the object API is not the
		// supported edit path — it is the one edit the contact-first unification
		// exists to forbid. (It never surfaced before because the test failed
		// earlier, at the index search box; fixing that exposed this.)
		//
		// So the round-trip now edits a field the client genuinely owns, and
		// separately ASSERTS the read-only mirror rejects a write — which is the
		// more valuable of the two claims and had no coverage at all.
		const editId = createdId

		const updated = await fx.update('client', editId, { lifecycleStage: 'customer', accountStatus: 'inactive' })
		expect(updated, 'client-owned fields accepted by the OR API').not.toBeNull()
		const persisted = await fx.get('client', editId)
		expect(persisted.lifecycleStage, 'lifecycleStage persisted to OpenRegister').toBe('customer')
		expect(persisted.accountStatus, 'accountStatus persisted to OpenRegister').toBe('inactive')
		expect(persisted.name, 'the mirrored identity name is untouched by an owned-field edit').toBe(NAME)
		expect(persisted.email, 'email unchanged').toBe(EMAIL)

		// The identity mirror is authoritative in the addressbook: a direct write
		// must NOT silently take effect here.
		await fx.apiUpdateName('client', editId, NAME_EDITED).catch(() => false)
		const afterRename = await fx.get('client', editId)
		expect(afterRename.name, 'client.name is a read-only mirror — the object API must not rename it').toBe(NAME)

		await openClientsList(page)
		await searchInList(page, NAME)
		await expect(page.locator('[data-testid="cn-object-row"]').filter({ hasText: NAME })).toBeVisible({ timeout: 10000 })

		// --- DELETE: remove + assert the row is gone from the list ------------
		await fx.remove('client', editId)
		await openClientsList(page)
		await searchInList(page, NAME)
		await expect(page.locator('[data-testid="cn-object-row"]').filter({ hasText: NAME })).toHaveCount(0)
		const remaining = await fx.list('client', { _limit: 5, name: NAME }).catch(() => [])
		expect(remaining.length, 'deleted client no longer returned by OR API').toBe(0)
	})

	/**
	 * GENERIC "Add Client" surface (the library CnIndexPage form on the Clients
	 * list), now contact-aware via the manifest `config.createOverride` +
	 * `config.fieldOverrides` wiring. Proves the regression this work closes:
	 * the generic Add button must NOT 400 on the required contactsUid — it must
	 * route through POST /api/contacts-sync/create, provision the NC contact,
	 * and persist the client with contactsUid populated. @spec
	 * openspec/specs/unify-client-contact/spec.md#REQ-PUCC-004
	 */
	test('generic "Add Client" list button creates contact-aware (201 + contactsUid)', async ({ page }) => {
		test.setTimeout(90000)
		fx = new FixtureSession(page)
		await openApp(page)
		await dismissSupportDialog(page)

		const GEN_NAME = `${TEST_PREFIX}-Generic Surface BV`
		const GEN_EMAIL = 'generic-surface@example.test'
		const GEN_PHONE = '+31 20 765 4321'

		// Open the Clients list and click the generic CnIndexPage "Add Client".
		await openClientsList(page)
		await page.getByRole('button', { name: /Add Client/i }).first().click()

		// The generic CnFormDialog. fieldOverrides un-skip name/email/phone (which
		// are schema-readOnly mirrors) so they can be collected on create.
		const dialog = page.locator('.modal-container').filter({ hasText: /Create Client/i }).first()
		await expect(dialog).toBeVisible({ timeout: 10000 })
		await dialog.getByRole('textbox', { name: /^Name/i }).fill(GEN_NAME)
		await dialog.getByRole('textbox', { name: /Email/i }).fill(GEN_EMAIL)
		await dialog.getByRole('textbox', { name: /Phone/i }).fill(GEN_PHONE)
		await pickOption(dialog.locator('.v-select').filter({ hasText: /Client type/i }).first(), 'organi')

		// Submit and capture the contact-aware POST — it MUST hit the contacts-sync
		// create endpoint (not a straight OR object POST) and return 201.
		const createResp = page.waitForResponse(
			(r) => /\/api\/contacts-sync\/create$/.test(r.url()) && r.request().method() === 'POST',
			{ timeout: 15000 },
		)
		await dialog.getByRole('button', { name: /^Create$/i }).click()
		const resp = await createResp
		expect(resp.status(), 'contact-aware create returns 201').toBe(201)
		const payload = await resp.json()
		expect(payload.success).toBe(true)
		expect(payload.object.contactsUid, 'required contactsUid populated by the contact-aware path').toBeTruthy()

		// Persistence (OR API): the client exists with the FK + entered mirror values.
		const created = (await fx.list('client', { _limit: 5, name: GEN_NAME }))[0]
		expect(created, 'generic-surface client persisted to OpenRegister').toBeTruthy()
		const createdId = (created.id || created['@self']?.id) as string
		fx.track('client', createdId)
		expect(created.contactsUid, 'contactsUid persisted on the object').toBeTruthy()
		expect(created.name).toBe(GEN_NAME)
		expect(created.email).toBe(GEN_EMAIL)
		expect(created.phone).toBe(GEN_PHONE)

		// A real vCard now backs it in the addressbook (resolvable by name).
		const search = await page.evaluate(async (q) => {
			const r = await fetch('/apps/pipelinq/api/contacts-sync/search?q=' + encodeURIComponent(q), { headers: { 'OCS-APIRequest': 'true' } })
			return r.json()
		}, GEN_NAME)
		expect(search.results?.some((c: any) => c.uid === created.contactsUid), 'addressbook vCard exists with the linked uid').toBe(true)

		await fx.remove('client', createdId)
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
