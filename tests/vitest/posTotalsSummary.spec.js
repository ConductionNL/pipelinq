// @vitest-environment jsdom
/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Board PqKassabon "Totalen": the receipt totals as one short list. The
 * number of lines, the amount without VAT, the VAT per rate (highest first)
 * and the total; the full tax return and the invoice split stay one click
 * away. Also the shortDateTime cell formatter the transaction card uses.
 *
 * @spec openspec/specs/pos-nl-btw-engine/spec.md#req-btw-005-tax-breakdown-display-on-detail-view
 */

import fs from 'node:fs'
import path from 'node:path'
import { flushPromises, mount } from '@vue/test-utils'
import { beforeEach, describe, expect, it, vi } from 'vitest'
import { CELL_FORMATTERS, shortDateTime } from '../../src/services/cellFormatters.js'

const axiosMock = vi.hoisted(() => ({ get: vi.fn() }))

vi.mock('@nextcloud/axios', async (importOriginal) => {
	const actual = await importOriginal()
	return { ...actual, default: Object.assign(Object.create(actual.default), { get: axiosMock.get }) }
})
vi.mock('@nextcloud/router', async (importOriginal) => ({ ...(await importOriginal()), generateUrl: (path) => '/index.php' + path }))

const { default: PosTransactionTotalsWidget } =
	await import('../../src/components/pos/PosTransactionTotalsWidget.vue')

const t = (app, text, vars) => String(text).replace(/\{(\w+)\}/g, (whole, key) => (vars && key in vars ? String(vars[key]) : whole))
const global = { mocks: { t } }
globalThis.t = t

const SALE = {
	id: 'tx-1',
	priceMode: 'incl',
	subtotal: 93.5,
	discountTotal: 0.99,
	totalTax: 2.91,
	total: 96.41,
	taxBreakdown: [
		{ rate: 0, base: 75, tax: 0 },
		{ rate: 21, base: 10.33, tax: 2.17 },
		{ rate: 9, base: 8.17, tax: 0.74 },
	],
}

/**
 * Mount the card in the summary layout and let the line count settle.
 *
 * @return {Promise<object>} The wrapper.
 */
async function mountSummary() {
	axiosMock.get.mockResolvedValue({ data: { total: 3, results: [] } })
	const wrapper = mount(PosTransactionTotalsWidget, {
		global,
		props: { objectId: 'tx-1', objectData: SALE, content: { layout: 'summary' } },
	})
	await flushPromises()
	return wrapper
}

function rows (wrapper) {
  return wrapper.findAll('[data-testid="pos-totals-row"]').map((row) => [
	row.find('dt').text(),
	row.find('dd').text().replace(/\s+/g, ' '),
])
}

describe('PosTransactionTotalsWidget summary layout', () => {
	beforeEach(() => {
		axiosMock.get.mockReset()
	})

	it('counts the lines of this transaction', async () => {
		const wrapper = await mountSummary()
		expect(axiosMock.get).toHaveBeenCalledTimes(1)
		const [url, options] = axiosMock.get.mock.calls[0]
		expect(url).toBe('/index.php/apps/openregister/api/objects/pipelinq/posTransactionLine')
		expect(options.params).toMatchObject({ transaction: 'tx-1', _limit: 1 })
		expect(rows(wrapper)[0][0]).toBe('Lines')
		expect(rows(wrapper)[0][1]).toBe('3 lines')
	})

	it('lists the amount without VAT, the VAT per rate highest first, and the total', async () => {
		const wrapper = await mountSummary()
		const labels = rows(wrapper).map((row) => row[0])
		expect(labels).toEqual(['Lines', 'Excl. VAT', 'VAT 21%', 'VAT 9%', 'VAT 0%', 'Total'])
		expect(rows(wrapper)[1][1]).toContain('93,50')
		expect(rows(wrapper)[2][1]).toContain('2,17')
		expect(wrapper.find('[data-testid="pos-totals-total"]').text()).toContain('96,41')
	})

	it('keeps the tax return and the invoice split behind a disclosure', async () => {
		const wrapper = await mountSummary()
		const details = wrapper.find('details[data-testid="pos-totals-breakdown"]')
		expect(details.exists()).toBe(true)
		expect(details.attributes('open')).toBeUndefined()
		expect(details.findAll('table').length).toBe(2)
	})

	it('keeps the full layout without a layout setting', () => {
		const wrapper = mount(PosTransactionTotalsWidget, { global, props: { objectData: SALE } })
		expect(wrapper.find('[data-testid="pos-totals-row"]').exists()).toBe(false)
		expect(wrapper.findAll('table').length).toBe(2)
		expect(axiosMock.get).not.toHaveBeenCalled()
	})
})

describe('shortDateTime', () => {
	it('writes the day, the short month, the year and the time', () => {
		expect(shortDateTime('2026-10-07T11:42:00', null, null, { locale: 'nl-NL' })).toBe('7 okt 2026, 11:42')
		expect(shortDateTime('', null, null)).toBe('')
		expect(shortDateTime('not a date', null, null)).toBe('not a date')
	})

	it('is a registered formatter', () => {
		expect(CELL_FORMATTERS.shortDateTime).toBe(shortDateTime)
	})
})

describe('PosTransactionTotalsWidget summary list style', () => {
	const css = fs.readFileSync(path.resolve(__dirname, '../../src/components/pos/PosTransactionTotalsWidget.vue'), 'utf8').split('<style')[1]
	const block = (selector) => {
		const start = css.indexOf(selector + ' {')
		expect(start, selector).toBeGreaterThan(-1)
		return css.slice(start, css.indexOf('}', start))
	}

	it('drops the server padding on the list', () => {
		expect(block('.pos-tx-totals__list')).toMatch(/padding: 0;/)
	})

	it('puts each label at the start of its column, as wide as its text', () => {
		const dt = block('.pos-tx-totals__item dt')
		expect(dt).toMatch(/width: auto;/)
		expect(dt).toMatch(/padding: 0;/)
		expect(dt).toMatch(/text-align: start;/)
		expect(dt).toMatch(/white-space: normal;/)
	})

	it('starts each value at its column without server padding', () => {
		expect(block('.pos-tx-totals__item dd')).toMatch(/padding: 0;/)
	})
})

