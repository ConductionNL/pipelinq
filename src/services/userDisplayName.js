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

import { getCurrentUser } from '@nextcloud/auth'
import axios from '@nextcloud/axios'
import { generateOcsUrl } from '@nextcloud/router'
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
