// SPDX-License-Identifier: EUPL-1.2
// Copyright (C) 2026 Conduction B.V.
//
// Client-side recurring-revenue helpers (contract-renewal-tracking).
// Mirrors lib/Service/RecurringRevenueService.php normalization so dashboard
// widgets and the client Contracts tab compute MRR/ARR consistently.
//
// @spec openspec/changes/contract-renewal-tracking/specs/contract-renewal-tracking/spec.md#requirement-recurring-revenue-roll-up

const REVENUE_STATUSES = ['active', 'expiring']

/**
 * Normalize one contract's interval value to a monthly figure.
 *
 * @param {string} billingInterval - monthly | quarterly | annual | one-off.
 * @param {number} valuePerInterval - The value per billing interval.
 * @return {number} The normalized monthly value (0 for one-off).
 */
export function normalizeToMonthly(billingInterval, valuePerInterval) {
	const value = Number(valuePerInterval) || 0
	switch (billingInterval) {
	case 'monthly': return value
	case 'quarterly': return value / 3
	case 'annual': return value / 12
	default: return 0
	}
}

/**
 * Compute MRR over a contract set (active + expiring only, one-off excluded).
 *
 * @param {Array<object>} contracts - The contract records.
 * @return {number} The monthly recurring revenue, rounded to cents.
 */
export function computeMrr(contracts) {
	let mrr = 0
	for (const c of contracts || []) {
		if (!REVENUE_STATUSES.includes(c.status)) continue
		mrr += normalizeToMonthly(c.billingInterval, c.valuePerInterval)
	}
	return Math.round(mrr * 100) / 100
}

/**
 * Compute ARR (MRR × 12).
 *
 * @param {Array<object>} contracts - The contract records.
 * @return {number} The annual recurring revenue.
 */
export function computeArr(contracts) {
	return Math.round(computeMrr(contracts) * 12 * 100) / 100
}

/**
 * Per-client recurring value (MRR) for a given client UUID.
 *
 * @param {Array<object>} contracts - The contract records.
 * @param {string} clientRef - The client UUID.
 * @return {number} The client's monthly recurring value.
 */
export function computeClientMrr(contracts, clientRef) {
	return computeMrr((contracts || []).filter(c => c.clientRef === clientRef))
}
