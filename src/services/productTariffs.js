/**
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * The product's fee table and weight as the product page shows them.
 * A tariff `{label, price, validFrom, note}` without `validFrom` is always in
 * force; one with a later `validFrom` replaces the same label from that day.
 *
 * @spec openspec/specs/product-catalog/spec.md
 */

/**
 * The tariffs in force on a day: per label the one with the latest
 * valid-from on or before it, in the order the labels first appear.
 *
 * @param {Array<object>|undefined} tariffs The product's tariffs.
 * @param {string} today The day, `YYYY-MM-DD`.
 * @return {Array<object>} The tariffs in force.
 * @spec openspec/specs/product-catalog/spec.md
 */
export function currentTariffs(tariffs, today) {
	const byLabel = new Map()
	for (const tariff of Array.isArray(tariffs) ? tariffs : []) {
		if (isInForce(tariff, today) && replaces(tariff, byLabel.get(tariff.label))) {
			byLabel.set(tariff.label, tariff)
		}
	}
	return [...byLabel.values()]
}

/**
 * Whether a tariff is in force on a day: it has no valid-from, or one on
 * or before the day.
 *
 * @param {object|null} tariff The tariff.
 * @param {string} today The day, `YYYY-MM-DD`.
 * @return {boolean}
 * @spec openspec/specs/product-catalog/spec.md
 */
function isInForce(tariff, today) {
	if (!tariff) {
		return false
	}
	return !((tariff.validFrom || '') > today)
}

/**
 * Whether a tariff replaces the one held for its label: there is none, or
 * it is valid from the same day or later.
 *
 * @param {object} tariff The tariff.
 * @param {object|undefined} held The tariff held so far.
 * @return {boolean}
 * @spec openspec/specs/product-catalog/spec.md
 */
function replaces(tariff, held) {
	return !held || (tariff.validFrom || '') >= (held.validFrom || '')
}

/**
 * The first day after `today` on which a tariff goes live, or null.
 *
 * @param {Array<object>|undefined} tariffs The product's tariffs.
 * @param {string} today The day, `YYYY-MM-DD`.
 * @return {string|null} The day, `YYYY-MM-DD`.
 * @spec openspec/specs/product-catalog/spec.md
 */
export function nextTariffDate(tariffs, today) {
	const later = (Array.isArray(tariffs) ? tariffs : [])
		.map((tariff) => tariff?.validFrom || '')
		.filter((from) => from > today)
		.sort()
	return later[0] || null
}

/**
 * The product's weight: grams under a kilogram, kilograms from one up.
 * `weightGrams` wins over `weight` (kg) when both are set.
 *
 * @param {object} product The product.
 * @return {string} The weight, or '' when none is set.
 * @spec openspec/specs/product-catalog/spec.md
 */
export function productWeight(product) {
	const grams = weightInGrams(product)
	if (grams === null) {
		return ''
	}
	const locale = userLocale()
	const number = (value) =>
		new Intl.NumberFormat(locale, { maximumFractionDigits: 3 }).format(value)
	return grams < 1000 ? `${number(Math.round(grams))} g` : `${number(grams / 1000)} kg`
}

/**
 * The product's weight in grams, from grams or else kilograms, or null.
 *
 * @param {object|null} product The product.
 * @return {number|null} The grams.
 * @spec openspec/specs/product-catalog/spec.md
 */
function weightInGrams(product) {
	if (Number.isFinite(product?.weightGrams)) {
		return product.weightGrams
	}
	return Number.isFinite(product?.weight) ? product.weight * 1000 : null
}

/**
 * The user's locale as a BCP 47 tag, or undefined outside Nextcloud.
 *
 * @return {string|undefined} The locale.
 * @spec exclude reads the Nextcloud locale, no behaviour of its own.
 */
function userLocale() {
	return typeof OC !== 'undefined' && OC.getLocale
		? OC.getLocale().replace('_', '-')
		: undefined
}
