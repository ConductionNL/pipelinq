// SPDX-License-Identifier: EUPL-1.2
// Copyright (C) 2026 Conduction B.V.

/**
 * Table cell formatters pipelinq adds to the library's `cnFormatters`
 * registry (CnAppRoot `formatters` prop). A manifest column picks one with
 * `"formatter": "<key>"`.
 *
 * @spec openspec/changes/review-finish/specs/commercial-dashboard/spec.md
 */

import { translate as t } from '@nextcloud/l10n'
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

/**
 * Print a stored enum value as its label: the schema property's
 * `x-enum-labels` entry, through pipelinq's translate. The library's `badge`
 * cell widget prints the formatted value and its plain formatter does not
 * read `x-enum-labels`, so without this a status column shows the stored code
 * ("active") while the detail page shows "Active". A value with no label
 * shows as stored.
 *
 * @param {unknown} value The stored value.
 * @param {object} [_row] The row (unused).
 * @param {object} [property] The schema property.
 * @return {string} The label.
 * @spec openspec/changes/round5-client-related-and-service-list/specs/appointment-booking/spec.md
 */
export function enumLabel(value, _row, property) {
	if (value === null || value === undefined || value === '') {
		return ''
	}
	const labels = property?.['x-enum-labels'] || property?.enumLabels || {}
	const raw = String(value)
	return Object.hasOwn(labels, raw) ? t('pipelinq', labels[raw]) : raw
}

/**
 * Print a boolean as "Yes" or "No", the words the detail pages use. The
 * library draws true as a check mark in the success colour, which on some
 * themes is too pale to see, and false as a dash.
 *
 * @param {unknown} value The stored value.
 * @return {string} "Yes", "No", or '' when unset.
 * @spec openspec/changes/round5-client-related-and-service-list/specs/appointment-booking/spec.md
 */
export function yesNo(value) {
	if (value === null || value === undefined || value === '') {
		return ''
	}
	return value === true || value === 'true' || value === 1
		? t('pipelinq', 'Yes')
		: t('pipelinq', 'No')
}

/** The registry handed to CnAppRoot. */
export const CELL_FORMATTERS = {
	objectCurrency,
	enumLabel,
	yesNo,
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
