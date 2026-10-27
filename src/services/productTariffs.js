/**
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * The product's fee table and weight as the product page shows them.
 * A tariff `{label, price, validFrom, note}` without `validFrom` is always in
 * force; one with a later `validFrom` replaces the same label from that day.
 *
 * @spec openspec/changes/product-catalogue-fields/specs/product-catalog/spec.md
 */

/**
 * The tariffs in force on a day: per label the one with the latest
 * valid-from on or before it, in the order the labels first appear.
 *
 * @param {Array<object>|undefined} tariffs The product's tariffs.
 * @param {string} today The day, `YYYY-MM-DD`.
 * @return {Array<object>} The tariffs in force.
 * @spec openspec/changes/product-catalogue-fields/specs/product-catalog/spec.md
 */
export function currentTariffs(tariffs, today) {
	const byLabel = new Map()
	for (const tariff of Array.isArray(tariffs) ? tariffs : []) {
		const from = tariff?.validFrom || ''
		if (!tariff || from > today) {
			continue
		}
		const held = byLabel.get(tariff.label)
		if (!held || from >= (held.validFrom || '')) {
			byLabel.set(tariff.label, tariff)
		}
	}
	return [...byLabel.values()]
}

/**
 * The first day after `today` on which a tariff goes live, or null.
 *
 * @param {Array<object>|undefined} tariffs The product's tariffs.
 * @param {string} today The day, `YYYY-MM-DD`.
 * @return {string|null} The day, `YYYY-MM-DD`.
 * @spec openspec/changes/product-catalogue-fields/specs/product-catalog/spec.md
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
 * @spec openspec/changes/product-catalogue-fields/specs/product-catalog/spec.md
 */
export function productWeight(product) {
	let grams = null
	if (Number.isFinite(product?.weightGrams)) {
		grams = product.weightGrams
	} else if (Number.isFinite(product?.weight)) {
		grams = product.weight * 1000
	}
	if (grams === null) {
		return ''
	}
	const locale =
		typeof OC !== 'undefined' && OC.getLocale
			? OC.getLocale().replace('_', '-')
			: undefined
	const number = (value) =>
		new Intl.NumberFormat(locale, { maximumFractionDigits: 3 }).format(value)
	return grams < 1000 ? `${number(Math.round(grams))} g` : `${number(grams / 1000)} kg`
}
