// SPDX-License-Identifier: EUPL-1.2
// SPDX-FileCopyrightText: 2026 Conduction B.V.
//
// Thin API client for the AVG (GDPR) request workflow endpoints. Centralises the
// generateUrl + axios calls so the views stay declarative.

import axios from '@nextcloud/axios'
import { generateUrl } from '@nextcloud/router'

/**
 * Build a full AVG API URL.
 *
 * @param {string} path The path under /apps/pipelinq/api.
 * @return {string} The generated URL.
 */
function url(path) {
	return generateUrl(`/apps/pipelinq/api${path}`)
}

export default {
	/**
	 * List AVG requests with optional filters.
	 *
	 * @param {object} [filters] The list filters.
	 * @return {Promise<object>} The response data.
	 */
	async list(filters = {}) {
		const { data } = await axios.get(url('/avg-verzoeken'), { params: filters })
		return data
	},

	/**
	 * Fetch a single request.
	 *
	 * @param {string} id The request id.
	 * @return {Promise<object>} The response data.
	 */
	async get(id) {
		const { data } = await axios.get(url(`/avg-verzoeken/${id}`))
		return data
	},

	/**
	 * Register a new request (intake).
	 *
	 * @param {object} payload The intake payload.
	 * @return {Promise<object>} The response data.
	 */
	async create(payload) {
		const { data } = await axios.post(url('/avg-verzoeken'), payload)
		return data
	},

	/**
	 * Trigger evidence collection.
	 *
	 * @param {string} id The request id.
	 * @return {Promise<object>} The response data.
	 */
	async collectEvidence(id) {
		const { data } = await axios.post(url(`/avg-verzoeken/${id}/collect-evidence`))
		return data
	},

	/**
	 * Fetch the evidence items for a request.
	 *
	 * @param {string} id The request id.
	 * @return {Promise<object>} The response data.
	 */
	async evidenceItems(id) {
		const { data } = await axios.get(url(`/avg-verzoeken/${id}/bewijs-items`))
		return data
	},

	/**
	 * Apply a field-level redaction.
	 *
	 * @param {string} id The request id.
	 * @param {object} payload The redaction payload.
	 * @return {Promise<object>} The response data.
	 */
	async redact(id, payload) {
		const { data } = await axios.post(url(`/avg-verzoeken/${id}/redact`), payload)
		return data
	},

	/**
	 * Generate the export bundle.
	 *
	 * @param {string} id The request id.
	 * @return {Promise<object>} The response data.
	 */
	async generateBundle(id) {
		const { data } = await axios.post(url(`/avg-verzoeken/${id}/generate-bundle`))
		return data
	},

	/**
	 * Draft a denial.
	 *
	 * @param {string} id The request id.
	 * @param {object} payload The denial payload.
	 * @return {Promise<object>} The response data.
	 */
	async deny(id, payload) {
		const { data } = await axios.post(url(`/avg-verzoeken/${id}/deny`), payload)
		return data
	},

	/**
	 * Request a 60-day extension.
	 *
	 * @param {string} id The request id.
	 * @param {string} verlengingsgrond The justification.
	 * @return {Promise<object>} The response data.
	 */
	async extend(id, verlengingsgrond) {
		const { data } = await axios.post(url(`/avg-verzoeken/${id}/extend`), { verlengingsgrond })
		return data
	},
}
