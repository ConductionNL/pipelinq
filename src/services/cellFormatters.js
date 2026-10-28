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
import { timeRange } from '../utils/bookingTime.js'
import { exportMoment, exportSchedule } from '../views/export/exportRunText.js'
import { createNameFormatter } from './nameFormatters.js'
import { currencyOr } from './reportingCurrency.js'
import { createUserDisplayNameFormatter } from './userDisplayName.js'
import { MARKETING_FORMATTERS } from './valueFormatters.js'

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
	// The reader's own locale (nl-NL reads "€ 120.000"); the browser's when
	// Nextcloud has none to give.
	const locale =
		opts.locale
		|| (typeof OC !== 'undefined' && OC.getLocale
			? OC.getLocale().replace('_', '-')
			: undefined)
	return new Intl.NumberFormat(locale, {
		style: 'currency',
		currency: currencyOr(row?.[field]),
		minimumFractionDigits: decimals,
		maximumFractionDigits: decimals,
	}).format(num)
}

/**
 * An enum value as plain, translated text (no pill), as the boards draw a
 * priority: `high` reads "Hoog".
 *
 * @param {unknown} value The stored value.
 * @return {string} The translated word, or '' for an empty value.
 * @spec openspec/changes/round6-board-look/specs/board-look/spec.md
 */
export function enumText(value) {
	if (value === null || value === undefined || value === '') {
		return ''
	}
	const word = String(value)
	return t('pipelinq', word.charAt(0).toUpperCase() + word.slice(1))
}

/**
 * A stored code as a translated word inside a sentence: `phone` reads
 * "telefoon" in "Via telefoon". As enumText, with the first letter in
 * lower case.
 *
 * @param {unknown} value The stored value.
 * @return {string} The word, or '' for an empty value.
 * @spec openspec/specs/dashboard/spec.md
 */
