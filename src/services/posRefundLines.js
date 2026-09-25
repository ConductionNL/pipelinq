// SPDX-License-Identifier: EUPL-1.2
// Copyright (C) 2026 Conduction B.V.

/**
 * Loaders for a POS refund's lines, shared by the PosRefundDetail grid
 * widgets.
 *
 * Each result is filtered to the requested parent again client-side, because
 * the object store keeps one collection per type and another widget may have
 * filled it for a different parent.
 */

/**
 * Fetch the lines of one refund.
 *
 * @param {object} store The pipelinq object store.
 * @param {string} refundId The refund UUID.
 * @return {Promise<Array<object>>} The refund's posRefundLine rows.
 */
export async function fetchRefundLines(store, refundId) {
	if (!refundId) {
		return []
	}
	await store.fetchCollection('posRefundLine', { refund: refundId, _limit: 500 })
	return (store.getCollection('posRefundLine')?.results || [])
		.filter((line) => line.refund === refundId)
}

/**
 * Fetch the lines of the transaction a refund returns items from.
 *
 * @param {object} store The pipelinq object store.
 * @param {string} transactionId The original transaction UUID.
 * @return {Promise<Array<object>>} Its posTransactionLine rows.
 */
export async function fetchOriginalLines(store, transactionId) {
	if (!transactionId) {
		return []
	}
	await store.fetchCollection('posTransactionLine', { transaction: transactionId, _limit: 500 })
	return (store.getCollection('posTransactionLine')?.results || [])
		.filter((line) => line.transaction === transactionId)
}
