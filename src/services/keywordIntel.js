/*
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Pure client-side helpers for the Keywords page (marketing-search-intelligence).
 *
 * Everything here is a function of its arguments: no store, no request, no
 * component. That is what lets the page's arithmetic be tested offline in
 * tests/vitest, and it keeps the formatting decisions in one place instead of
 * inside four templates that would drift.
 *
 * @spec openspec/changes/marketing-search-intelligence/specs/marketing-keyword-intelligence/spec.md#requirement-the-keywords-page-shows-the-four-derivations-and-confirms-one-at-a-time
 */

/**
 * The proposal kinds a confirmation may claim to come from, in the order the
 * page renders their sections. Mirrors KeywordTargetService::PROPOSAL_KINDS.
 *
 * @type {Array<string>}
 */
export const PROPOSAL_KINDS = [
	'striking-distance',
	'cannibalisation',
	'content-gap',
	'manual',
]

/**
 * What a marketer may decide about a term. Mirrors
 * KeywordTargetService::STATUSES.
 *
 * @type {Array<string>}
 */
export const TARGET_STATUSES = ['use-more', 'use-less', 'watch']

/**
 * A ratio between 0 and 1 as a percentage with one decimal.
 *
 * @param {number} ratio The ratio.
 * @return {string} The percentage.
 * @spec openspec/changes/marketing-search-intelligence/specs/marketing-keyword-intelligence/spec.md#requirement-the-keywords-page-shows-the-four-derivations-and-confirms-one-at-a-time
 */
export function percent(ratio) {
	return `${(Number(ratio || 0) * 100).toFixed(1)}%`
}

/**
 * The click-through shortfall of a striking-distance row, as a percentage.
 *
 * A shortfall is the gap between what the position normally earns and what
 * the query actually earns, so a negative value is meaningless and is shown
 * as zero rather than as a negative gap.
 *
 * @param {object} row A striking-distance row.
 * @return {string} The shortfall.
 * @spec openspec/changes/marketing-search-intelligence/specs/marketing-keyword-intelligence/spec.md#requirement-striking-distance-queries-are-queries-one-push-from-page-one
 */
export function shortfall(row) {
	return percent(Math.max(0, Number(row?.shortfall || 0)))
}

/**
 * The share of a query's impressions one page carries, as a percentage.
 *
 * @param {object} page One page of a cannibalisation finding.
 * @param {number} total The query's total impressions.
 * @return {string} The share, or a dash when the total is zero.
 * @spec openspec/changes/marketing-search-intelligence/specs/marketing-keyword-intelligence/spec.md#requirement-cannibalisation-names-two-pages-competing-for-one-query
 */
export function pageShare(page, total) {
	const impressions = Number(page?.impressions || 0)
	const denominator = Number(total || 0)
	if (denominator <= 0) {
		return '-'
	}
	return percent(impressions / denominator)
}

/**
 * Whether a term already has a confirmed keyword target, so the page can mark
 * the proposal as taken instead of offering to confirm it twice.
 *
 * @param {string} term The term.
 * @param {Array<string>} confirmed The confirmed terms, lowercase.
 * @return {boolean} True when it is already a target.
 * @spec openspec/changes/marketing-search-intelligence/specs/marketing-keyword-intelligence/spec.md#requirement-a-proposal-becomes-a-keyword-target-only-when-a-person-confirms-it
 */
export function isConfirmed(term, confirmed) {
	const needle = String(term || '')
		.trim()
		.toLowerCase()
	if (needle === '') {
		return false
	}
	return (confirmed || []).some((value) => String(value).toLowerCase() === needle)
}

/**
 * The body posted to confirm one proposal.
 *
 * The status defaults to `watch` and an unknown kind falls back to `manual`,
 * because the server refuses a value outside its vocabulary and a page that
 * sends one would fail the save without saying why.
 *
 * @param {object} proposal The proposal row.
 * @param {object} choice What the marketer chose: status, intent, notes.
 * @param {string} kind The proposal kind the section renders.
 * @return {object} The request body.
 * @spec openspec/changes/marketing-search-intelligence/specs/marketing-keyword-intelligence/spec.md#requirement-a-proposal-becomes-a-keyword-target-only-when-a-person-confirms-it
 */
export function confirmPayload(proposal, choice, kind) {
	const status = TARGET_STATUSES.includes(choice?.status) ? choice.status : 'watch'
	const proposalKind = PROPOSAL_KINDS.includes(kind) ? kind : 'manual'
	return {
		term: String(proposal?.query || proposal?.term || '').trim(),
		status,
		proposalKind,
		intent: String(choice?.intent || ''),
		targetPageRef: String(choice?.targetPageRef || proposal?.topPage || ''),
		property: String(choice?.property || ''),
		notes: String(choice?.notes || ''),
	}
}

/**
 * What the gap section says when the crawl did not run.
 *
 * An empty list and a crawl that never happened look identical on screen, and
 * this is the sentence that keeps them apart.
 *
 * @param {object} crawl The crawl block of the proposals response.
 * @return {string} The message, or an empty string when the crawl ran.
 * @spec openspec/changes/marketing-search-intelligence/specs/marketing-keyword-intelligence/spec.md#requirement-a-content-gap-is-a-query-no-page-of-ours-answers
 */
export function crawlNotice(crawl) {
	if (crawl?.crawled) {
		return ''
	}
	return String(crawl?.reason || '')
}
