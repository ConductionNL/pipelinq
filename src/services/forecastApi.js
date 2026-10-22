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
