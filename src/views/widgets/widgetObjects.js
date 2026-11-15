/**
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * The OpenRegister requests the dashboard's create widgets (create lead,
 * start request) make on their own: a list read and an object post for a
 * registered object type, and the id of a picked option.
 */

import { generateUrl } from '@nextcloud/router'

/**
 * @return {object} The headers of a widget's OpenRegister request.
 */
function headers() {
	return {
		'Content-Type': 'application/json',
		requesttoken: OC.requestToken,
		'OCS-APIREQUEST': 'true',
	}
}

/**
 * @param {{register: string, schema: string}} typeConfig The object type's register and schema.
 * @param {string} query The query string, with its `?`, or ''.
 * @return {string} The objects URL.
 */
export function widgetObjectsUrl(typeConfig, query) {
	return generateUrl(
		'/apps/openregister/api/objects/'
			+ typeConfig.register
			+ '/'
			+ typeConfig.schema
			+ query,
	)
}

/**
 * Read a list of objects.
 *
 * @param {string} url The objects URL.
 * @return {Promise<(Array|null)>} The objects, or null when the server refused.
 */
export async function readWidgetObjects(url) {
	const response = await fetch(url, {
		headers: headers(),
	})

	if (!response.ok) return null
	const data = await response.json()
	return data.results || data || []
}

/**
 * Post a new object; a refused post throws the failure.
 *
 * @param {{register: string, schema: string}} typeConfig The object type's register and schema.
 * @param {{body: object, failure: string}} creation The object and what a refusal says.
 * @return {Promise<string>} The created object's id.
 */
export async function createWidgetObject(typeConfig, creation) {
	const response = await fetch(widgetObjectsUrl(typeConfig, ''), {
		method: 'POST',
		headers: headers(),
		body: JSON.stringify(creation.body),
	})

	if (!response.ok) throw new Error(creation.failure)
	const created = await response.json()
	return created.id || created.uuid
}

/**
 * @param {(object|string)} value A picked option, or the value itself.
 * @return {string} The value the option stands for.
 */
export function optionValue(value) {
	return typeof value === 'object'
		? value.id
		: value
}
