// SPDX-License-Identifier: EUPL-1.2
// Copyright (C) 2026 Conduction B.V.

/**
 * Currency helpers for a lead's value and the forecast's rate table
 * (pipelinq#2040). Pure, so the rules the server enforces can be checked
 * before a save.
 *
 * @spec openspec/changes/forecast-roll-up-and-categories/specs.md#REQ-FRC-004-05
 */

const CODE = /^[A-Z]{3}$/

/**
 * Normalise a currency code as typed: trimmed and upper case.
 *
 * @param {string|null|undefined} value The typed code.
 * @return {string} The code, or '' when empty.
 * @spec openspec/changes/forecast-roll-up-and-categories/specs.md#REQ-FRC-004-05
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
 * @spec openspec/changes/forecast-roll-up-and-categories/specs.md#REQ-FRC-004-05
 */
export function isCurrencyCode(value) {
	return CODE.test(normaliseCurrency(value))
}
