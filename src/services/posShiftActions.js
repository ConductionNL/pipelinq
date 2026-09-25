// SPDX-License-Identifier: EUPL-1.2
// Copyright (C) 2026 Conduction B.V.

import { showError, showSuccess } from '@nextcloud/dialogs'
import { emit } from '@nextcloud/event-bus'
import { generateUrl } from '@nextcloud/router'

/**
 * POST to a cash-shift endpoint under /api/pos-shifts/{id}, toast the outcome
 * and, on success, bump `cn:page:refresh` so the CashShiftDetail widgets
 * re-read the shift, its drops and its variance.
 *
 * Shared by the page's header actions (drop / count) and its variance widget
 * (diff approve / reject).
 *
 * @param {string} shiftId The shift UUID.
 * @param {string} path The path under /api/pos-shifts/{id} (e.g. 'drop').
 * @param {object} body The request body.
 * @param {string} successMessage The success toast.
 * @return {Promise<boolean>} Whether the action succeeded.
 * @spec exclude pos-lifecycle-guard-adoption names posTransaction and posRefund
 *   transitions only -- the cash-shift lifecycle has no owning requirement in
 *   any spec
 */
export async function postShiftAction(shiftId, path, body, successMessage) {
	if (!shiftId) {
		return false
	}
	try {
		const response = await fetch(
			generateUrl(`/apps/pipelinq/api/pos-shifts/${shiftId}/${path}`),
			{
				method: 'POST',
				headers: {
					'Content-Type': 'application/json',
					requesttoken: OC.requestToken,
					'OCS-APIREQUEST': 'true',
				},
				body: JSON.stringify(body || {}),
			},
		)
		const data = await response.json().catch(() => ({}))
		if (!response.ok) {
			showError(data.error || t('pipelinq', 'Action failed.'))
			return false
		}
		showSuccess(successMessage)
		emit('cn:page:refresh', {})
		return true
	} catch {
		showError(t('pipelinq', 'Action failed.'))
		return false
	}
}
