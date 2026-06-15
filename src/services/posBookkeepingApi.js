// SPDX-License-Identifier: EUPL-1.2
// SPDX-FileCopyrightText: 2026 Conduction B.V.
//
// Frontend API client for the POS end-of-day journal-raise surface.
// The endpoint is documented in lib/Controller/PosBookkeepingController.php.
// The GL chart of accounts + the journal entry itself live in shillinq
// (cross-app contract #3); pipelinq only raises the journal through the
// ADR-019 integration registry and mirrors the outcome onto the Z-report.
//
// @spec openspec/changes/pipelinq-bookkeeping-to-shillinq/specs/pipelinq-bookkeeping-to-shillinq/spec.md#REQ-PBTS-001

import axios from '@nextcloud/axios'
import { generateUrl } from '@nextcloud/router'

const base = (path) => generateUrl('/apps/pipelinq' + path)

/**
 * Raise (or re-raise) the journal entry for a posZReport in shillinq.
 *
 * The server performs the manager role check, builds the business-fact
 * payload and dispatches the registry-mediated shillinq.JournalEntry.raise
 * with the deterministic idempotency key, then returns the persisted Z-report
 * with its updated bookkeepingStatus projection.
 *
 * @param {string} zReportId The Z-report UUID.
 * @return {Promise<object>} The persisted Z-report.
 */
export async function raiseJournalEntry(zReportId) {
	const { data } = await axios.post(base('/api/pos-bookkeeping/post'), {
		zReportId,
	})
	return data?.zReport ?? null
}
