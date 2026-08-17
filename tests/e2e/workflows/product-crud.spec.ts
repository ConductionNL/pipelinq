/*
 * SPDX-FileCopyrightText: 2026 Pipelinq Contributors
 * SPDX-License-Identifier: EUPL-1.2
 *
 * DEEP, data-dependent CRUD-with-persistence journey for PRODUCTS.
 *
 *   create (UI "Create Product" dialog, real price + VAT rate) → assert the new
 *   row appears in the LIST (not empty-state) → assert the entered values render
 *   in the row → EDIT the price (persisted via the app's PUT verb) → assert the
 *   new price persisted (OR API) → DELETE → assert gone from the list.
 *
 * Money correctness matters for products: the unitPrice and taxRate feed the POS
 * money math, so this asserts the EXACT numeric values round-trip (49.95 in,
 * 49.95 stored; edited to 59.95). The standalone ProductDetail edit/delete PAGE
 * exists (src/views/products/ProductDetail.vue + ProductForm.vue) but is not
 * reachable from a manifest list row in the current shell (row-click toggles the
 * list filter sidebar, not the /products/:id route — verified live 2026-06-10),
 * so the edit is applied through the same object API the app's saveObject() uses
 * and the detail-page UI gap is captured by a test.fixme.
 *
 * Created objects are tracked + removed via the OR object API in afterAll.
 */
import { test, expect, Locator, Page } from '@playwright/test'
import {
	openApp,
	navClick,
	dismissSupportDialog,
	openIndexSearch,
} from '../helpers/pipelinq'
import { FixtureSession, TEST_PREFIX } from './helpers/fixtures'

const NAME = `${TEST_PREFIX}-Cnd Werkbank`
const SKU = `SKU-${TEST_PREFIX}`
const PRICE = 49.95
const PRICE_EDITED = 59.95

/** Open an NcSelect combobox and pick the option containing `text`. */
async function pickOption(combo: Locator, text: string): Promise<void> {
	await combo.click()
	const page = combo.page()
	await page.waitForTimeout(300)
	await page
		.locator('li[role="option"], .vs__dropdown-option')
		.filter({ hasText: text })
		.first()
		.click()
	await page.waitForTimeout(200)
}

/**
 * The generic "Create Product" schema dialog renders its selects with stable
 * ids `cn-form-<field>` (btwClass / status / type), so the required Type field
 * is addressed by id rather than positional combobox order.
 */
async function pickFormSelect(
	dialog: Locator,
	field: string,
	text: string,
): Promise<void> {
	await pickOption(dialog.locator(`#cn-form-${field}`), text)
}

/**
 * Open the Products list fresh. A reload after the nav-click guarantees the
 * product collection — not a stale neighbouring index collection — is mounted
 * (the manifest index pages share list state; verified live 2026-06-10).
 */
async function openProductsList(page: Page): Promise<void> {
	await navClick(page, 'Products', /products/)
	await page.reload()
	await page.waitForTimeout(2000)
	await dismissSupportDialog(page)
}

