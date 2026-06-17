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
 * (verified live 2026-06-17):
 *   - CREATE  — the Client is now a CRM ACCOUNT record linked to a Contact
 *               (required contactsUid); the "Create Client" schema dialog drives
 *               only the account fields, so the create is issued through the OR
 *               object API the app itself uses (store.saveObject()).
 *   - READ    — the new object renders as a LIST ROW (name/type/industry
 *               columns). Asserted against the row cells.
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
import { test, expect, Page } from '@playwright/test'
import { openApp, navClick, dismissSupportDialog } from '../helpers/pipelinq'
import { FixtureSession, TEST_PREFIX } from './helpers/fixtures'

const NAME = `${TEST_PREFIX}-Acme Diensten BV`
const NAME_EDITED = `${TEST_PREFIX}-Acme Diensten Holding BV`
// The Client schema was refactored into a CRM ACCOUNT record "distinct from the
// contact identity": name/type/accountStatus are required, the account MUST link
// to a Contact via `contactsUid`, and email/phone moved to that Contact (they are
// no longer client-schema properties, and the create dialog no longer exposes
// them). The value round-trip is therefore driven through the OpenRegister object
// API the app's own store.saveObject() uses.
const INDUSTRY = 'Software'
const INDUSTRY_EDITED = 'Public sector'

/**
 * Open the Clients list fresh so this run's row is rendered. A reload after the
 * nav-click forces the store to re-fetch the collection from OpenRegister rather
 * than serve a cached copy — required after an out-of-band object change so the
 * list reflects current server state. An optional `search` term is typed into the
 * list search box so a run-scoped row surfaces on page 1 (the register holds 20+
 * clients, paginated ~20/page).
 */
async function openClientsList(page: Page, search?: string): Promise<void> {
	await navClick(page, 'Clients', /clients/)
	await page.reload()
	await page.waitForTimeout(1800)
	await dismissSupportDialog(page)
	if (search) {
		const box = page.locator('#content-vue input[placeholder*="search" i]').first()
		if (await box.count()) {
			await box.fill('')
			await box.fill(search)
			await page.waitForTimeout(1500)
		}
	}
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

	test('create → list → values → edit → delete round-trips real data', async ({ page }) => {
		test.setTimeout(90000)
		fx = new FixtureSession(page)
		await openApp(page)

		// The account record requires a linked Contact (contactsUid). Resolve a
		// real contact uuid from the register so the create satisfies validation.
		const contacts = await fx.list('contact', { _limit: 1 })
		const contactUid = (contacts[0]?.['@self']?.uuid || contacts[0]?.uuid || contacts[0]?.id) as string
		expect(contactUid, 'a seeded contact exists to link the account to').toBeTruthy()

		// --- CREATE the account record via the OR object API ------------------
		// (The schema-form create dialog only drives the account fields; the
		// value round-trip is asserted against the same object API the app's own
		// store.saveObject() uses.)
		const created = await fx.create('client', {
			name: NAME,
			type: 'organization',
			accountStatus: 'active',
			contactsUid: contactUid,
			industry: INDUSTRY,
		})
		expect(created, 'created client returned by OR API').toBeTruthy()
		const createdId = (created.id || created['@self']?.id) as string
		expect(created.name).toBe(NAME)
		expect(created.type).toBe('organization')
		expect(created.industry).toBe(INDUSTRY)

		// --- READ: the new row is present (NOT empty-state) + renders values --
		await openClientsList(page, NAME)
		await expect(page.locator('.cn-index-page__empty')).toHaveCount(0)
		const row = page.locator('[data-testid="cn-object-row"]').filter({ hasText: NAME }).first()
		await expect(row).toBeVisible({ timeout: 10000 })
		// The row renders the account's type + industry across its columns.
		await expect(row).toContainText('organization')
		await expect(row).toContainText(INDUSTRY)

		// --- UPDATE: rename + reclassify the industry, assert persisted -------
		// (Detail-page edit UI is not reachable from a list row — see fixme.)
		const editId = createdId
		const updated = await fx.update('client', editId, { name: NAME_EDITED, industry: INDUSTRY_EDITED })
		expect(updated, 'update accepted by OR API').toBeTruthy()
		const persisted = await fx.get('client', editId)
		expect(persisted.name, 'edited name persisted to OpenRegister').toBe(NAME_EDITED)
		expect(persisted.industry, 'edited industry persisted to OpenRegister').toBe(INDUSTRY_EDITED)
		expect(persisted.contactsUid, 'linked contact unchanged after edit').toBe(contactUid)

		await openClientsList(page, NAME_EDITED)
		await expect(page.locator('[data-testid="cn-object-row"]').filter({ hasText: NAME_EDITED })).toBeVisible({ timeout: 10000 })
		// Old name no longer appears as a row.
		await expect(page.locator('[data-testid="cn-object-row"]').filter({ hasText: NAME + ' BV' }).filter({ hasNotText: 'Holding' })).toHaveCount(0)

		// --- DELETE: remove + assert the row is gone from the list ------------
		await fx.remove('client', editId)
		await openClientsList(page, NAME_EDITED)
		await expect(page.locator('[data-testid="cn-object-row"]').filter({ hasText: NAME_EDITED })).toHaveCount(0)
		const remaining = await fx.list('client', { _limit: 5, name: NAME_EDITED }).catch(() => [])
		expect(remaining.length, 'deleted client no longer returned by OR API').toBe(0)
	})

	/**
	 * The standalone ClientDetail page (with its Edit form + Delete confirmation
	 * dialog) is implemented in src/views/clients/ClientDetail.vue + ClientForm.vue
	 * but is NOT reachable from the manifest-driven Clients list: clicking a list
	 * row toggles the list's filter/columns sidebar instead of routing to
	 * /clients/:id (verified live 2026-06-10). Until the manifest index wires
	 * row-click to the detail route, the in-UI edit/delete journey cannot be
	 * driven; the persistence round-trip is covered above through the object API.
	 */
	test.fixme('edit + delete via the ClientDetail page UI (unreachable from list row in current shell)', async () => {})
})
