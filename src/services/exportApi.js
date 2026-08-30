// SPDX-License-Identifier: EUPL-1.2
// SPDX-FileCopyrightText: 2024 Conduction B.V.
//
// Thin client for the BI-export controller endpoints that the generic
// OpenRegister object API cannot express: destination connection test, job
// test-run, enable/disable, and run history filtering + retry. Object CRUD
// (create/read/update/delete of exportDestination / exportJob / exportRun)
// goes through the shared object store, not this module.
//
// Credentials are never handled here — the warehouse credentials live in the
// referenced OpenConnector source and are resolved server-side only (ADR-005).

import { generateUrl } from '@nextcloud/router'

/**
 * Perform a JSON request against a pipelinq export endpoint.
 *
 * @param {string} path The path under /apps/pipelinq/api/export.
 * @param {string} method The HTTP method.
 * @param {object} [body] The optional JSON body.
 * @return {Promise<object>} The parsed JSON response.
 * @throws {Error} When the response is not ok (message from the server error).
 */
async function request(path, method, body) {
	const options = {
		method,
		headers: {
			'Content-Type': 'application/json',
			requesttoken: OC.requestToken,
			'OCS-APIREQUEST': 'true',
		},
	}
	if (body !== undefined) {
		options.body = JSON.stringify(body)
	}

	const response = await fetch(
		generateUrl(`/apps/pipelinq/api/export${path}`),
		options,
	)
	const data = await response.json().catch(() => ({}))
	if (!response.ok) {
		throw new Error(data.error || 'Export request failed')
	}

	return data
}

export const exportApi = {
	/**
	 * Test connectivity to a destination.
	 *
	 * @param {string} id The destination UUID.
	 * @return {Promise<object>} { valid: boolean }.
	 */
	testDestination(id) {
		return request(`/destinations/${id}/test`, 'POST')
	},

	/**
	 * Run a non-destructive test of a job.
	 *
	 * @param {string} id The job UUID.
	 * @return {Promise<object>} The test result envelope's `result`.
	 */
	async testRun(id) {
		const data = await request(`/jobs/${id}/test-run`, 'POST')
		return data.result || data
	},

	/**
	 * Enable a job for scheduled execution.
	 *
	 * @param {string} id The job UUID.
	 * @return {Promise<object>} The enabled job.
	 */
	enableJob(id) {
		return request(`/jobs/${id}/enable`, 'POST')
	},

	/**
	 * Disable a job.
	 *
	 * @param {string} id The job UUID.
	 * @return {Promise<object>} The disabled job.
	 */
	disableJob(id) {
		return request(`/jobs/${id}/disable`, 'POST')
	},

	/**
	 * List runs with optional filters.
	 *
	 * @param {object} [filters] job_id, status, date_from, date_to.
	 * @return {Promise<Array<object>>} The runs.
	 */
	async listRuns(filters = {}) {
		const query = new URLSearchParams()
		Object.entries(filters).forEach(([key, value]) => {
			if (value) {
				query.set(key, value)
			}
		})
		const suffix = query.toString() ? `?${query.toString()}` : ''
		const data = await request(`/runs${suffix}`, 'GET')
		return data.runs || []
	},

	/**
	 * Get one run with its schema snapshots.
	 *
	 * @param {string} id The run UUID.
	 * @return {Promise<object>} { run, snapshots }.
	 */
	getRun(id) {
		return request(`/runs/${id}`, 'GET')
	},

	/**
	 * Retry a failed or partial run.
	 *
	 * @param {string} id The run UUID.
	 * @return {Promise<object>} The new pending run.
	 */
	retryRun(id) {
		return request(`/runs/${id}/retry`, 'POST')
	},
}