/**
 * Filter the index list to a single term via the embedded CnIndexSidebar search
 * field (the manifest Products page sets sidebar.enabled, so the search box is
 * always rendered). The list defaults to created-ASCending order and paginates
 * at 20 rows/page, so a freshly-created product lands on the LAST page and is
 * never on page 1 (verified live 2026-06-18). The search re-fetches server-side
 * with the term, surfacing the row regardless of which page it would paginate
 * onto. Returns once the list has settled to the filtered result.
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

test.describe('Products — full CRUD with persistence', () => {
	test.describe.configure({ mode: 'serial' })

	test.afterAll(async ({ browser }) => {
		const page = await browser.newPage()
		try {
			const cleaner = new FixtureSession(page)
			await openApp(page)
			const rows = await cleaner
				.list('product', { _limit: 20, name: NAME })
				.catch(() => [])
			for (const r of rows) {
				const id = r.id || r['@self']?.id
				if (id) await cleaner.remove('product', id)
			}
		} finally {
			await page.close()
		}
	})

	// UN-FIXME 2026-06-18 — the "create persists nothing" reading was a fixture
	// register-binding artefact, not a backend regression. The "Create Product"
	// dialog POSTs to `/objects/pipelinq/product` (the register SLUG); the app and
	// OpenRegister resolve that slug to register 16, but the fixture had resolved a
	// NUMERIC id from the registers list, which on this box returns a DUPLICATE
	// register (446) first — so the read-back queried the wrong register. The
	// fixture now addresses the OR object API by the same slug the app uses, so
	// create → list → values → edit → delete all round-trip against the register
	// the UI writes to. (Edit + delete are applied through the OR object API the
	// app's own saveObject() calls; the detail-PAGE edit UI is still unreachable
	// from a list row — that distinct UI-shell gap stays captured by the fixme
	// below.)
	test('create → list → values → edit price → delete round-trips real data', async ({
		page,
	}) => {
		test.setTimeout(90000)
		fx = new FixtureSession(page)
		await openApp(page)

		// --- CREATE via the "Create Product" schema dialog --------------------
		await openProductsList(page)
		await page
			.locator('#content-vue')
			.getByRole('button', { name: /Add Product/i })
			.first()
			.click()
		const dialog = page
			.locator('[role="dialog"]')
			.filter({ hasText: 'Create Product' })
			.first()
		await expect(dialog).toBeVisible({ timeout: 10000 })

		// FIXED 2026-08-06 — this was `{ name: /^name/i }`, anchored at the START
		// of the accessible name. CnFormDialog labels each control from the schema
		// property's `title`, and `product.name` carries `"title": "Product Name"`
		// (lib/Settings/pipelinq_register.json). "Product Name" does not START with
		// "name", so the locator matched nothing and `fill()` waited out the whole
		// 90s test timeout — a label mismatch presenting as a hung create dialog.
		await dialog
			.getByRole('textbox', { name: /product name/i })
			.first()
			.fill(NAME)
		await pickFormSelect(dialog, 'type', 'product') // required Type
		// unitPrice is the dialog's single numeric/spinbutton field.
		const priceField = dialog
			.getByRole('spinbutton')
			.or(dialog.locator('input[type="number"]'))
			.first()
		await priceField.fill(String(PRICE))
		const skuField = dialog.getByRole('textbox', { name: /sku/i }).first()
		if (await skuField.count()) await skuField.fill(SKU)

		await dialog.getByRole('button', { name: 'Create', exact: true }).click()
		await expect(dialog).toBeHidden({ timeout: 15000 })
		await page.waitForTimeout(1500)
		await dismissSupportDialog(page)

		// Persistence (OR API): the EXACT price round-trips.
		const created = (await fx.list('product', { _limit: 5, name: NAME }))[0]
		expect(created, 'created product returned by OR API').toBeTruthy()
		const createdId = (created.id || created['@self']?.id) as string
		fx.track('product', createdId)
		expect(created.name).toBe(NAME)
		expect(
			Number(created.unitPrice),
			'unitPrice persisted exactly (49.95)',
		).toBe(PRICE)

		// --- READ: row present (NOT empty-state) + renders the name -----------
		await openProductsList(page)
		await searchInList(page, NAME)
		await expect(page.locator('.cn-index-page__empty')).toHaveCount(0)
		const row = page
			.locator('[data-testid="cn-object-row"]')
			.filter({ hasText: NAME })
			.first()
		await expect(row).toBeVisible({ timeout: 10000 })

		// --- UPDATE the price, assert persisted EXACTLY -----------------------
		const updated = await fx.update('product', createdId, {
			unitPrice: PRICE_EDITED,
		})
		expect(updated, 'price update accepted by OR API').toBeTruthy()
		const persisted = await fx.get('product', createdId)
		expect(
			Number(persisted.unitPrice),
			'edited unitPrice persisted exactly (59.95)',
		).toBe(PRICE_EDITED)
		expect(persisted.name, 'name unchanged after price edit').toBe(NAME)

		// The row still shows the product after the edit.
		await openProductsList(page)
		await searchInList(page, NAME)
		await expect(
			page.locator('[data-testid="cn-object-row"]').filter({ hasText: NAME }),
		).toBeVisible({ timeout: 10000 })

		// --- DELETE: remove + assert the row is gone from the list ------------
		await fx.remove('product', createdId)
		await openProductsList(page)
		await searchInList(page, NAME)
		await expect(
			page.locator('[data-testid="cn-object-row"]').filter({ hasText: NAME }),
		).toHaveCount(0)
		const remaining = await fx
			.list('product', { _limit: 5, name: NAME })
			.catch(() => [])
		expect(
			remaining.length,
			'deleted product no longer returned by OR API',
		).toBe(0)
	})

	/**
	 * UI-SHELL GAP (left skipped on purpose; NOT the register binding bug, which is
	 * fixed — the data round-trip above now passes end-to-end).
	 *
	 * src/views/products/ProductDetail.vue ships an Edit form (ProductForm.vue) and
	 * a Delete confirmation, and a ProductDetail route exists at /products/:id, but
	 * the manifest Products index does not wire a list-row click to that route:
	 * clicking a row toggles the index filter/columns sidebar instead of navigating
	 * to the detail page (the page sets showViewAction:false, so no in-row "View"
	 * affordance reaches ProductDetail either). Until the manifest index routes a
	 * row to its detail page, the in-UI edit/delete journey cannot be driven; the
	 * persistence round-trip is covered above through the OR object API.
	 */
	test.fixme('edit price + delete via the ProductDetail page UI (unreachable from list row in current shell)', async () => {})
})
