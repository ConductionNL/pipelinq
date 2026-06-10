/*
 * SPDX-FileCopyrightText: 2026 Pipelinq Contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 *
 * DEEP, data-dependent POS money workflow — proves the cart math is COMPUTED
 * CORRECTLY against known prices/rates, asserting the EXACT euro figures.
 *
 * The POS transaction form (Kassabon → Add Item → New transaction) computes a
 * live preview of every line total, the subtotal, the per-rate BTW/VAT and the
 * grand total via services/posTotals.js (which mirrors the server-authoritative
 * PosTransactionService formula). This spec drives the form with hand-entered
 * lines whose prices and VAT rates are known, then asserts the rendered money
 * figures to the cent — the high-value correctness check.
 *
 * Cases asserted (excl.-BTW price mode, the form default):
 *   A. 2 × €10.00 @ 21%                → line €24.20, sub €20.00, VAT21 €4.20, total €24.20
 *   B. + 3 × €4.00 @ 9%                → line €13.08, sub €32.00,
 *                                        VAT21 €4.20 + VAT9 €1.08, total €37.28
 *   C. 1 × €100.00 @ 21% with 10% disc → net €90.00, VAT €18.90, line/total €108.90
 *
 * Two product-catalogue-dependent steps (picker prefill, and completing the
 * sale through Checkout) cannot currently be driven headlessly — see the
 * documented test.fixme blocks at the bottom (a real store-registration bug,
 * not a test defect).
 */
import { test, expect, Page, Locator } from '@playwright/test'
import { openApp, navClick, dismissSupportDialog } from '../helpers/pipelinq'
import { FixtureSession } from './helpers/fixtures'

/** Set a numeric input to an exact value and let Vue's @update:value fire. */
async function setNum(page: Page, input: Locator, value: string): Promise<void> {
	await input.click()
	await input.fill('')
	await input.fill(value)
	await input.blur()
	await page.waitForTimeout(250)
}

/** Open the POS new-transaction form and wait for the empty cart to render. */
async function openPosForm(page: Page): Promise<void> {
	await navClick(page, 'Kassabon', /pos/)
	await page.waitForTimeout(800)
	await page.locator('#content-vue').getByRole('button', { name: /Add Item/i }).first().click()
	await page.waitForURL(/pos\/new/, { timeout: 10000 })
	await page.waitForTimeout(1500)
	await dismissSupportDialog(page)
}

/**
 * Fill the nth (0-based) cart line with manual values. The line cells, in order,
 * are: product picker | description | qty | unitPrice | discount% | VAT | total.
 * The three numeric inputs are qty / unitPrice / discount.
 */
async function fillLine(page: Page, index: number, opts: {
	description: string, qty: string, unitPrice: string, discount?: string, vat?: '0' | '9' | '21',
}): Promise<Locator> {
	const row = page.locator('.pos-form__lines tbody tr').nth(index)
	await row.locator('.pos-line-row__description input').fill(opts.description)
	const nums = row.locator('.pos-line-row__num input[type="number"]')
	await setNum(page, nums.nth(0), opts.qty)
	await setNum(page, nums.nth(1), opts.unitPrice)
	if (opts.discount) await setNum(page, nums.nth(2), opts.discount)
	if (opts.vat) {
		// VAT is an NcSelect in the row; default is 21%. Only switch if needed.
		const vatCombo = row.locator('.pos-line-row__num').last().getByRole('combobox')
		await vatCombo.click()
		await page.waitForTimeout(250)
		await page.locator('li[role="option"], .vs__dropdown-option').filter({ hasText: `${opts.vat}%` }).first().click()
		await page.waitForTimeout(250)
	}
	return row
}

/** Read the totals panel as a single normalised string. */
async function totalsText(page: Page): Promise<string> {
	return (await page.locator('.pos-totals').innerText()).replace(/\s+/g, ' ').trim()
}

