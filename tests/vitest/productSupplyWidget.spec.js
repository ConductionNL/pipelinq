// @vitest-environment jsdom
/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 * SPDX-License-Identifier: EUPL-1.2
 *
 * The Stock tracked line of the Supply card (products-stock-on-hand, task
 * 2.2): the four states the stock endpoint answers, the location line and
 * the tooltip.
 *
 * @spec openspec/changes/products-stock-on-hand/specs/product-stock/spec.md#requirement-the-product-page-shows-the-available-stock-req-pst-001
 */

import { flushPromises, mount } from '@vue/test-utils'
import { beforeEach, describe, expect, it, vi } from 'vitest'

const axiosMock = vi.hoisted(() => ({ get: vi.fn() }))

vi.mock('@nextcloud/axios', () => ({ default: axiosMock }))
vi.mock('@nextcloud/router', () => ({
	generateUrl: (path, params) =>
		'/index.php'
		+ String(path).replace(/\{(\w+)\}/g, (whole, key) =>
			params && key in params ? params[key] : whole,
		),
}))
vi.mock('@nextcloud/l10n', () => ({
	translate: (app, text, vars) =>
		String(text).replace(/\{(\w+)\}/g, (whole, key) =>
			vars && key in vars ? String(vars[key]) : whole,
		),
}))

const { default: ProductSupplyWidget } = await import(
	'../../src/components/products/ProductSupplyWidget.vue'
)

/**
 * Mount the card on a product and let the stock read settle.
 *
 * @param {object} answer The stock endpoint's answer.
 * @param {object} [product] The product.
 * @return {Promise<object>} The wrapper.
 */
async function mountWith(answer, product = { unitOfMeasure: 'pieces' }) {
	axiosMock.get.mockResolvedValue({ data: answer })
	const wrapper = mount(ProductSupplyWidget, {
		props: { objectId: 'p-1', objectData: product },
	})
	await flushPromises()
	return wrapper
}

const stockLine = (wrapper) => wrapper.find('[data-testid="product-stock"]')

describe('ProductSupplyWidget', () => {
	beforeEach(() => {
		axiosMock.get.mockReset()
	})

	it('reads the stock of this product on load', async () => {
		await mountWith({ state: 'untracked', tracked: false })

		expect(axiosMock.get).toHaveBeenCalledWith(
			'/index.php/apps/pipelinq/api/products/p-1/stock',
		)
	})

	it('shows the available sum with the unit, the locations and the tooltip', async () => {
		const wrapper = await mountWith({
			state: 'ok',
			tracked: true,
			available: 1860,
			onHand: 1960,
			reserved: 100,
			unit: 'pieces',
			locations: [
				{ code: 'AMS', name: 'Amsterdam', available: 1200 },
				{ code: 'UTR', name: 'Utrecht', available: 660 },
			],
		})

		const line = stockLine(wrapper)
		expect(line.text()).toContain(`Yes, ${(1860).toLocaleString()} pieces`)
		expect(wrapper.find('[data-testid="product-stock-locations"]').text()).toBe(
			`Amsterdam ${(1200).toLocaleString()} · Utrecht 660`,
		)
		expect(line.find('[title]').attributes('title')).toBe(
			`${(1960).toLocaleString()} on hand, 100 reserved`,
		)
	})

	it('names no locations for a single one', async () => {
		const wrapper = await mountWith({
			state: 'ok',
			available: 70,
			onHand: 100,
			reserved: 30,
			unit: '',
			locations: [{ code: 'AMS', name: 'Amsterdam', available: 70 }],
		})

		expect(stockLine(wrapper).text()).toBe('Yes, 70')
		expect(wrapper.find('[data-testid="product-stock-locations"]').exists()).toBe(false)
	})

	it('says No for an untracked product', async () => {
		const wrapper = await mountWith({ state: 'untracked', tracked: false })

		expect(stockLine(wrapper).text()).toBe('No')
	})

	it('says shillinq is not installed, without a link', async () => {
		const wrapper = await mountWith({ state: 'no-shillinq', tracked: true })

		expect(stockLine(wrapper).text()).toBe(
			'Stock is kept in shillinq, which is not installed',
		)
		expect(wrapper.find('a').exists()).toBe(false)
	})

	it('says there is no access instead of a number', async () => {
		const wrapper = await mountWith({ state: 'no-access', tracked: true })

		expect(stockLine(wrapper).text()).toBe('No access to stock in shillinq')
		expect(stockLine(wrapper).text()).not.toMatch(/\d/)
	})
})
