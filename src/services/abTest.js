// SPDX-License-Identifier: EUPL-1.2
// Copyright (C) 2026 Conduction B.V.

/**
 * A/B test arithmetic for the newsletter results page: the click figures of
 * one variant, a 2x2 chi-square p-value over two variants, and which variant
 * clicked better.
 *
 * @spec openspec/specs/marketing/spec.md
 */

/**
 * Delivered, clicked and click rate of one variant blast, with the subject of
 * the template it sent. A variant differs from its parent by its template, so
 * the subject is the template's.
 *
 * @param {object} blast            The variant blast.
 * @param {object} templateSubjects Subject per template id.
 * @return {{delivered: number, clicked: number, clickRate: number, subject: string}}
 *
 * @spec openspec/specs/marketing/spec.md#requirement-the-a-b-tab-names-the-variant-that-clicked-better
 */
export function variantStats(blast, templateSubjects = {}) {
	const totals = blast?.totals || {}
	const delivered = totals.delivered || 0
	const clicked = totals.clicked || 0
	return {
		delivered,
		clicked,
		clickRate: delivered > 0 ? clicked / delivered : 0,
		subject: templateSubjects[blast?.templateId] || '',
	}
}

/**
 * The template ids of every blast that takes part in an A/B test: each
 * variant and the parent it names.
 *
 * @param {Array<object>} blasts The loaded blasts.
 * @return {Array<string>} Distinct template ids.
 *
 * @spec openspec/specs/marketing/spec.md#requirement-the-a-b-tab-names-the-variant-that-clicked-better
 */
export function abTemplateIds(blasts) {
	const parents = new Set(blasts.map((b) => b.abVariantOf).filter(Boolean))
	const ids = new Set()
	for (const blast of blasts) {
		const id = blast.id || blast.uuid || blast.slug
		if ((blast.abVariantOf || parents.has(id)) && blast.templateId) {
			ids.add(blast.templateId)
		}
	}
	return [...ids]
}

/**
 * Abramowitz-Stegun 7.1.26 approximation of erfc(x), good to about 1.5e-7.
 *
 * @param {number} x Input.
 * @return {number} erfc(x).
 */
export function erfc(x) {
	if (x < 0) {
		return 2 - erfc(-x)
	}
	const t = 1 / (1 + 0.3275911 * x)
	const y = 1
		- ((((1.061405429 * t - 1.453152027) * t + 1.421413741) * t - 0.284496736) * t + 0.254829592)
		* t
		* Math.exp(-x * x)
	return 1 - y
}

/**
 * Pearson chi-square p-value (one degree of freedom) on clicks against
 * non-clicks for two variants.
 *
 * @param {number} aClicks    Variant A clicks.
 * @param {number} aDelivered Variant A delivered.
 * @param {number} bClicks    Variant B clicks.
 * @param {number} bDelivered Variant B delivered.
 * @return {number|null} The p-value, or null when the table is degenerate.
 */
export function chiSquarePValue(aClicks, aDelivered, bClicks, bDelivered) {
	const aNon = aDelivered - aClicks
	const bNon = bDelivered - bClicks
	if (aDelivered <= 0 || bDelivered <= 0 || aNon < 0 || bNon < 0) {
		return null
	}
	const colClicks = aClicks + bClicks
	const colNon = aNon + bNon
	const total = aDelivered + bDelivered
	if (colClicks <= 0 || colNon <= 0) {
		return null
	}
	const cells = [
		[aClicks, (aDelivered * colClicks) / total],
		[aNon, (aDelivered * colNon) / total],
		[bClicks, (bDelivered * colClicks) / total],
		[bNon, (bDelivered * colNon) / total],
	]
	const chi = cells.reduce((sum, [observed, expected]) => sum + (observed - expected) ** 2 / expected, 0)
	return erfc(Math.sqrt(chi / 2))
}

/**
 * The variant with the higher click rate, or null on a tie.
 *
 * @param {{clickRate: number}} a Variant A stats.
 * @param {{clickRate: number}} b Variant B stats.
 * @return {'a'|'b'|null}
 */
export function higherVariant(a, b) {
	if (a.clickRate === b.clickRate) {
		return null
	}
	return b.clickRate > a.clickRate ? 'b' : 'a'
}

/**
 * Each variant's share of the delivered mail, as whole percentages that add
 * up to 100.
 *
 * @param {{delivered: number}} a Variant A stats.
 * @param {{delivered: number}} b Variant B stats.
 * @return {{a: number, b: number}}
 */
export function variantSplit(a, b) {
	const total = a.delivered + b.delivered
	if (total <= 0) {
		return { a: 50, b: 50 }
	}
	const shareA = Math.round((a.delivered / total) * 100)
	return { a: shareA, b: 100 - shareA }
}

/**
 * A 0..1 fraction as a whole percentage.
 *
 * @param {number} fraction The fraction.
 * @return {string}
 */
export function wholePercent(fraction) {
	if (typeof fraction !== 'number' || isNaN(fraction)) {
		return '—'
	}
	return `${Math.round(fraction * 100)}%`
}
