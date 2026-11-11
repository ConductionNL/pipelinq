// SPDX-License-Identifier: EUPL-1.2
// Copyright (C) 2026 Conduction B.V.

import { showError, showSuccess } from '@nextcloud/dialogs'

/**
 * POST an action to a pipelinq endpoint and toast the outcome: the server's
 * error (or "Action failed.") on a refusal or a failure, the success message
 * otherwise, after which `then` runs with the answer. A failure in `then` is
 * an action failure too.
 *
 * Shared by the booking header actions and the cash-shift actions.
 *
 * @param {string} url The endpoint.
 * @param {{body: ?object, successMessage: string, then: (data: object) => void}} action The body, the success toast, and what follows a success.
 * @return {Promise<boolean>} Whether the action succeeded.
 */
export async function postAction(url, action) {
	try {
		const response = await fetch(url, {
			method: 'POST',
			headers: {
				'Content-Type': 'application/json',
				requesttoken: OC.requestToken,
				'OCS-APIREQUEST': 'true',
			},
			body: JSON.stringify(action.body || {}),
		})
		const data = await response.json().catch(() => ({}))
		if (!response.ok) {
			showError(data.error || t('pipelinq', 'Action failed.'))
			return false
		}
		showSuccess(action.successMessage)
		action.then(data)
		return true
	} catch {
		showError(t('pipelinq', 'Action failed.'))
		return false
	}
}
