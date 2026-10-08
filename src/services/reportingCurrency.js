// SPDX-License-Identifier: EUPL-1.2
// Copyright (C) 2026 Conduction B.V.

/**
 * The reporting currency, as the setup wizard stored it (`currency` app
 * config, handed to the page as the `config` initial state).
 *
 * Every money figure that is not tied to one object's own currency shows in
 * this currency, so an install set up in USD never reads "EUR".
 *
 * @spec openspec/changes/review-finish/specs/commercial-dashboard/spec.md
 */

import { loadState } from '@nextcloud/initial-state'

/** The currency used when no setup choice is available (tests, public pages). */
export const FALLBACK_CURRENCY = 'EUR'

/**
 * Read the reporting currency.
 *
 * @return {string} An upper-case three-letter currency code.
 * @spec openspec/changes/review-finish/specs/commercial-dashboard/spec.md
 */
export function reportingCurrency() {
	try {
		const code = String(loadState('pipelinq', 'config', {})?.currency || '')
			.trim()
			.toUpperCase()
		return /^[A-Z]{3}$/.test(code) ? code : FALLBACK_CURRENCY
	} catch {
		return FALLBACK_CURRENCY
	}
}

/**
 * Pick the currency for an amount: the object's own currency when it has a
 * valid one, otherwise the reporting currency.
 *
 * @param {string|null|undefined} own The object's own currency code.
 * @return {string} An upper-case three-letter currency code.
 * @spec openspec/changes/review-finish/specs/commercial-dashboard/spec.md
 */
export function currencyOr(own) {
	const code = String(own || '')
		.trim()
		.toUpperCase()
	return /^[A-Z]{3}$/.test(code) ? code : reportingCurrency()
}
