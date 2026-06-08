// SPDX-License-Identifier: EUPL-1.2
// SPDX-FileCopyrightText: 2026 Conduction B.V.
//
// Frontend API client for the POS end-of-day bookkeeping surface.
// All endpoints documented in lib/Controller/PosBookkeepingController.php +
// lib/Controller/PosBookkeepingConfigController.php.
//
// @spec openspec/changes/pos-end-of-day-bookkeeping-post/tasks.md#5

import axios from '@nextcloud/axios'
import { generateUrl } from '@nextcloud/router'

const base = (path) => generateUrl('/apps/pipelinq' + path)

/**
 * Submit (or resubmit) a posJournalEntryOutbound to Shillinq.
 *
 * @param {string} outboundMessageId The outbound message UUID.
 * @return {Promise<object>} The persisted outbound message envelope.
 */
export async function postJournalEntry(outboundMessageId) {
	const { data } = await axios.post(base('/api/pos-bookkeeping/post'), {
		outboundMessageId,
	})
	return data?.outbound ?? null
}

/**
 * Read the current POS bookkeeping admin settings (token redacted).
 *
 * @return {Promise<object>} The settings (zReportTime, shillinqEndpoint, alertEmail, maxRetryAttempts, tokenConfigured).
 */
export async function getBookkeepingConfig() {
	const { data } = await axios.get(base('/api/admin/pos-bookkeeping/config'))
	return data?.settings ?? {}
}

/**
 * Update the POS bookkeeping admin settings.
 *
 * Any subset of (zReportTime, shillinqEndpoint, shillinqToken, alertEmail,
 * maxRetryAttempts) may be supplied; omitted keys are left unchanged. The
 * shillinqToken is persisted with the sensitive flag and never returned by
 * the GET endpoint.
 *
 * @param {object} payload The settings to update.
 * @return {Promise<object>} The persisted settings (token redacted).
 */
export async function updateBookkeepingConfig(payload) {
	const { data } = await axios.post(base('/api/admin/pos-bookkeeping/config'), payload)
	return data?.settings ?? {}
}
