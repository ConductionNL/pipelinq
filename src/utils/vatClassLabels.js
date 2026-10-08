// SPDX-License-Identifier: EUPL-1.2
// Copyright (C) 2026 Conduction B.V.

/**
 * Label the product form's VAT class options with the configured rate
 * (pipelinq-forms-review, B4).
 *
 * The schema ships static `x-enum-labels` with the Dutch rates ("High (21%)").
 * An administrator can change the rate per class on the admin settings page,
 * and the server hands the result over as `config.vat_rates`. This turns that
 * map into a `fieldOverrides.vatClass.enumLabels` on every page whose schema
 * is `product`; fieldsFromSchema merges an override over the field, so the
 * options read the configured rates without touching the schema.
 */

const CLASS_NAMES = {
	high: 'High',
	low: 'Low',
	zero: 'Zero',
}

/**
 * The option label per VAT class.
 *
 * @param {object} rates Rate per class, e.g. `{ high: 21, low: 9 }`.
 * @param {(text: string) => string} translate Translates an English source string.
 * @return {object|null} `{ high: 'High (21%)', … }`, or null without rates.
 * @spec openspec/changes/pipelinq-forms-review/specs/product-catalog/spec.md
 */
export function vatClassLabels(rates, translate = (text) => text) {
	if (!rates || typeof rates !== 'object') {
		return null
	}
	const labels = { exempt: translate('Exempt') }
	for (const [cls, name] of Object.entries(CLASS_NAMES)) {
		const rate = Number(rates[cls])
		labels[cls] = Number.isFinite(rate)
			? `${translate(name)} (${rate}%)`
			: translate(name)
	}
	return labels
}

/**
 * Seed the labels onto every page whose schema is `product`.
 *
 * @param {object} manifest The merged manifest (with `pages[]`).
 * @param {object} rates Rate per class from `config.vat_rates`.
 * @param {(text: string) => string} translate Translates an English source string.
 * @return {object} The same manifest.
 * @spec openspec/changes/pipelinq-forms-review/specs/product-catalog/spec.md
 */
export function seedVatClassLabels(manifest, rates, translate) {
	const enumLabels = vatClassLabels(rates, translate)
	if (!enumLabels) {
		return manifest
	}
	for (const page of manifest.pages || []) {
		if (page.config?.schema !== 'product') {
			continue
		}
		const overrides = page.config.fieldOverrides || {}
		page.config.fieldOverrides = {
			...overrides,
			vatClass: { ...(overrides.vatClass || {}), enumLabels },
		}
	}
	return manifest
}
