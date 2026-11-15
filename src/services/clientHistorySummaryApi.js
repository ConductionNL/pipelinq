// SPDX-License-Identifier: EUPL-1.2
// SPDX-FileCopyrightText: 2026 Conduction B.V.
//
// The written history summary of a client (clients-history-summary-and-risk),
// served by lib/Controller/ClientHistorySummaryController.php. It is generated
// on request and never stored.
//
// @spec openspec/changes/clients-history-summary-and-risk/tasks.md#task-2.3

import axios from '@nextcloud/axios'
import { generateUrl } from '@nextcloud/router'

/**
 * @param {string} clientId The client.
 * @return {string} The endpoint.
 */
function endpoint(clientId) {
	return generateUrl('/apps/pipelinq/api/clients/{id}/history-summary', { id: clientId })
}

/**
 * Whether summarising is available (hermiq installed). Reads and generates nothing.
 *
 * @param {string} clientId The client.
 * @return {Promise<boolean>} Whether the Summarise button can be offered.
 * @spec openspec/changes/clients-history-summary-and-risk/tasks.md#task-2.3
 */
export async function fetchSummaryAvailable(clientId) {
	const { data } = await axios.get(endpoint(clientId), { params: { check: 1 } })
	return Boolean(data && data.available === true)
}

/**
 * Write the summary now.
 *
 * @param {string} clientId The client.
 * @return {Promise<{available: boolean, summary: string|null, recordsRead: number, generatedAt: string|null}>}
 * @spec openspec/changes/clients-history-summary-and-risk/tasks.md#task-2.3
 */
export async function fetchSummary(clientId) {
	const { data } = await axios.get(endpoint(clientId))
	const body = data && typeof data === 'object' ? data : {}
	return {
		available: body.available === true,
		summary: typeof body.summary === 'string' && body.summary !== '' ? body.summary : null,
		recordsRead: Number.isFinite(body.recordsRead) ? body.recordsRead : 0,
		generatedAt: typeof body.generatedAt === 'string' ? body.generatedAt : null,
	}
}
