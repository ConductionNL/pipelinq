// SPDX-License-Identifier: EUPL-1.2
// SPDX-FileCopyrightText: 2026 Conduction B.V.
//
// Thin frontend API client for the POS payment provider adapter.
// All endpoints are documented in lib/Controller/PosPaymentController.php.
//
// @spec openspec/changes/pos-payment-provider-adapter/specs/pos-payment-provider-adapter/spec.md#REQ-PAY-003

import axios from '@nextcloud/axios'
import { generateUrl } from '@nextcloud/router'

const base = (path) => generateUrl('/apps/pipelinq' + path)

/**
 * List configured payment providers (credentials masked).
 *
 * @return {Promise<Array<object>>} The provider list.
 */
export async function listProviders() {
	const { data } = await axios.get(base('/api/payment-providers'))
	return (data && data.providers) ? data.providers : []
}

/**
 * Fetch a single provider config.
 *
 * @param {string} name The provider name.
 * @return {Promise<object>} The provider config.
 */
export async function getProvider(name) {
	const { data } = await axios.get(base('/api/payment-providers/' + encodeURIComponent(name)))
	return (data && data.provider) ? data.provider : null
}

/**
 * Update a provider config (admin only — credentials are encrypted server-side).
 *
 * @param {string} name The provider name.
 * @param {object} config The form payload.
 * @return {Promise<object>} The updated provider config (credentials masked).
 */
export async function updateProvider(name, config) {
	const { data } = await axios.put(base('/api/payment-providers/' + encodeURIComponent(name)), config)
	return (data && data.provider) ? data.provider : null
}

/**
 * Test the connection for a provider.
 *
 * @param {string} name The provider name.
 * @return {Promise<{status: string, message: string}>} The test result.
 */
export async function testConnection(name) {
	const { data } = await axios.post(base('/api/payment-providers/' + encodeURIComponent(name) + '/test'))
	return (data && data.result) ? data.result : { status: 'error', message: 'No result' }
}

/**
 * Initiate a payment for a transaction.
 *
 * @param {string} transactionId The transaction id.
 * @param {string} providerName The provider name.
 * @param {string} paymentMethod The method.
 * @return {Promise<object>} { transaction, payment }
 */
export async function initiatePayment(transactionId, providerName, paymentMethod) {
	const { data } = await axios.post(
		base('/api/pos-payments/' + encodeURIComponent(transactionId) + '/initiate'),
		{ providerName, paymentMethod },
	)
	return data
}

/**
 * Capture a payment.
 *
 * @param {string} transactionId The transaction id.
 * @return {Promise<object>} { transaction, payment }
 */
export async function capturePayment(transactionId) {
	const { data } = await axios.post(
		base('/api/pos-payments/' + encodeURIComponent(transactionId) + '/capture'),
	)
	return data
}

/**
 * Refund a payment.
 *
 * @param {string} transactionId The transaction id.
 * @param {string} reason The refund reason.
 * @return {Promise<object>} { transaction, payment }
 */
export async function refundPayment(transactionId, reason) {
	const { data } = await axios.post(
		base('/api/pos-payments/' + encodeURIComponent(transactionId) + '/refund'),
		{ reason },
	)
	return data
}

export default {
	listProviders,
	getProvider,
	updateProvider,
	testConnection,
	initiatePayment,
	capturePayment,
	refundPayment,
}
