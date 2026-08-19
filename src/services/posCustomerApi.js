// SPDX-License-Identifier: EUPL-1.2
// SPDX-FileCopyrightText: 2026 Conduction B.V.
//
// Frontend API client for the POS customer-link surface.
// All endpoints documented in lib/Controller/PosCustomerController.php +
// lib/Controller/PosCustomerSettingsController.php.
//
// @spec openspec/changes/pos-customer-link/specs.md#REQ-PCL-001

import axios from '@nextcloud/axios'
import { generateUrl } from '@nextcloud/router'

const base = (path) => generateUrl('/apps/pipelinq' + path)

/**
 * Search pipelinq contacts as POS customers.
 *
 * @param {string} query The search query (>= 2 chars).
 * @param {number} limit Max results (defaults to 20, capped at 100).
 * @return {Promise<Array<object>>} Decorated customer rows.
 */
export async function searchCustomers(query, limit = 20) {
	const { data } = await axios.get(base('/api/pos-customers/search'), {
		params: { query, limit },
	})
	return Array.isArray(data?.customers) ? data.customers : []
}

/**
 * Fetch the purchase history for a customer.
 *
 * @param {string} customerId The contact UUID.
 * @param {number} limit Max history rows (defaults to admin setting, 0 = use server default).
 * @return {Promise<Array<object>>} History rows.
 */
export async function getCustomerHistory(customerId, limit = 0) {
	const { data } = await axios.get(
		base('/api/pos-customers/' + encodeURIComponent(customerId) + '/history'),
		{ params: { limit } },
	)
	return Array.isArray(data?.history) ? data.history : []
}

/**
 * Attach a pipelinq contact to a draft / parked transaction.
 *
 * @param {string}  transactionId    The transaction UUID.
 * @param {string}  customerId       The contact UUID.
 * @param {boolean} marketingConsent Whether the cashier captured consent.
 * @return {Promise<object>} The updated transaction.
 */
export async function attachCustomer(transactionId, customerId, marketingConsent = false) {
	const { data } = await axios.post(
		base('/api/pos-transactions/' + encodeURIComponent(transactionId) + '/customer'),
		{ customer: customerId, marketingConsent },
	)
	return data?.transaction || null
}

/**
 * Detach the customer from a draft / parked transaction.
 *
 * @param {string} transactionId The transaction UUID.
 * @return {Promise<object>} The updated transaction.
 */
export async function detachCustomer(transactionId) {
	const { data } = await axios.delete(
		base('/api/pos-transactions/' + encodeURIComponent(transactionId) + '/customer'),
	)
	return data?.transaction || null
}

/**
 * Read the POS customer-link admin settings.
 *
 * @return {Promise<object>} The settings.
 */
export async function getCustomerSettings() {
	const { data } = await axios.get(base('/api/admin/pos-customer-settings'))
	return data?.settings || {}
}

/**
 * Update the POS customer-link admin settings.
 *
 * @param {object} settings The settings payload.
 * @return {Promise<object>} The persisted settings.
 */
export async function updateCustomerSettings(settings) {
	const { data } = await axios.post(base('/api/admin/pos-customer-settings'), settings)
	return data?.settings || {}
}
