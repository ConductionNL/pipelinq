// SPDX-License-Identifier: EUPL-1.2
// Copyright (C) 2026 Conduction B.V.

/**
 * The till's open sale as the server prices it.
 *
 * The till stores each line as its own posTransactionLine; the server writes
 * the line's VAT and total as it stores it, and on recalculate stores the
 * sale's VAT per rate and totals. Nothing here works out a price: these
 * helpers only shape what is sent and copy back what the server returns.
 *
 * @spec openspec/changes/till-server-priced-lines/tasks.md#task-3
 */

import axios from '@nextcloud/axios'
import { generateUrl } from '@nextcloud/router'

/** The sale fields only the server writes. */
export const SERVER_TOTALS = ['subtotal', 'discountTotal', 'taxBreakdown', 'invoiceBreakdown', 'totalTax', 'total']

/** The line fields only the server writes. */
const SERVER_LINE_FIGURES = ['taxAmount', 'lineTotal']

/**
 * Whether a line says enough to be stored (it has a product or description).
 *
 * @param {object} line The till line.
 * @return {boolean} True when it can be stored.
 */
export function isStorable(line) {
	return String(line?.description || '').trim() !== ''
}

/**
 * The posTransactionLine to store for a till line: what the cashier entered, never a figure.
 *
 * @param {object} line The till line.
 * @param {string} transactionId The sale.
 * @param {number} sortOrder The line's place on the sale.
 * @return {object} The object to save.
 */
export function linePayload(line, transactionId, sortOrder) {
	const payload = {
		transaction: transactionId,
		product: line.product || null,
		description: line.description,
		quantity: Number(line.quantity) || 0,
		unitPrice: Number(line.unitPrice) || 0,
		discount: Number(line.discount) || 0,
		taxRate: Number(line.taxRate) || 0,
		sortOrder,
		notes: line.notes || '',
	}
	if (line.id) {
		payload.id = line.id
	}
	return payload
}

/**
 * The sale header to store, without the totals the server owns.
 *
 * @param {object} transaction The sale as the till holds it.
 * @return {object} The object to save.
 */
export function headerPayload(transaction) {
	const header = { ...transaction }
	for (const key of SERVER_TOTALS) {
		delete header[key]
	}
	return header
}

/**
 * The totals of a sale as the server stored them.
 *
 * @param {object} transaction The sale.
 * @return {{subtotal: number, discountTotal: number, taxBreakdown: Array<object>, totalTax: number, total: number}} The totals.
 */
export function storedTotals(transaction) {
	return {
		subtotal: Number(transaction?.subtotal) || 0,
		discountTotal: Number(transaction?.discountTotal) || 0,
		taxBreakdown: Array.isArray(transaction?.taxBreakdown) ? transaction.taxBreakdown : [],
		totalTax: Number(transaction?.totalTax) || 0,
		total: Number(transaction?.total) || 0,
	}
}

/**
 * The till lines with the server's figures copied onto the stored ones.
 *
 * @param {Array<object>} lines The till lines.
 * @param {Array<object>} stored The lines as the server returned them.
 * @return {Array<object>} The till lines.
 */
export function withServerFigures(lines, stored) {
	const byId = new Map((stored || []).map((line) => [String(line.id || line['@self']?.id || ''), line]))
	return lines.map((line) => {
		const server = line.id ? byId.get(String(line.id)) : null
		if (!server) {
			return line
		}
		const figures = Object.fromEntries(SERVER_LINE_FIGURES.map((key) => [key, Number(server[key]) || 0]))
		return { ...line, ...figures }
	})
}

/**
 * Ask the server to price the open sale.
 *
 * @param {string} transactionId The sale.
 * @return {Promise<{transaction: object, lines: Array<object>}>} The sale and its lines as the server stored them.
 */
export async function recalculateSale(transactionId) {
	const { data } = await axios.post(generateUrl(`/apps/pipelinq/api/pos-transactions/${encodeURIComponent(transactionId)}/recalculate`))
	return { transaction: data?.transaction || {}, lines: Array.isArray(data?.lines) ? data.lines : [] }
}
