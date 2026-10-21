// @vitest-environment jsdom
/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 * SPDX-License-Identifier: EUPL-1.2
 *
 * The till's purchase history shows each total as money in the purchase's own
 * currency and the user's locale, and dates in the user's locale, instead of a
 * hard-coded euro sign and nl-NL (PL-ABSTRACT bug 17).
 *
 * @spec openspec/changes/pl2-pos-locale-fixes/specs/pos-display/spec.md#requirement-a-purchase-total-follows-its-currency-and-the-users-locale
 */
import { mount } from '@vue/test-utils'
import { readFileSync } from 'node:fs'
import { resolve } from 'node:path'
import { afterEach, describe, expect, it, vi } from 'vitest'

vi.mock('@nextcloud/initial-state', () => ({ loadState: () => ({ currency: 'EUR' }) }))

globalThis.t = (_app, text) => text

const { default: PurchaseHistory } = await import('../../src/components/pos/PurchaseHistory.vue')

/**
 * @param {string} locale The user's locale.
 * @param {Array<object>} rows The purchases.
 * @return {object} The mounted panel, open.
 */
function mountIn(locale, rows) {
	globalThis.OC = { getLocale: () => locale }
	return mount(PurchaseHistory, { props: { rows, defaultCollapsed: false }, global: { mocks: { t: globalThis.t } } })
}

afterEach(() => {
	delete globalThis.OC
})

describe('PurchaseHistory', () => {
	const rows = [{ id: 'p1', createdAt: '2026-10-07T11:43:00Z', reference: 'POS-1', total: 1234.5, currency: 'USD', tenderType: 'card' }]

	it("shows the total in the purchase's currency and the user's locale", () => {
		const cells = mountIn('en_US', rows).findAll('td').map((cell) => cell.text())
		expect(cells[3]).toBe('$1,234.50')
		expect(cells[0]).toBe('10/07/2026')
	})

	it('falls back to the reporting currency', () => {
		const cells = mountIn('en_US', [{ ...rows[0], currency: undefined }]).findAll('td').map((cell) => cell.text())
		expect(cells[3]).toBe('€1,234.50')
	})

	it('has no hard-coded euro sign or nl-NL', () => {
		const source = readFileSync(resolve(__dirname, '../../src/components/pos/PurchaseHistory.vue'), 'utf8')
		expect(source).not.toMatch(/€|'nl-NL'/)
	})
})
