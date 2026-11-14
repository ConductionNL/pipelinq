// SPDX-License-Identifier: EUPL-1.2
// Copyright (C) 2026 Conduction B.V.

import { emit } from '@nextcloud/event-bus'
import { generateUrl } from '@nextcloud/router'
import { postAction } from './postedAction.js'

/**
 * POST to a cash-shift endpoint under /api/pos-shifts/{id}, toast the outcome
 * and, on success, bump `cn:page:refresh` so the CashShiftDetail widgets
 * re-read the shift, its drops and its variance.
 *
 * Shared by the page's header actions (drop / count) and its variance widget
 * (diff approve / reject).
 *
 * @param {string} shiftId The shift UUID.
 * @param {{path: string, body: ?object, successMessage: string}} action The path under /api/pos-shifts/{id} (e.g. 'drop'), the request body and the success toast.
 * @return {Promise<boolean>} Whether the action succeeded.
 * @spec exclude pos-lifecycle-guard-adoption names posTransaction and posRefund
 *   transitions only -- the cash-shift lifecycle has no owning requirement in
 *   any spec
 */
export async function postShiftAction(shiftId, action) {
	if (!shiftId) {
		return false
	}
	return postAction(generateUrl(`/apps/pipelinq/api/pos-shifts/${shiftId}/${action.path}`), {
		body: action.body,
		successMessage: action.successMessage,
		then: () => emit('cn:page:refresh', {}),
	})
}
