// SPDX-License-Identifier: EUPL-1.2
// SPDX-FileCopyrightText: 2024 Conduction B.V.

/**
 * POS customer-link API client.
 *
 * Thin wrappers around the server-authoritative POS customer endpoints. Every
 * lookup / attach / history call is gated and tenant-scoped on the backend; the
 * frontend only renders what the server returns.
 */

import { generateUrl } from '@nextcloud/router'

/**
 * Default headers for an OCS-style POST against the POS API.
 *
 * @return {object} The fetch headers.
 */
function postHeaders() {
	return {
		'Content-Type': 'application/json',
		requesttoken: OC.requestToken,
		'OCS-APIREQUEST': 'true',
	}
}

/**
 * Search Pipelinq contacts for the customer-lookup modal.
 *
 * @param {string} query The free-text query (name / email / phone).
 * @param {number} limit The maximum number of contacts to return.
 * @return {Promise<Array<object>>} The matching, decorated contacts.
 */
export async function searchContacts(query, limit = 20) {
	const url = generateUrl('/apps/pipelinq/api/pos-customers/search?query={query}&limit={limit}', {
		query,
		limit,
	})
	const response = await fetch(url, { headers: { 'OCS-APIREQUEST': 'true' } })
	const data = await response.json().catch(() => ({}))
	if (!response.ok) {
		throw new Error(data.error || 'search failed')
	}
	return data.contacts || []
}

/**
 * Fetch a customer's purchase history.
 *
 * @param {string} customerId The contact UUID.
 * @param {number|null} limit An optional override of the history depth.
 * @return {Promise<object>} The history payload (count, lifetimeSpend, transactions).
 */
export async function getPurchaseHistory(customerId, limit = null) {
	let path = `/apps/pipelinq/api/pos-customers/${encodeURIComponent(customerId)}/history`
	if (limit !== null) {
		path += `?limit=${encodeURIComponent(limit)}`
	}
	const response = await fetch(generateUrl(path), { headers: { 'OCS-APIREQUEST': 'true' } })
	const data = await response.json().catch(() => ({}))
	if (!response.ok) {
		throw new Error(data.error || 'history failed')
	}
	return data.history || { count: 0, lifetimeSpend: 0, transactions: [] }
}

/**
 * Attach (or clear) a customer on a transaction.
 *
 * @param {string} transactionId The transaction UUID.
 * @param {object} payload The link payload (customer, marketingConsent, tenderType).
 * @return {Promise<object>} The updated transaction.
 */
export async function attachCustomer(transactionId, payload) {
	const response = await fetch(
		generateUrl(`/apps/pipelinq/api/pos-transactions/${encodeURIComponent(transactionId)}/customer`),
		{
			method: 'POST',
			headers: postHeaders(),
			body: JSON.stringify(payload || {}),
		},
	)
	const data = await response.json().catch(() => ({}))
	if (!response.ok) {
		throw new Error(data.error || 'attach failed')
	}
	return data.transaction
}
