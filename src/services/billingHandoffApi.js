// SPDX-License-Identifier: EUPL-1.2
// SPDX-FileCopyrightText: 2026 Conduction B.V.
//
// Frontend API client for the manager-facing "Send to billing" action
// (time-billing-handoff-emit). The endpoints are documented in
// lib/Controller/BillingHandoffController.php — an availability check (so the
// UI can hide the button and fall back to the existing Shillinq deep-link)
// and the trigger itself, which posts a client's approved, un-billed time
// entries for a period to shillinq's time-intake as one idempotent batch.
//
// @spec openspec/changes/time-billing-handoff-emit/specs/time-approval-workflow/spec.md

import axios from '@nextcloud/axios'
import { generateUrl } from '@nextcloud/router'

const base = (path) => generateUrl('/apps/pipelinq' + path)

/**
 * Fetch whether "Send to billing" is available for the acting user.
 *
 * @param {string} clientId The client UUID.
 * @return {Promise<{available: boolean, deepLinkUrl: string, isManager: boolean}>} Availability.
 */
export async function getBillingHandoffAvailability(clientId) {
	const { data } = await axios.get(base(`/api/billing/handoff/${clientId}/availability`))
	return {
		available: !!data.available,
		deepLinkUrl: data.deepLinkUrl || '',
		isManager: !!data.isManager,
	}
}

/**
 * Trigger "Send to billing" for a client's approved, un-billed entries in a period.
 *
 * @param {string} clientId The client UUID.
 * @param {string} periodStart The period start date (ISO 8601, inclusive).
 * @param {string} periodEnd The period end date (ISO 8601, inclusive).
 * @return {Promise<object>} The handoff outcome ({status, invoiceId, invoiceNumber, duplicated, entryCount, message, ...}).
 */
export async function sendToBilling(clientId, periodStart, periodEnd) {
	const { data } = await axios.post(base(`/api/billing/handoff/${clientId}`), {
		periodStart,
		periodEnd,
	})
	return data
}
