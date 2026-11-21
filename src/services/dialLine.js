// SPDX-License-Identifier: EUPL-1.2
// SPDX-FileCopyrightText: 2026 Conduction B.V.

import axios from '@nextcloud/axios'
import { generateUrl } from '@nextcloud/router'

/**
 * The signed-in agent's dial line, read once per page load: every phone
 * number on a page asks, and the answer does not change while it is open.
 *
 * @type {Promise<{enabled: boolean, extension: string, busy: boolean}>|null}
 */
let pending = null

const OFF = Object.freeze({ enabled: false, extension: '', busy: false })

/**
 * Whether the agent can place a call through the telephony platform, from
 * which extension, and whether that extension is on a call. A failed read,
 * a refusal or an unexpected answer is a line that is off.
 *
 * @return {Promise<{enabled: boolean, extension: string, busy: boolean}>}
 * @spec openspec/changes/specify-capability-rows/tasks.md#task-36.1
 */
export function loadDialLine() {
	if (pending === null) {
		pending = axios.get(generateUrl('/apps/pipelinq/api/cti/dial-line'))
			.then(({ data }) => {
				const extension = typeof data?.extension === 'string' ? data.extension : ''
				if (data?.enabled !== true || extension === '') {
					return OFF
				}
				return { enabled: true, extension, busy: data.busy === true }
			})
			.catch(() => OFF)
	}
	return pending
}

/**
 * Forget the read line, so the next ask reads it again.
 *
 * @spec openspec/changes/specify-capability-rows/tasks.md#task-36.1
 */
export function forgetDialLine() {
	pending = null
}
