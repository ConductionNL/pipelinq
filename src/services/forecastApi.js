/**
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * Forecast API client.
 *
 * Thin wrapper around the pipelinq forecast REST endpoints. All forecast math
 * is server-authoritative; this module only transports requests/responses.
 */

import axios from '@nextcloud/axios'
import { generateUrl } from '@nextcloud/router'

const base = '/apps/pipelinq/api/forecast'

/**
 * Fetch forecast snapshots for a period and level.
 *
 * @param {object} params Query parameters.
 * @param {string} params.periodId The fiscal period id.
 * @param {string} params.level The hierarchy level.
 * @param {string} [params.ownerId] Optional owner filter.
 * @param {number} [params.limit] Page size.
 * @param {number} [params.offset] Page offset.
 * @return {Promise<object>} The snapshot page payload.
 */
export async function fetchSnapshots({
	periodId,
	level,
	ownerId = null,
	limit = 50,
	offset = 0,
}) {
	const query = { periodId, level, format: 'json', limit, offset }
	if (ownerId) {
		query.ownerId = ownerId
	}
	const response = await axios.get(generateUrl(base + '/snapshots'), {
		params: query,
	})
	return response.data
}

/**
 * Build the CSV export URL for a period and level.
 *
 * @param {object} params Query parameters.
 * @param {string} params.periodId The fiscal period id.
 * @param {string} params.level The hierarchy level.
 * @param {string} [params.ownerId] Optional owner filter.
 * @return {string} The download URL.
 */
export function csvExportUrl({ periodId, level, ownerId = null }) {
	let url =
		generateUrl(base + '/snapshots')
		+ '?periodId='
		+ encodeURIComponent(periodId)
		+ '&level='
		+ encodeURIComponent(level)
		+ '&format=csv'
	if (ownerId) {
		url += '&ownerId=' + encodeURIComponent(ownerId)
	}
	return url
}

/**
 * Create a forecast override.
 *
 * @param {object} payload The override payload.
 * @return {Promise<object>} The created override.
 */
export async function createOverride(payload) {
	const response = await axios.post(generateUrl(base + '/overrides'), payload)
	return response.data
}

/**
 * Delete a forecast override.
 *
 * @param {string} id The override id.
 * @return {Promise<object>} The deletion result.
 */
export async function deleteOverride(id) {
	const response = await axios.delete(
		generateUrl(base + '/overrides/' + encodeURIComponent(id)),
	)
	return response.data
}
