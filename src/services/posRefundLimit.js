// SPDX-License-Identifier: EUPL-1.2
// Copyright (C) 2026 Conduction B.V.

/**
 * The refund limit below which a till user completes a refund without a
 * manager (pos-refund-limit, REQ-PLG-007), as the refund screen reads it.
 *
 * The server's PosRefundManagerGuard decides; this only lets the screen say in
 * advance who will confirm the refund. Both read the same setting the same way:
 * empty, not a number or negative means every refund needs a manager.
 *
 * @spec openspec/changes/pos-refund-limit/specs/pos-lifecycle-guard-adoption/spec.md#requirement-a-refund-up-to-the-configured-limit-shall-need-no-manager-req-plg-007
 */

/**
 * The app-config key of the refund limit.
 *
 * @type {string}
 */
export const REFUND_LIMIT_KEY = 'pos.refund_limit'

/**
 * The refund limit in euros from the settings, or null when none is set.
 *
 * @param {object|null} config The settings' config map.
 * @return {number|null} The limit, rounded to cents.
 */
export function refundLimitFrom(config) {
	const raw = String((config && config[REFUND_LIMIT_KEY]) ?? '').trim().replace(',', '.')
	if (raw === '' || !/^\d+(\.\d+)?$/.test(raw)) {
		return null
	}
	return Math.round(Number(raw) * 100) / 100
}

/**
 * Who confirms a refund of this total.
 *
 * @param {number} total The refund's gross total.
 * @param {number|null} limit The refund limit, or null.
 * @return {'manager'|'tillUser'|'aboveLimit'} `manager` with no limit, `tillUser`
 *     at or below the limit, `aboveLimit` above it.
 */
export function refundApprover(total, limit) {
	if (limit === null || limit === undefined) {
		return 'manager'
	}
	return Math.round(Number(total || 0) * 100) / 100 <= limit ? 'tillUser' : 'aboveLimit'
}
