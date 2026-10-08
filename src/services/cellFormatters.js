// SPDX-License-Identifier: EUPL-1.2
// Copyright (C) 2026 Conduction B.V.

/**
 * Table cell formatters pipelinq adds to the library's `cnFormatters`
 * registry (CnAppRoot `formatters` prop). A manifest column picks one with
 * `"formatter": "<key>"`.
 *
 * @spec openspec/changes/review-finish/specs/commercial-dashboard/spec.md
 */

import { createNameFormatter } from './nameFormatters.js'
import { currencyOr } from './reportingCurrency.js'
import { createUserDisplayNameFormatter } from './userDisplayName.js'

/**
 * Format an amount in the row's own currency (`row.currency`), or in the
 * reporting currency when the row has none. `formatterOptions.decimals` sets
 * the decimals (default 2). Never throws: empty stays empty, a non-number
 * shows as typed.
 *
 * @param {unknown} value The amount.
 * @param {object} [row] The row; its `currency` wins when valid.
 * @param {object} [_property] The schema property (unused).
 * @param {{decimals?: number, currencyField?: string}} [options] Column options.
 * @return {string} The formatted amount.
 * @spec openspec/changes/review-finish/specs/commercial-dashboard/spec.md
 */
export function objectCurrency(value, row, _property, options) {
	if (value === null || value === undefined || value === '') {
		return ''
	}
	const num = Number(value)
	if (!Number.isFinite(num)) {
		return String(value)
	}
	const opts = options || {}
	const decimals = Number.isFinite(opts.decimals) ? opts.decimals : 2
	const field = opts.currencyField || 'currency'
	return new Intl.NumberFormat(undefined, {
		style: 'currency',
		currency: currencyOr(row?.[field]),
		minimumFractionDigits: decimals,
		maximumFractionDigits: decimals,
	}).format(num)
}

/** The registry handed to CnAppRoot. */
export const CELL_FORMATTERS = {
	objectCurrency,
}

/**
 * The full formatter map App.vue hands to CnAppRoot: the static formatters
 * plus the booking name formatters, which look objects up through the store.
 * Keys never reuse a library built-in name, so no built-in is shadowed.
 *
 * @param {{fetchObject: (type: string, id: string) => Promise<object|null>}} store The object store.
 * @return {Record<string, (value: unknown) => string>} The formatter map.
 * @spec openspec/changes/review-finish/specs/appointment-booking/spec.md
 */
export function createAppFormatters(store) {
	return {
		...CELL_FORMATTERS,
		// The booking data block names the customer (a contact, or a client)
		// and the service instead of showing their ids.
		bookingCustomerName: createNameFormatter([
			(id) => store.fetchObject('contact', id),
			(id) => store.fetchObject('client', id),
		]),
		bookingServiceName: createNameFormatter([
			(id) => store.fetchObject('appointmentService', id),
		]),
		// A user field shows the user's display name, not the uid
		// (round3-review-points).
		userDisplayName: createUserDisplayNameFormatter(),
	}
}
