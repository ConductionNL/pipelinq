/**
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * How the booking pages write a moment, an amount and a booking status.
 *
 * @spec openspec/specs/appointment-booking/spec.md
 */

import { currencyOr } from '../../services/reportingCurrency.js'

/**
 * The booking statuses, as the English source text the pages translate.
 */
export const BOOKING_STATUS_LABELS = {
	'pending-deposit': 'Awaiting deposit',
	confirmed: 'Confirmed',
	completed: 'Completed',
	'no-show': 'No-show',
	'cancelled-by-customer': 'Cancelled (customer)',
	'cancelled-by-business': 'Cancelled (business)',
	rescheduled: 'Rescheduled',
}

/**
 * @param {?string} iso An ISO moment.
 * @return {string} The moment in Dutch; '-' without one, the value itself when it cannot be written.
 */
export function bookingDateTime(iso) {
	if (!iso) return '-'
	try {
		return new Date(iso).toLocaleString('nl-NL')
	} catch {
		return iso
	}
}

/**
 * An amount in the given currency, or the reporting currency.
 *
 * @param {number|string} value The amount.
 * @param {string} [currency] The currency code.
 * @return {string} The formatted amount.
 * @spec openspec/specs/commercial-dashboard/spec.md
 */
export function bookingCurrency(value, currency) {
	const code = currencyOr(currency)
	const n = Number(value) || 0
	try {
		return new Intl.NumberFormat('nl-NL', {
			style: 'currency',
			currency: code,
			maximumFractionDigits: 2,
		}).format(n)
	} catch {
		return `${code} ${n}`
	}
}
