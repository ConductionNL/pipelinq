/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 * SPDX-License-Identifier: EUPL-1.2
 *
 * The line items (LeadProduct objects) of the leads on the pipeline board:
 * grouped per lead, the names of their products, and the per-stage totals
 * per product.
 *
 * @module views/pipeline/leadProductBreakdown
 */

/**
 * The ids of the leads among the board items.
 *
 * @param {Array<object>} items The board items.
 * @return {Set<string>} The lead ids.
 * @spec openspec/changes/archive/2026-06-14-lead-product-link/tasks.md#task-4.1
 */
export function boardLeadIds(items) {
	return new Set(
		items
			.filter((i) => i._schemaSlug === 'lead')
			.map((i) => i.id)
			.filter(Boolean),
	)
}

/**
 * The line items of the given leads, grouped per lead, and the products they name.
 *
 * @param {?Array<object>} lpCollection Every line item.
 * @param {Set<string>} leadIds The leads to keep.
 * @return {{byLead: object, productIds: Set<string>}} The groups and the product ids.
 * @spec openspec/changes/archive/2026-06-14-lead-product-link/tasks.md#task-4.1
 */
export function groupLeadProducts(lpCollection, leadIds) {
	const byLead = {}
	const productIds = new Set()
	for (const lp of lpCollection || []) {
		if (!lp.lead || !leadIds.has(lp.lead)) continue
		if (!byLead[lp.lead]) byLead[lp.lead] = []
		byLead[lp.lead].push(lp)
		if (lp.product) productIds.add(lp.product)
	}
	return { byLead, productIds }
}

/**
 * The names of the given products, by id; a product without a name goes by its id.
 *
 * @param {?Array<object>} products Every product.
 * @param {Set<string>} productIds The products to name.
 * @return {object} The names by product id.
 * @spec openspec/changes/archive/2026-06-14-lead-product-link/tasks.md#task-4.1
 */
export function productNames(products, productIds) {
	const names = {}
	for (const p of products || []) {
		if (productIds.has(p.id)) {
			names[p.id] = p.name || p.id
		}
	}
	return names
}

/**
 * Sum the line items per product.
 *
 * @param {Array<object>} lineItems The line items.
 * @param {object} namesById The product names by id.
 * @return {Array<{product: string, name: string, count: number, total: number}>} One entry per product, highest total first.
 * @spec openspec/changes/archive/2026-06-14-lead-product-link/tasks.md#task-4.2
 */
export function productTotals(lineItems, namesById) {
	const aggregate = new Map()
	for (const lp of lineItems) {
		if (!lp.product) continue
		const current = aggregate.get(lp.product) || emptyTotal(lp.product, namesById)
		current.count += 1
		current.total += Number(lp.total) || 0
		aggregate.set(lp.product, current)
	}
	return Array.from(aggregate.values()).sort(
		(a, b) => b.total - a.total,
	)
}

/**
 * A product's total before its first line item.
 *
 * @param {string} product The product id.
 * @param {object} namesById The product names by id.
 * @return {{product: string, name: string, count: number, total: number}} The empty total.
 */
function emptyTotal(product, namesById) {
	return {
		product,
		name: namesById[product] || product,
		count: 0,
		total: 0,
	}
}
