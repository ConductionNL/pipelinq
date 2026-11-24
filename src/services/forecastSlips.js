/**
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * The deals that slipped out of a forecast period, for the forecast trend.
 *
 * A deal slips when its expected close date moves past the end of the
 * period that held its old close date; the server then stores where it
 * slipped from (`slipped_from`), when (`slipped_at`) and why (`slip_reason`)
 * on the lead (REQ-FRC-012). This module reads those leads and shapes the
 * rows the trend lists.
 *
 * @spec openspec/changes/forecast-slip-reason/specs/forecast-roll-up-and-categories/spec.md#requirement-a-deal-that-slips-out-of-its-forecast-period-needs-a-slip-reason-req-frc-012
 */

import axios from '@nextcloud/axios'
import { objectsUrl } from './objectFetch.js'

const LEAD = { register: 'pipelinq', schema: 'lead' }

/**
 * The first day of a quarter period and the first day after it.
 *
 * @param {string} periodId A period id such as Q2-2026.
 * @return {{start: string, end: string}|null} ISO dates, the end exclusive; null for an id it cannot read.
 * @spec openspec/changes/forecast-slip-reason/specs/forecast-roll-up-and-categories/spec.md#scenario-the-manager-reads-why-a-deal-slipped-req-frc-012-05
 */
export function periodBounds(periodId) {
	const match = /^Q([1-4])-(\d{4})$/.exec(String(periodId || ''))
	if (!match) {
		return null
	}
	const quarter = Number(match[1])
	const year = Number(match[2])
	const month = (quarter - 1) * 3 + 1
	const start = year + '-' + String(month).padStart(2, '0') + '-01'
	const end = quarter === 4 ? (year + 1) + '-01-01' : year + '-' + String(month + 3).padStart(2, '0') + '-01'
	return { start, end }
}

/**
 * The rows of the deals that slipped out of a period: each lead whose
 * `slipped_from` lies in the period and that carries a reason, latest slip first.
 *
 * @param {Array<object>} leads Leads as OpenRegister returns them.
 * @param {string} periodId A period id such as Q2-2026.
 * @return {Array<{id: string, title: string, slippedFrom: string, closeDate: string, reason: string, slippedAt: string}>} The rows.
 * @spec openspec/changes/forecast-slip-reason/specs/forecast-roll-up-and-categories/spec.md#scenario-the-manager-reads-why-a-deal-slipped-req-frc-012-05
 */
export function slippedDealRows(leads, periodId) {
	const bounds = periodBounds(periodId)
	if (bounds === null || !Array.isArray(leads)) {
		return []
	}
	const rows = []
	for (const lead of leads) {
		const from = String(lead?.slipped_from || '').slice(0, 10)
		const reason = String(lead?.slip_reason || '').trim()
		if (from === '' || reason === '' || from < bounds.start || from >= bounds.end) {
			continue
		}
		rows.push({
			id: String(lead.id || lead['@self']?.id || lead.uuid || ''),
			title: String(lead.title || ''),
			slippedFrom: from,
			closeDate: String(lead.expectedCloseDate || '').slice(0, 10),
			reason,
			slippedAt: String(lead.slipped_at || ''),
		})
	}
	return rows.sort((a, b) => (a.slippedAt < b.slippedAt ? 1 : (a.slippedAt > b.slippedAt ? -1 : 0)))
}

/**
 * The deals that slipped out of a period, as trend rows. A failed read
 * answers no rows.
 *
 * @param {object} params The query.
 * @param {string} params.periodId The period the deals slipped out of.
 * @param {?string} params.ownerId Only this seller's deals, or every seller's.
 * @return {Promise<Array<object>>} The rows, see slippedDealRows().
 * @spec openspec/changes/forecast-slip-reason/specs/forecast-roll-up-and-categories/spec.md#scenario-the-manager-reads-why-a-deal-slipped-req-frc-012-05
 */
export async function fetchSlippedDeals({ periodId, ownerId = null }) {
	const bounds = periodBounds(periodId)
	if (bounds === null) {
		return []
	}
	try {
		const response = await axios.get(objectsUrl(LEAD, {
			'slipped_from[gte]': bounds.start,
			'slipped_from[lt]': bounds.end,
			assignee: ownerId,
			_limit: 100,
		}))
		const data = response?.data
		const leads = Array.isArray(data) ? data : (data?.results || [])
		return slippedDealRows(leads, periodId)
	} catch {
		return []
	}
}
