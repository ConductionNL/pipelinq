import { generateUrl } from '@nextcloud/router'

/**
 * The requests the settings tag lists (lead sources, request channels)
 * share: the list at `/apps/pipelinq/api/settings/<path>`, and an add,
 * rename or remove of one tag, each of which must answer `success`.
 */

/**
 * @param {string} path The settings list, such as `lead-sources`.
 * @return {string} The list URL.
 */
export function settingsTagsUrl(path) {
	return generateUrl('/apps/pipelinq/api/settings/' + path)
}

/**
 * @return {object} The headers of a settings list request.
 */
function headers() {
	return {
		'Content-Type': 'application/json',
		requesttoken: OC.requestToken,
		'OCS-APIREQUEST': 'true',
	}
}

/**
 * Load a settings list into a store: its `tags`, with `loading` on, and a
 * failure kept in `error` and logged.
 *
 * @param {object} store The store.
 * @param {{url: string, what: string}} list The list URL and what it holds, such as "lead sources".
 * @return {Promise<void>}
 */
export async function loadSettingsTags(store, list) {
	store.loading = true
	store.error = null

	try {
		const response = await fetch(list.url, { headers: headers() })
		if (!response.ok) {
			throw new Error(
				`Failed to fetch ${list.what} (${response.status})`,
			)
		}
		const data = await response.json()
		store.tags = data.tags || []
	} catch (error) {
		store.error = error.message
		console.error(`Error fetching ${list.what}:`, error)
	} finally {
		store.loading = false
	}
}

/**
 * Send one change to a settings list. A refused request or an answer
 * without `success` throws, with the failure (and the status) as message.
 *
 * @param {string} url The list or tag URL.
 * @param {{method: string, body: (object|undefined), failure: string}} change The method, the body and what a failure says.
 * @return {Promise<object>} The answer.
 */
export async function sendSettingsTagChange(url, change) {
	const init = { method: change.method, headers: headers() }
	if (change.body !== undefined) {
		init.body = JSON.stringify(change.body)
	}
	const response = await fetch(url, init)
	if (!response.ok) {
		throw new Error(`${change.failure} (${response.status})`)
	}
	const data = await response.json()

	if (!data.success) {
		throw new Error(data.message || change.failure)
	}
	return data
}

/**
 * Run a change, keeping the message of a failure in the store's `error`
 * before passing the failure on.
 *
 * @param {object} store The store.
 * @param {() => Promise<(object|undefined)>} change The change.
 * @return {Promise<(object|undefined)>} What the change answers.
 */
export async function keepingError(store, change) {
	try {
		return await change()
	} catch (error) {
		store.error = error.message
		throw error
	}
}

/**
 * Put a renamed tag in place of the old one, when the list has it.
 *
 * @param {object} store The store.
 * @param {{id: string, tag: object}} renamed The id that was renamed, and the tag the server answered.
 * @return {void}
 */
export function replaceTag(store, renamed) {
	const index = store.tags.findIndex((t) => t.id === renamed.id)
	if (index !== -1) {
		store.tags[index] = renamed.tag
	}
}
