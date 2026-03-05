/**
 * Service for interacting with OpenRegister View API.
 *
 * Provides methods to fetch views and schema properties
 * for the pipeline view-backed board system.
 */

const API_BASE = '/apps/openregister/api'

/**
 * Build standard request headers for Nextcloud API calls.
 *
 * @return {object} The headers object.
 */
function headers() {
	return {
		'Content-Type': 'application/json',
		requesttoken: OC.requestToken,
		'OCS-APIREQUEST': 'true',
	}
}

/**
 * Fetch all available views from OpenRegister.
 *
 * @return {Promise<Array>} The list of views.
 */
export async function getViews() {
	const response = await fetch(`${API_BASE}/views`, {
		method: 'GET',
		headers: headers(),
	})
	if (!response.ok) {
		throw new Error(`Failed to fetch views: ${response.status}`)
	}
	const data = await response.json()
	return data.results ?? data
}

/**
 * Fetch a single view by ID.
 *
 * @param {string} id The view UUID.
 * @return {Promise<object>} The view object.
 */
export async function getView(id) {
	const response = await fetch(`${API_BASE}/views/${id}`, {
		method: 'GET',
		headers: headers(),
	})
	if (!response.ok) {
		throw new Error(`Failed to fetch view ${id}: ${response.status}`)
	}
	return await response.json()
}

/**
 * Fetch property definitions for a schema by its ID.
 *
 * @param {string} schemaId The schema UUID.
 * @return {Promise<object>} The schema object including properties.
 */
export async function getSchemaProperties(schemaId) {
	const response = await fetch(`${API_BASE}/schemas/${schemaId}`, {
		method: 'GET',
		headers: headers(),
	})
	if (!response.ok) {
		throw new Error(`Failed to fetch schema ${schemaId}: ${response.status}`)
	}
	return await response.json()
}