test.describe('POS — money workflow computes correct totals', () => {
	test('cart line totals, subtotal, per-rate VAT and grand total are exact', async ({ page }) => {
		test.setTimeout(120000)
		await openApp(page)
		await openPosForm(page)

		// --- Case A: 2 × €10.00 @ 21% ----------------------------------------
		await page.getByRole('button', { name: /Add line/i }).click()
		await page.waitForTimeout(400)
		const rowA = await fillLine(page, 0, { description: 'Widget A', qty: '2', unitPrice: '10' })
		await expect(rowA.locator('.pos-line-row__total')).toHaveText('€ 24,20')

		let totals = await totalsText(page)
		expect(totals, 'subtotal after case A').toContain('Subtotal € 20,00')
		expect(totals, 'VAT 21% line after case A').toContain('VAT 21% (base € 20,00) € 4,20')
		expect(totals, 'grand total after case A').toContain('Total € 24,20')

		// --- Case B: add 3 × €4.00 @ 9% (second rate bucket) ------------------
		await page.getByRole('button', { name: /Add line/i }).click()
		await page.waitForTimeout(400)
		const rowB = await fillLine(page, 1, { description: 'Snack B', qty: '3', unitPrice: '4', vat: '9' })
		// net 12.00, tax 9% = 1.08, line total 13.08
		await expect(rowB.locator('.pos-line-row__total')).toHaveText('€ 13,08')

		totals = await totalsText(page)
		expect(totals, 'subtotal sums both lines (20 + 12)').toContain('Subtotal € 32,00')
		expect(totals, '21% bucket unchanged').toContain('VAT 21% (base € 20,00) € 4,20')
		expect(totals, '9% bucket present').toContain('VAT 9% (base € 12,00) € 1,08')
		// grand total = 32.00 + 4.20 + 1.08 = 37.28
		expect(totals, 'grand total = subtotal + both VAT buckets').toContain('Total € 37,28')

		// --- Case C: a discounted line on a fresh cart ------------------------
		// Reload to a clean form so the discount case asserts in isolation.
		await openPosForm(page)
		await page.getByRole('button', { name: /Add line/i }).click()
		await page.waitForTimeout(400)
		// 1 × €100.00 @ 21% with 10% discount → net 90.00, VAT 18.90, total 108.90
		const rowC = await fillLine(page, 0, { description: 'Service C', qty: '1', unitPrice: '100', discount: '10' })
		await expect(rowC.locator('.pos-line-row__total')).toHaveText('€ 108,90')

		totals = await totalsText(page)
		expect(totals, 'discounted subtotal is the net base').toContain('Subtotal € 90,00')
		expect(totals, 'discount line surfaced').toContain('Discount')
		expect(totals, 'VAT on the discounted net').toContain('VAT 21% (base € 90,00) € 18,90')
		expect(totals, 'discounted grand total').toContain('Total € 108,90')
	})

	/**
	 * BUG (HIGH) — completing the sale fails: the POS "Checkout" button calls
	 * objectStore.saveObject('posTransaction', …), but `posTransaction` is NOT
	 * registered in the app's object store, so the save throws
	 * (`Object type "posTransaction" is not registered in the store`), no POST is
	 * ever issued to the OpenRegister API, the UI shows the toast "Failed to save
	 * transaction." and the form stays on /pos/new. A cashier therefore cannot
	 * complete a sale through the UI. Verified live 2026-06-10 (console error +
	 * zero posTransaction network requests).  Same root cause leaves
	 * `billingCategory` unregistered. Likely fix: register the POS-area object
	 * types via the store slug-fallback (store.js) the same way the CRM types are.
	 *
	 * Re-enable this test once checkout persists a posTransaction; it should
	 * assert the created transaction's persisted `total` equals the computed
	 * grand total (€24.20 for case A) and that a line item was stored.
	 */
	test.fixme('completing the sale persists a transaction with the correct total (blocked: posTransaction not registered in store)', async ({ page }) => {
		const fx = new FixtureSession(page)
		await openApp(page)
		await openPosForm(page)
		await page.getByRole('button', { name: /Add line/i }).click()
		await fillLine(page, 0, { description: 'Widget A', qty: '2', unitPrice: '10' })
		await page.locator('[data-testid="checkout"]').click()
		await page.waitForURL(/pos\/[0-9a-f-]{36}/i, { timeout: 10000 })
		const txId = page.url().match(/pos\/([0-9a-f-]{36})/i)![1]
		fx.track('posTransaction', txId)
		const tx = await fx.get('posTransaction', txId)
		expect(Number(tx.total)).toBe(24.2)
		const lines = await fx.list('posTransactionLine', { transaction: txId, _limit: 10 })
		for (const l of lines) fx.track('posTransactionLine', l.id || l['@self']?.id)
		expect(lines.length).toBe(1)
		expect(Number(lines[0].lineTotal)).toBe(24.2)
		await fx.cleanup()
	})

	/**
	 * BUG (MEDIUM) — the POS line product picker is empty: PosTransactionForm's
	 * loadProducts() calls objectStore.fetchCollection('product'), which fails on
	 * the same store-registration gap (the billingCategory/posTransaction
	 * "not registered" errors fire on mount and the product fetch never returns
	 * rows), so the "Search product…" dropdown shows "No results" even when the
	 * catalogue is populated. A cashier cannot pick a catalogued product to
	 * prefill its price/VAT. Verified live 2026-06-10. Manual line entry (above)
	 * is unaffected and exercises the same money math.
	 *
	 * Re-enable to seed a product, open the picker, select it, and assert the
	 * line's unitPrice/VAT prefill from the product.
	 */
	test.fixme('selecting a catalogue product prefills the line price + VAT (blocked: POS product picker catalogue empty)', async () => {})
})
