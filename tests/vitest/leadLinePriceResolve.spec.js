// @vitest-environment jsdom
// SPDX-License-Identifier: EUPL-1.2
// Copyright (C) 2026 Conduction B.V.

/**
 * A product picked onto a lead line asks the price resolver for its unit
 * price and VAT rate at the line's quantity, and asks again when the
 * quantity changes; a price the user typed stays theirs. The answers below
 * are the resolver's own shapes (ProductCatalogService::resolveEffectivePrice)
 * for a base, a tier and a variant price.
 *
 * @spec openspec/changes/products-quote-from-lead/tasks.md#task-1
 */

import { rowFromSource } from '@conduction/nextcloud-vue/src/utils/childRecordTotals.js'
import { patchFromAnswer, resolveBody, shouldResolveAgain } from '@conduction/nextcloud-vue/src/utils/childRecordResolve.js'
import { describe, expect, it } from 'vitest'
import lines from '../../lib/Settings/register.d/99-zzzzzz-quote-and-lead-lines.json'
import resolveFragment from '../../lib/Settings/register.d/99-zzzzzzzzzzzzzzzzz-lead-line-price-resolve.json'

const addFrom = {
	...lines.components.schemas.lead.properties.lines.addFrom,
	...resolveFragment.components.schemas.lead.properties.lines.addFrom,
}
const resolve = addFrom.resolve

const product = { id: 'p-koffie', name: 'Koffiebonen 1 kg', sku: 'KOF-1', unitPrice: 18.5, unit: 'kg', vatClass: 'low' }

const answers = {
	base: { unitPrice: 18.5, source: 'base', tierLabel: '', quantity: 1, vatClass: 'low', taxRate: 9 },
	tier: { unitPrice: 16.25, source: 'tier', tierLabel: '10+', quantity: 12, vatClass: 'low', taxRate: 9 },
	variant: { unitPrice: 21, source: 'variant', tierLabel: '', quantity: 1, vatClass: 'high', taxRate: 21 },
}

describe('the lead line asks the price resolver', () => {
	it('posts to the app resolver with the picked product id and the quantity', () => {
		expect(resolve.url).toBe('/apps/pipelinq/api/products/resolve-price')
		expect(resolve.method).toBe('POST')
		const row = { ...rowFromSource(product, addFrom.map), quantity: 12 }
		expect(resolveBody(resolve.body, { source: product, row })).toEqual({ productId: 'p-koffie', quantity: 12 })
	})

	it('leaves the quantity out of a fresh line, so the resolver prices one', () => {
		const row = rowFromSource(product, addFrom.map)
		expect(resolveBody(resolve.body, { source: product, row })).toEqual({ productId: 'p-koffie' })
	})

	it('fills the base price and the VAT rate', () => {
		expect(patchFromAnswer(answers.base, resolve.map)).toEqual({ unitPrice: 18.5, vatRate: 9 })
	})

	it('fills the tier price for twelve', () => {
		expect(patchFromAnswer(answers.tier, resolve.map)).toEqual({ unitPrice: 16.25, vatRate: 9 })
	})

	it('fills a variant price and its own VAT rate', () => {
		expect(patchFromAnswer(answers.variant, resolve.map)).toEqual({ unitPrice: 21, vatRate: 21 })
	})

	it('keeps the unit the product carries', () => {
		const row = { ...rowFromSource(product, addFrom.map), ...patchFromAnswer(answers.base, resolve.map) }
		expect(row).toMatchObject({ product: 'p-koffie', name: 'Koffiebonen 1 kg', unit: 'kg', unitPrice: 18.5, vatRate: 9 })
	})

	it('asks again when the quantity changes', () => {
		const before = { product: 'p-koffie', quantity: 1, unitPrice: 18.5, vatRate: 9 }
		expect(shouldResolveAgain(before, { ...before, quantity: 12 }, resolve)).toBe(true)
	})

	it('keeps a price the user typed in the same edit', () => {
		const before = { product: 'p-koffie', quantity: 1, unitPrice: 18.5, vatRate: 9 }
		expect(shouldResolveAgain(before, { ...before, quantity: 12, unitPrice: 15 }, resolve)).toBe(false)
	})
})

describe('the lead line schema', () => {
	const schemas = resolveFragment.components.schemas

	it('gains the VAT rate the quote groups by', () => {
		expect(schemas.leadProduct.properties.vatRate).toMatchObject({ type: 'number', minimum: 0, maximum: 100 })
	})

	it('moves the versions past every earlier fragment', () => {
		expect(schemas.lead.version).toBe('1.9.0')
		expect(schemas.leadProduct.version).toBe('1.4.0')
	})
})
