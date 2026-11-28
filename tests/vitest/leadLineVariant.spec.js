// @vitest-environment jsdom
// SPDX-License-Identifier: EUPL-1.2
// Copyright (C) 2026 Conduction B.V.

/**
 * A lead line can name one of its product's variants by code. The line form
 * sends that code to the price resolver with the product and quantity, and
 * asks again when the code changes; a price the user typed stays theirs.
 * The fragments are merged the way ConfigFileLoaderService merges them:
 * maps recursively, lists replaced.
 *
 * @spec openspec/changes/specify-capability-rows/tasks.md#29.1
 */

import { patchFromAnswer, resolveBody, shouldResolveAgain } from '@conduction/nextcloud-vue/src/utils/childRecordResolve.js'
import { describe, expect, it } from 'vitest'
import lines from '../../lib/Settings/register.d/99-zzzzzz-quote-and-lead-lines.json'
import resolveFragment from '../../lib/Settings/register.d/99-zzzzzzzzzzzzzzzzz-lead-line-price-resolve.json'
import variantFragment from '../../lib/Settings/register.d/99-zzzzzzzzzzzzzzzzzzz-lead-line-variant.json'

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

const merged = [lines, resolveFragment, variantFragment].reduce((acc, fragment) => merge(acc, fragment), {})
const schemas = merged.components.schemas
const addFrom = schemas.lead.properties.lines.addFrom
const resolve = addFrom.resolve

const shirt = { id: 'p-shirt', name: 'Werkshirt', sku: 'WS', unitPrice: 25, unit: 'stuk' }

describe('the lead line names a variant', () => {
	it('sends the variant code with the product and the quantity', () => {
		const row = { product: 'p-shirt', quantity: 2, variantSku: 'WS-M' }
		expect(resolveBody(resolve.body, { source: shirt, row })).toEqual({ productId: 'p-shirt', quantity: 2, variantSku: 'WS-M' })
	})

	it('leaves the code out of a line without one, so the product itself is priced', () => {
		const row = { product: 'p-shirt', quantity: 1, variantSku: '' }
		expect(resolveBody(resolve.body, { source: shirt, row })).toEqual({ productId: 'p-shirt', quantity: 1 })
	})

	it('asks again when the code changes, and still when the quantity does', () => {
		const before = { product: 'p-shirt', quantity: 1, variantSku: 'WS-S', unitPrice: 25, vatRate: 21 }
		expect(shouldResolveAgain(before, { ...before, variantSku: 'WS-M' }, resolve)).toBe(true)
		expect(shouldResolveAgain(before, { ...before, quantity: 3 }, resolve)).toBe(true)
	})

	it('keeps a price the user typed in the same edit', () => {
		const before = { product: 'p-shirt', quantity: 1, variantSku: 'WS-S', unitPrice: 25, vatRate: 21 }
		expect(shouldResolveAgain(before, { ...before, variantSku: 'WS-M', unitPrice: 20 }, resolve)).toBe(false)
	})

	it('fills the variant price the resolver answers', () => {
		const answer = { unitPrice: 27.5, source: 'variant', tierLabel: '', quantity: 1, vatClass: 'high', taxRate: 21 }
		expect(patchFromAnswer(answer, resolve.map)).toEqual({ unitPrice: 27.5, vatRate: 21 })
	})

	it('keeps the resolver url, method and map of the price-resolve fragment', () => {
		expect(resolve).toMatchObject({ url: '/apps/pipelinq/api/products/resolve-price', method: 'POST', map: { unitPrice: 'unitPrice', vatRate: 'taxRate' } })
		expect(addFrom.schema).toBe('product')
	})
})

describe('the lead line schema', () => {
	it('gains the variant code as bounded text', () => {
		expect(schemas.leadProduct.properties.variantSku).toMatchObject({ type: 'string', title: 'Variant code', maxLength: 64 })
	})

	it('stamps versions above the price-resolve fragment', () => {
		expect(schemas.lead.version).toBe('1.10.0')
		expect(schemas.leadProduct.version).toBe('1.5.0')
	})
})
