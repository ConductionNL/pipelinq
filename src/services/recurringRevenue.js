// SPDX-License-Identifier: EUPL-1.2
// Copyright (C) 2026 Conduction B.V.
//
// Client-side recurring-revenue helpers — mirrors RecurringRevenueService.php
// (contract-renewal-tracking).

/**
 * Statuses that contribute to live recurring revenue.
 *
 * @type {string[]}
 */
const REVENUE_STATUSES = ['active', 'expiring']

/**
 * Per recurring billing interval, how a value per interval becomes a value
 * per month. A one-off or unknown interval adds nothing.
 */
const TO_MONTHLY = {
	monthly: (value) => value,
	quarterly: (value) => value / 3,
	annual: (value) => value / 12,
}

/**
 * Normalize a single contract's interval value to a monthly figure.
 *
 * @param {string} billingInterval  - The billing interval ('monthly', 'quarterly', 'annual', 'one-off').
 * @param {number} valuePerInterval - The value per interval.
 * @return {number} The normalized monthly recurring revenue (0 for one-off).
 */
export function normalizeToMonthly(billingInterval, valuePerInterval) {
	const toMonthly = typeof billingInterval === 'string' && Object.hasOwn(TO_MONTHLY, billingInterval)
		? TO_MONTHLY[billingInterval]
		: null
	return toMonthly ? toMonthly(valuePerInterval) : 0
}

/**
 * Compute MRR (sum of normalized monthly values of revenue-status contracts).
 *
 * @param {Array<object>|null|undefined} contracts - The contract objects.
 * @return {number} The monthly recurring revenue.
 */
export function computeMrr(contracts) {
	if (!contracts || !Array.isArray(contracts)) {
		return 0
	}
	const mrr = contracts
		.filter((c) => REVENUE_STATUSES.includes(c.status ?? ''))
		.reduce(
			(sum, c) =>
				sum
				+ normalizeToMonthly(
					c.billingInterval ?? '',
					c.valuePerInterval ?? 0,
				),
			0,
		)
	return Math.round(mrr * 100) / 100
}

/**
 * Compute ARR (MRR × 12).
 *
 * @param {Array<object>|null|undefined} contracts - The contract objects.
 * @return {number} The annual recurring revenue.
 */
export function computeArr(contracts) {
	return Math.round(computeMrr(contracts) * 12 * 100) / 100
}

/**
 * Compute the per-client recurring value (MRR) for a given client.
 *
 * @param {Array<object>} contracts - All contracts.
 * @param {string}        clientRef - The client reference / UUID.
 * @return {number} The client's monthly recurring value.
 */
export function computeClientMrr(contracts, clientRef) {
	const clientContracts = (contracts ?? []).filter(
		(c) => (c.clientRef ?? '') === clientRef,
	)
	return computeMrr(clientContracts)
}