export function enumWord(value) {
	const word = enumText(value)
	return word === '' ? '' : word.charAt(0).toLowerCase() + word.slice(1)
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

/**
 * Print an ISO 8601 duration ("PT6M", "PT1H5M", "PT45S") the way the lists
 * read it: "6 min", "1 h 5 min", "45 s". A value that is not an ISO duration
 * shows as stored.
 *
 * @param {unknown} value The stored value.
 * @return {string} The readable duration, or '' when unset.
 * @spec openspec/specs/contactmomenten/spec.md
 */
export function isoDuration(value) {
	if (value === null || value === undefined || value === '') {
		return ''
	}
	const match = /^P(?:(\d+)D)?(?:T(?:(\d+)H)?(?:(\d+)M)?(?:(\d+(?:\.\d+)?)S)?)?$/.exec(String(value))
	if (!match || match[0] === 'P' || match[0] === 'PT') {
		return String(value)
	}
	const [, days, hours, minutes, seconds] = match
	const parts = []
	if (days) {
		parts.push(t('pipelinq', '{count} d', { count: Number(days) }))
	}
	if (hours) {
		parts.push(t('pipelinq', '{count} h', { count: Number(hours) }))
	}
	if (minutes) {
		parts.push(t('pipelinq', '{count} min', { count: Number(minutes) }))
	}
	if (seconds && parts.length === 0) {
		parts.push(t('pipelinq', '{count} s', { count: Math.round(Number(seconds)) }))
	}
	return parts.join(' ')
}

/**
 * When an appointment is, as the PqAfspraken board writes it: the start and
 * end time of day ("08:45 tot 09:00"). The row's `endAt` gives the end; a
 * row without one shows the start alone.
 *
 * @param {unknown} value The start (`startAt`).
 * @param {object} [row] The booking; its `endAt` is the end.
 * @return {string} The time range, or '' for an empty start.
 * @spec openspec/changes/screens-appointments-board/specs/appointment-booking/spec.md
 */
export function bookingWhen(value, row) {
	return timeRange(value, row?.endAt)
}

/**
 * When a POS transaction happened, as the PqKassabonnen board writes it
 * ("7 okt, 11:42"): its confirmation, else its parking, else its creation.
 * The year is added outside the current one.
 *
 * @param {unknown} _value The cell value (unused; the row holds the times).
 * @param {object} [row] The transaction.
 * @param {object} [_property] The schema property (unused).
 * @param {{now?: Date, locale?: string}} [options] Reference date and locale (tests).
 * @return {string} The moment, or '' when the row has no time at all.
 * @spec openspec/changes/pos-transaction-moment/specs/pos-transaction-core/spec.md
 */
export function transactionMoment(_value, row, _property, options) {
	return momentInYear(row?.confirmedAt || row?.parkedAt || row?.['@self']?.created, row, _property, options)
}

/**
 * A moment as a list writes it ("7 okt, 11:42"), the year added outside
 * the current one.
 *
 * @param {unknown} value The moment, ISO.
 * @param {object} [_row] The record (unused).
 * @param {object} [_property] The schema property (unused).
 * @param {{now?: Date, locale?: string}} [options] Reference date and locale (tests).
 * @return {string} The moment, or '' when it is empty or not a date.
 * @spec openspec/specs/declarative-view-system/spec.md
 */
export function momentInYear(value, _row, _property, options) {
	const date = value ? new Date(String(value)) : null
	if (!date || Number.isNaN(date.getTime())) {
		return ''
	}
	const opts = options || {}
	const now = opts.now instanceof Date ? opts.now : new Date()
	const locale =
		opts.locale
		|| (typeof OC !== 'undefined' && OC.getLocale
			? OC.getLocale().replace('_', '-')
			: undefined)
	const day = { day: 'numeric', month: 'short' }
	if (date.getFullYear() !== now.getFullYear()) {
		day.year = 'numeric'
	}
	const dayText = new Intl.DateTimeFormat(locale, day).format(date).replace('.', '')
	const timeText = new Intl.DateTimeFormat(locale, { hour: '2-digit', minute: '2-digit' }).format(date)
	return dayText + ', ' + timeText
}

/**
 * Language codes as the product page writes them: "nl, en" reads "NL, EN".
 *
 * @param {unknown} value The list of two-letter codes.
 * @return {string} The codes in capitals, or '' for none.
 * @spec openspec/changes/product-catalogue-fields/specs/product-catalog/spec.md
 */
export function languageCodes(value) {
	const list = Array.isArray(value) ? value : (value ? [value] : [])
	return list.map((code) => String(code).toUpperCase()).join(', ')
}

/**
 * A list of enum values as one phrase of labels, the first capitalised and
 * the rest in lower case ("Inwoner, bedrijf"). The labels come from the
 * property's `x-enum-labels`, through pipelinq's translate; a value with no
 * label shows as stored.
 *
 * @param {unknown} value The stored values.
 * @param {object} [_row] The row (unused).
 * @param {object} [property] The schema property.
 * @return {string} The phrase, or '' for none.
 * @spec openspec/changes/product-catalogue-fields/specs/product-catalog/spec.md
 */
export function labelList(value, _row, property) {
	const list = Array.isArray(value) ? value : (value ? [value] : [])
	return list
		.map((item, index) => {
			const label = enumLabel(item, null, property)
			return index === 0 || label === String(item) ? label : label.toLowerCase()
		})
		.join(', ')
}

/**
 * A date with the month written out and the year ("1 januari 2026").
 *
 * @param {unknown} value The date, `YYYY-MM-DD` or ISO.
 * @return {string} The date, or the value as stored when it is not one.
 * @spec openspec/changes/product-catalogue-fields/specs/product-catalog/spec.md
 */
export function longDate(value) {
	if (value === null || value === undefined || value === '') {
		return ''
	}
	const match = /^(\d{4})-(\d{2})-(\d{2})/.exec(String(value))
	if (!match) {
		return String(value)
	}
	const date = new Date(Number(match[1]), Number(match[2]) - 1, Number(match[3]))
	const locale =
		typeof OC !== 'undefined' && OC.getLocale
			? OC.getLocale().replace('_', '-')
			: undefined
	return new Intl.DateTimeFormat(locale, { day: 'numeric', month: 'long', year: 'numeric' }).format(date)
}

/**
 * A moment as the board's record cards write it: day, short month, year and
 * time ("7 okt 2026, 11:42").
 *
 * @param {unknown} value The moment, ISO.
 * @param {object} [_row] The record (unused).
 * @param {object} [_property] The schema property (unused).
 * @param {{locale?: string}} [options] The locale (tests).
 * @return {string} The moment, '' when empty, or the value as stored when it is not a date.
 * @spec openspec/specs/pos-transaction-core/spec.md
 */
export function shortDateTime(value, _row, _property, options) {
	if (value === null || value === undefined || value === '') {
		return ''
	}
	const date = new Date(String(value))
	if (Number.isNaN(date.getTime())) {
		return String(value)
	}
	const locale =
		options?.locale
		|| (typeof OC !== 'undefined' && OC.getLocale
			? OC.getLocale().replace('_', '-')
			: undefined)
	const dayText = new Intl.DateTimeFormat(locale, { day: 'numeric', month: 'short', year: 'numeric' }).format(date).replace('.', '')
	const timeText = new Intl.DateTimeFormat(locale, { hour: '2-digit', minute: '2-digit' }).format(date)
	return dayText + ', ' + timeText
}

/**
 * The hour and minute of a moment ("12:10"), for a list of events within
 * one day.
 *
 * @param {unknown} value The moment, ISO.
 * @param {object} [_row] The record (unused).
 * @param {object} [_property] The schema property (unused).
 * @param {{locale?: string}} [options] The locale (tests).
 * @return {string} The time, '' when empty, or the value as stored when it is not a date.
 * @spec openspec/specs/pos-transaction-core/spec.md
 */
export function timeOfDay(value, _row, _property, options) {
	if (value === null || value === undefined || value === '') {
		return ''
	}
	const date = new Date(String(value))
	if (Number.isNaN(date.getTime())) {
		return String(value)
	}
	const locale =
		options?.locale
		|| (typeof OC !== 'undefined' && OC.getLocale
			? OC.getLocale().replace('_', '-')
			: undefined)
	return new Intl.DateTimeFormat(locale, { hour: '2-digit', minute: '2-digit' }).format(date)
}

/** The registry handed to CnAppRoot. */
export const CELL_FORMATTERS = {
	objectCurrency,
	transactionMoment,
	momentInYear,
	enumText,
	enumWord,
	enumLabel,
	yesNo,
	callDuration: isoDuration,
	bookingWhen,
	languageCodes,
	labelList,
	longDate,
	shortDateTime,
	timeOfDay,
	exportMoment: (value) => exportMoment(value),
	exportSchedule: (value) => exportSchedule(value),
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
		...MARKETING_FORMATTERS,
	}
}
