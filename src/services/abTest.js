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
		clickRate: clickRate(clicked, delivered),
		subject: templateSubjects[blast?.templateId] || '',
	}
}

/**
 * Clicks per delivered message, or 0 when nothing was delivered.
 *
 * @param {number} clicked The clicks.
 * @param {number} delivered The delivered messages.
 * @return {number} The rate.
 */
function clickRate(clicked, delivered) {
	return delivered > 0 ? clicked / delivered : 0
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
		if (takesPartInAbTest(blast, parents) && blast.templateId) {
			ids.add(blast.templateId)
		}
	}
	return [...ids]
}

/**
 * Whether a blast is a variant, or the parent a variant names.
 *
 * @param {object} blast The blast.
 * @param {Set<string>} parents The ids variants name as their parent.
 * @return {boolean} Whether it takes part.
 */
function takesPartInAbTest(blast, parents) {
	const id = blast.id || blast.uuid || blast.slug
	return Boolean(blast.abVariantOf || parents.has(id))
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
	const a = { clicks: aClicks, delivered: aDelivered, non: aDelivered - aClicks }
	const b = { clicks: bClicks, delivered: bDelivered, non: bDelivered - bClicks }
	if (!isUsableTable(a, b)) {
		return null
	}
	return erfc(Math.sqrt(chiSquare(a, b) / 2))
}

/**
 * Whether a variant has deliveries and no more clicks than deliveries.
 *
 * @param {{delivered: number, non: number}} variant The variant's counts.
 * @return {boolean} Whether it can be tested.
 */
function isUsableVariant(variant) {
	return !(variant.delivered <= 0 || variant.non < 0)
}

/**
 * Whether the two variants make a table the test can judge: both usable,
 * and both a click and a non-click column.
 *
 * @param {{clicks: number, delivered: number, non: number}} a Variant A's counts.
 * @param {{clicks: number, delivered: number, non: number}} b Variant B's counts.
 * @return {boolean} Whether the table is usable.
 */
function isUsableTable(a, b) {
	if (!isUsableVariant(a) || !isUsableVariant(b)) {
		return false
	}
	return !(a.clicks + b.clicks <= 0 || a.non + b.non <= 0)
}

/**
 * Pearson's chi-square statistic over the four cells.
 *
 * @param {{clicks: number, delivered: number, non: number}} a Variant A's counts.
 * @param {{clicks: number, delivered: number, non: number}} b Variant B's counts.
 * @return {number} The statistic.
 */
function chiSquare(a, b) {
	const colClicks = a.clicks + b.clicks
	const colNon = a.non + b.non
	const total = a.delivered + b.delivered
	const cells = [
		[a.clicks, (a.delivered * colClicks) / total],
		[a.non, (a.delivered * colNon) / total],
		[b.clicks, (b.delivered * colClicks) / total],
		[b.non, (b.delivered * colNon) / total],
	]
	return cells.reduce((sum, [observed, expected]) => sum + (observed - expected) ** 2 / expected, 0)
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
