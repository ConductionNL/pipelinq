// SPDX-License-Identifier: EUPL-1.2
// Copyright (C) 2026 Conduction B.V.
//
// One OpenRegister list request for an object type the app has registered:
// the URL with its query, and the fetch that reads the objects from it. The
// dashboard data cache and the dashboard widgets that fetch on their own use
// the same request.

import { generateUrl } from '@nextcloud/router'

/**
 * The list URL of a registered object type; empty query values are left out.
 *
 * @param {{register: string, schema: string}} typeConfig The type's register and schema.
 * @param {object} params Query parameters.
 * @return {string} The URL.
 */
export function objectsUrl(typeConfig, params = {}) {
	const query = queryString(params)
	return generateUrl(
		'/apps/openregister/api/objects/'
			+ typeConfig.register
			+ '/'
			+ typeConfig.schema
			+ (query ? '?' + query : ''),
	)
}

/**
 * @param {object} params Query parameters.
 * @return {string} The query string, without the empty values.
 */
function queryString(params) {
	const queryParams = new URLSearchParams()
	for (const [key, value] of Object.entries(params)) {
		if (value === undefined || value === null || value === '') continue
		queryParams.set(key, value)
	}
	return queryParams.toString()
}

/**
 * The objects of a type, or `[]` when the type is not registered in the
 * app's settings (a graceful no-op).
 *
 * @param {object} registry The object-type registry (register/schema per type).
 * @param {{type: string, params: object}} query The object type and the query parameters.
 * @return {Promise<Array>} The objects.
 */
export async function fetchRegisteredObjects(registry, query) {
	const typeConfig = registry[query.type]
	if (!typeConfig) return []

	const response = await fetch(objectsUrl(typeConfig, query.params), {
		headers: {
			'Content-Type': 'application/json',
			requesttoken: OC.requestToken,
			'OCS-APIREQUEST': 'true',
		},
	})

	if (!response.ok) throw new Error('Failed to fetch ' + query.type)
	const data = await response.json()
	return data.results || data || []
}
