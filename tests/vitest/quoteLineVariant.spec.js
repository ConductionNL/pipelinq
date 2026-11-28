// @vitest-environment jsdom
// SPDX-License-Identifier: EUPL-1.2
// Copyright (C) 2026 Conduction B.V.

/**
 * A product picked onto a quote line asks the price resolver for its unit
 * price at the line's quantity and variant, and asks again when either
 * changes; a price the user typed stays theirs, and the VAT rate stays the
 * quote's own. The fragments are merged the way ConfigFileLoaderService
 * merges them: maps recursively, lists replaced.
 *
 * @spec openspec/changes/specify-capability-rows/tasks.md#29.1
 */

import { patchFromAnswer, resolveBody, shouldResolveAgain } from '@conduction/nextcloud-vue/src/utils/childRecordResolve.js'
import { rowFromSource } from '@conduction/nextcloud-vue/src/utils/childRecordTotals.js'
import { describe, expect, it } from 'vitest'
import nameAndVat from '../../lib/Settings/register.d/99-zzzzz-quote-name-and-vat-rate.json'
import lines from '../../lib/Settings/register.d/99-zzzzzz-quote-and-lead-lines.json'
import variantFragment from '../../lib/Settings/register.d/99-zzzzzzzzzzzzzzzzzzzz-quote-line-variant.json'

/**
 * Whether a value is a JSON object rather than a list or a scalar.
 *
 * @param {*} value The value.
 * @return {boolean} True for an object.
 */
function isMap(value) {
	return value !== null && typeof value === 'object' && !Array.isArray(value)
}

/**
 * Merge a fragment the way ConfigFileLoaderService does: objects recursively, lists and scalars replaced.
 *
 * @param {object} base The configuration so far.
 * @param {object} override The fragment.
 * @return {object} The merged configuration.
 */
function merge(base, override) {
	const out = { ...base }
	for (const [key, value] of Object.entries(override)) {
		out[key] = isMap(value) && isMap(base?.[key]) ? merge(base[key], value) : value
	}
	return out
}

const merged = [nameAndVat, lines, variantFragment].reduce((acc, fragment) => merge(acc, fragment), {})
const schemas = merged.components.schemas
const addFrom = schemas.salesQuote.properties.lines.addFrom
const resolve = addFrom.resolve

const shirt = { id: 'p-shirt', name: 'Werkshirt', sku: 'WS', unitPrice: 25 }

describe('the quote line asks the price resolver', () => {
	it('posts the picked product, the quantity and the variant code', () => {
		expect(resolve).toMatchObject({ url: '/apps/pipelinq/api/products/resolve-price', method: 'POST' })
		const row = { ...rowFromSource(shirt, addFrom.map), quantity: 3, variantSku: 'WS-M' }
		expect(resolveBody(resolve.body, { source: shirt, row })).toEqual({ productId: 'p-shirt', quantity: 3, variantSku: 'WS-M' })
	})

	it('leaves the quantity and the code out of a fresh line, so the product itself is priced for one', () => {
		const row = rowFromSource(shirt, addFrom.map)
		expect(resolveBody(resolve.body, { source: shirt, row })).toEqual({ productId: 'p-shirt' })
	})

	it('fills the unit price and leaves the VAT rate to the quote', () => {
		const answer = { unitPrice: 27.5, source: 'variant', tierLabel: '', quantity: 1, vatClass: 'high', taxRate: 21 }
		expect(patchFromAnswer(answer, resolve.map)).toEqual({ unitPrice: 27.5 })
	})

	it('asks again when the quantity or the code changes', () => {
		const before = { product: 'p-shirt', quantity: 1, variantSku: '', unitPrice: 25 }
		expect(shouldResolveAgain(before, { ...before, quantity: 12 }, resolve)).toBe(true)
		expect(shouldResolveAgain(before, { ...before, variantSku: 'WS-M' }, resolve)).toBe(true)
	})

	it('keeps a price the user typed in the same edit', () => {
		const before = { product: 'p-shirt', quantity: 1, variantSku: '', unitPrice: 25 }
		expect(shouldResolveAgain(before, { ...before, quantity: 12, unitPrice: 20 }, resolve)).toBe(false)
	})

	it('keeps the picker and the columns of the lines fragment', () => {
		expect(addFrom).toMatchObject({ schema: 'product', register: 'pipelinq', map: { product: '@id', unitPrice: 'unitPrice' } })
		expect(schemas.salesQuote.properties.lines.columns).toEqual(['description', 'quantity', 'unitPrice', 'discount'])
	})
})

describe('the quote line schema', () => {
	it('gains the variant code as bounded text', () => {
		expect(schemas.salesQuoteLine.properties.variantSku).toMatchObject({ type: 'string', title: 'Variant code', maxLength: 64 })
	})

	it('stamps versions above the earlier quote fragments', () => {
		expect(schemas.salesQuote.version).toBe('1.3.0')
		expect(schemas.salesQuoteLine.version).toBe('1.2.0')
	})
})
