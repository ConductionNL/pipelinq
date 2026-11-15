// SPDX-License-Identifier: EUPL-1.2
// SPDX-FileCopyrightText: 2026 Conduction B.V.
//
// Client for OpenRegister's bulk job endpoints the Bulk changes page uses:
// list the caller's jobs, ask for the reversal of one (a preview, nothing
// written) and commit a job. OpenRegister checks who may do what.
//
// @spec openspec/changes/clients-bulk-edit-and-undo/specs/client-bulk-changes/spec.md

import { generateUrl } from '@nextcloud/router'

/**
 * Call an OpenRegister bulk job endpoint.
 *
 * @param {string} path The path below /apps/openregister/api/bulk-jobs.
 * @param {string} method The HTTP method.
 * @return {Promise<object>} The JSON answer.
 * @throws {Error} With the refusal reason or error when the call fails.
 */
async function request(path, method) {
	const response = await fetch(generateUrl(`/apps/openregister/api/bulk-jobs${path}`), {
		method,
		headers: {
			'Content-Type': 'application/json',
			requesttoken: OC.requestToken,
		},
	})
	const data = await response.json().catch(() => ({}))
	if (!response.ok) {
		throw new Error(data.reason || data.error || 'Bulk change request failed')
	}
	return data
}

export const bulkJobsApi = {
	/**
	 * The caller's own jobs.
	 *
	 * @return {Promise<Array<object>>}
	 */
	async list() {
		return (await request('?limit=100', 'GET')).results || []
	},

	/**
	 * Ask for the reversal of a job: a new job, previewed, not yet written.
	 *
	 * @param {number} id The job id.
	 * @return {Promise<object>} The reversal job.
	 */
	reverse(id) {
		return request(`/${id}/reverse`, 'POST')
	},

	/**
	 * Commit a previewed job.
	 *
	 * @param {number} id The job id.
	 * @return {Promise<object>}
	 */
	commit(id) {
		return request(`/${id}/commit`, 'POST')
	},
}
