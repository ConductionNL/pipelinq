// SPDX-License-Identifier: EUPL-1.2
// Copyright (C) 2026 Conduction B.V.

/**
 * Currency helpers for a lead's value and the forecast's rate table
 * (pipelinq#2040). Pure, so the rules the server enforces can be checked
 * before a save.
 *
 * @spec openspec/specs/forecast-roll-up-and-categories/spec.md#REQ-FRC-004-05
 */

import { translate as t } from '@nextcloud/l10n'
import { formatDate, getUserLocale } from './localeUtils.js'

const CODE = /^[A-Z]{3}$/

/**
 * Normalise a currency code as typed: trimmed and upper case.
 *
 * @param {string|null|undefined} value The typed code.
 * @return {string} The code, or '' when empty.
 * @spec openspec/specs/forecast-roll-up-and-categories/spec.md#REQ-FRC-004-05
 */
export function normaliseCurrency(value) {
	return String(value ?? '')
		.trim()
		.toUpperCase()
}

/**
 * Whether a code has the ISO 4217 shape (three letters).
 *
 * @param {string} value The code.
 * @return {boolean} True for a three-letter code.
 * @spec openspec/specs/forecast-roll-up-and-categories/spec.md#REQ-FRC-004-05
 */
export function isCurrencyCode(value) {
	return CODE.test(normaliseCurrency(value))
}

/**
 * Turn the stored rate table into editable rows.
 *
 * @param {object|null|undefined} table Currency code => rate.
 * @return {Array<{currency: string, rate: string}>} Rows, sorted by code.
 * @spec openspec/specs/forecast-roll-up-and-categories/spec.md#REQ-FRC-004-05
 */
export function rowsFromRates(table) {
	return Object.entries(table || {})
		.map(([currency, rate]) => ({ currency, rate: String(rate) }))
		.sort((a, b) => a.currency.localeCompare(b.currency))
}

/**
 * Turn edited rows back into a rate table, skipping blank rows.
 *
 * @param {Array<{currency: string, rate: string|number}>} rows The rows.
 * @return {{rates: object, error: string}} The table, or the first problem found.
 * @spec openspec/specs/forecast-roll-up-and-categories/spec.md#REQ-FRC-004-05
 */
export function ratesFromRows(rows) {
	const rates = {}
	for (const row of rows || []) {
		const currency = normaliseCurrency(row?.currency)
		const raw = String(row?.rate ?? '').trim()
		if (currency === '' && raw === '') {
			continue
		}
		if (!isCurrencyCode(currency)) {
			return { rates: {}, error: 'code' }
		}
		const rate = Number(raw)
		if (!Number.isFinite(rate) || rate <= 0) {
			return { rates: {}, error: 'rate' }
		}
		rates[currency] = rate
	}
	return { rates, error: '' }
}

/**
 * Where a resolved rate came from, in words.
 *
 * @param {{source: string, origin: string|null}} resolved A resolved rate.
 * @return {string} The source label.
 * @spec openspec/changes/pipeline-live-exchange-rates/specs/deal-exchange-rates/spec.md#requirement-rates-come-from-the-fleets-rate-store-before-the-hand-kept-table-req-dxr-001
 */
export function rateSourceLabel(resolved) {
	switch (resolved?.source) {
	case 'override':
		return t('pipelinq', 'Set by an administrator')
	case 'shillinq':
		if (resolved.origin === 'ecb') {
			return t('pipelinq', 'ECB via Shillinq')
		}
		if (resolved.origin === 'bank-feed') {
			return t('pipelinq', 'Bank feed via Shillinq')
		}
		return t('pipelinq', 'Typed in Shillinq')
	case 'manual':
		return t('pipelinq', 'Typed in Forecast settings')
	case 'none':
		return t('pipelinq', 'No rate, counted at face value')
	default:
		return ''
	}
}

/**
 * The rate line under a converted amount: "1 USD = 0.9132 EUR, rate of 6 Oct".
 *
 * @param {{currency: string, reportingCurrency: string, rate: number, rateDate: string|null, source: string}} resolved A resolved rate.
 * @param {string} reportingCurrency The reporting currency, when the rate does not carry it.
 * @return {string} The line, or '' for a rate that is not a conversion.
 * @spec openspec/changes/pipeline-live-exchange-rates/specs/deal-exchange-rates/spec.md#requirement-a-stale-rate-is-shown-as-stale-req-dxr-002
 */
export function rateLine(resolved, reportingCurrency = '') {
	if (!resolved || resolved.source === 'same' || resolved.source === 'none') {
		return ''
	}
	const reporting = resolved.reportingCurrency || reportingCurrency
	const rate = Number(resolved.rate).toLocaleString(getUserLocale(), {
		maximumFractionDigits: 6,
	})
	const pair = `1 ${resolved.currency} = ${rate} ${reporting}`
	if (!resolved.rateDate) {
		return t('pipelinq', '{pair}, date unknown', { pair })
	}
	return t('pipelinq', '{pair}, rate of {date}', {
		pair,
		date: formatDate(resolved.rateDate),
	})
}

/**
 * Turn the stored fixed rates into editable rows.
 *
 * @param {object|null|undefined} table Currency code => {rate, reason, date}.
 * @return {Array<{currency: string, rate: string, reason: string}>} Rows, sorted by code.
 * @spec openspec/changes/pipeline-live-exchange-rates/specs/deal-exchange-rates/spec.md#requirement-an-administrator-can-override-a-rate-on-purpose-req-dxr-003
 */
export function rowsFromOverrides(table) {
	return Object.entries(table || {})
		.map(([currency, entry]) => ({
			currency,
			rate: String(entry?.rate ?? ''),
			reason: String(entry?.reason ?? ''),
		}))
		.sort((a, b) => a.currency.localeCompare(b.currency))
}

/**
 * Turn edited fixed-rate rows back into overrides, skipping blank rows.
 *
 * @param {Array<{currency: string, rate: string|number, reason: string}>} rows The rows.
 * @return {{overrides: object, error: string}} The overrides, or the first problem
 *   found: `code`, `rate` or `reason`.
 * @spec openspec/changes/pipeline-live-exchange-rates/specs/deal-exchange-rates/spec.md#requirement-an-administrator-can-override-a-rate-on-purpose-req-dxr-003
 */
export function overridesFromRows(rows) {
	const overrides = {}
	for (const row of rows || []) {
		const reason = String(row?.reason ?? '').trim()
		const { rates, error } = ratesFromRows([row])
		const [currency] = Object.keys(rates)
		if (error) {
			return { overrides: {}, error }
		}
		if (!currency) {
			if (reason !== '') {
				return { overrides: {}, error: 'code' }
			}
			continue
		}
		if (reason === '') {
			return { overrides: {}, error: 'reason' }
		}
		overrides[currency] = { rate: rates[currency], reason }
	}
	return { overrides, error: '' }
}
