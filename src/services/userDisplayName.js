// SPDX-License-Identifier: EUPL-1.2
// Copyright (C) 2026 Conduction B.V.

/**
 * A formatter that shows a Nextcloud user's display name instead of the uid.
 *
 * A user field (`format: user`) stores the uid. The picker shows the display
 * name, but a list column or a data widget showed the stored uid (`cluade`
 * instead of "claude"). A manifest column or data widget field picks this one
 * with `"formatter": "userDisplayName"`.
 *
 * The name comes from the signed-in user when the uid is theirs, and else
 * from the core autocomplete endpoint, which every signed-in user may call
 * (the provisioning API is admin-only). A uid nobody answers to stays as it is.
 *
 * @spec openspec/changes/round3-review-points/specs/user-fields/spec.md
 */

import { reactive } from 'vue'

/** Shown while a name is still being looked up; never the raw uid. */
export const PENDING_LABEL = '…'

/**
 * Look a user's display name up through core autocomplete.
 *
 * @param {string} uid The uid.
 * @return {Promise<string>} The display name, or the uid when no user matches it exactly.
 * @spec openspec/changes/round3-review-points/specs/user-fields/spec.md
 */
export async function fetchUserDisplayName(uid) {
	// Loaded on first use: @nextcloud/auth reads browser storage when it is
	// imported, which breaks every node-environment spec that imports the
	// cell formatters.
	const [{ getCurrentUser }, { default: axios }, { generateOcsUrl }] =
		await Promise.all([
			import('@nextcloud/auth'),
			import('@nextcloud/axios'),
			import('@nextcloud/router'),
		])
	let me
	try {
		me = getCurrentUser()
	} catch {
		me = null
	}
	if (me && String(me.uid) === uid) {
		return me.displayName || uid
	}
	try {
		const response = await axios.get(generateOcsUrl('core/autocomplete/get'), {
			headers: { 'OCS-APIRequest': 'true', Accept: 'application/json' },
			params: {
				search: uid,
				itemType: ' ',
				itemId: ' ',
				'shareTypes[]': 0,
				limit: 10,
			},
		})
		const list = response?.data?.ocs?.data
		const match = (Array.isArray(list) ? list : []).find(
			(entry) => entry && String(entry.id) === uid,
		)
		return (match && match.label) || uid
	} catch {
		return uid
	}
}

/**
 * Build the formatter. Each uid is looked up once; the reactive cache makes
 * the cell or data widget render again when the name arrives.
 *
 * @param {(uid: string) => Promise<string>} [lookup] The lookup (injectable for tests).
 * @return {(value: unknown) => string} The formatter.
 * @spec openspec/changes/round3-review-points/specs/user-fields/spec.md
 */
export function createUserDisplayNameFormatter(lookup = fetchUserDisplayName) {
	const names = reactive({})
	const started = new Set()

	return (value) => {
		if (value === null || value === undefined || value === '') {
			return ''
		}
		if (Array.isArray(value)) {
			return value
				.map((uid) => resolveOne(String(uid)))
				.filter(Boolean)
				.join(', ')
		}
		return resolveOne(String(value))
	}

	/**
	 * The name for one uid, starting its lookup when needed.
	 *
	 * @param {string} uid The uid.
	 * @return {string} The name, or the pending label.
	 */
	function resolveOne(uid) {
		if (uid in names) {
			return names[uid]
		}
		if (!started.has(uid)) {
			started.add(uid)
			Promise.resolve(lookup(uid))
				.then((label) => {
					names[uid] = label || uid
				})
				.catch(() => {
					names[uid] = uid
				})
		}
		return PENDING_LABEL
	}
}

let assignableUsers = null

/**
 * The uids a record can be assigned to, from core autocomplete. One request
 * serves every caller; a failed request answers an empty list and is retried
 * on the next call.
 *
 * @param {number} [limit] How many users to ask for.
 * @return {Promise<Array<string>>} The uids.
 * @spec openspec/specs/lead-management/spec.md
 */
export function listAssignableUserIds(limit = 200) {
	if (assignableUsers === null) {
		assignableUsers = Promise.all([
			import('@nextcloud/axios'),
			import('@nextcloud/router'),
		])
			.then(([{ default: axios }, { generateOcsUrl }]) =>
				axios.get(generateOcsUrl('core/autocomplete/get'), {
					headers: { 'OCS-APIRequest': 'true', Accept: 'application/json' },
					params: {
						search: '',
						itemType: ' ',
						itemId: ' ',
						'shareTypes[]': 0,
						limit,
					},
				}),
			)
			.then((response) => {
				const list = response?.data?.ocs?.data
				return (Array.isArray(list) ? list : [])
					.map((entry) => (entry && entry.id ? String(entry.id) : ''))
					.filter(Boolean)
			})
			.catch(() => {
				assignableUsers = null
				return []
			})
	}
	return assignableUsers
}

/**
 * Forget the assignable users, so the next call asks the server again.
 *
 * @spec openspec/specs/lead-management/spec.md
 * @return {void}
 */
export function resetAssignableUsers() {
	assignableUsers = null
}

const displayNames = new Map()

/**
 * A user's display name, asked once per uid for the page's lifetime.
 *
 * @param {string} uid The uid.
 * @return {Promise<string>} The display name, or the uid.
 * @spec openspec/specs/pipeline/spec.md
 */
export function cachedUserDisplayName(uid) {
	const key = String(uid || '')
	if (key === '') {
		return Promise.resolve('')
	}
	if (!displayNames.has(key)) {
		displayNames.set(key, fetchUserDisplayName(key).catch(() => key))
	}
	return displayNames.get(key)
}

/**
 * The initials of a name: the first letters of its first and last word
 * ("Pieter Jansen" PJ), or the first letter of a single word.
 *
 * @param {string} name The name.
 * @return {string} One or two capitals, or ''.
 * @spec openspec/specs/pipeline/spec.md
 */
export function initialsOf(name) {
	const words = String(name || '').trim().split(/[\s._-]+/).filter(Boolean)
	if (words.length === 0) {
		return ''
	}
	const first = words[0].charAt(0)
	const last = words.length > 1 ? words[words.length - 1].charAt(0) : ''
	return (first + last).toUpperCase()
}
