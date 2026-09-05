// SPDX-License-Identifier: EUPL-1.2
// Copyright (C) 2026 Conduction B.V.

/**
 * Article lifecycle status, as the interface reads it.
 *
 * Pure and dependency-free, so it can be unit-tested offline the way
 * `subscriptionState.js` is. Two jobs:
 *
 *   • map a stored `status` value to the chip a card or the detail page
 *     renders, and to the transitions legal from it,
 *   • group a page of usage rows (`GET /api/articles/{id}/usages`) into the
 *     sections the usage list renders.
 *
 * The declared lifecycle (`x-openregister-lifecycle` on the `article`
 * schema, `lib/Settings/register.d/97-marketing-articles.json`) is the
 * source of truth for which moves are legal; the shape here mirrors it so a
 * button never offers a move the server would refuse. Colours are Nextcloud
 * CSS variables, never literals, per the app's styling rule.
 *
 * @spec openspec/changes/marketing-article-hub/specs/marketing-articles/spec.md#requirement-an-article-moves-through-a-declared-lifecycle
 */

/** Every status the `article` schema declares, in lifecycle order. */
export const ARTICLE_STATUSES = ['draft', 'review', 'published', 'archived']

const CHIPS = {
	draft: {
		label: 'Draft',
		color: 'var(--color-text-maxcontrast)',
	},
	review: {
		label: 'In review',
		color: 'var(--color-warning)',
	},
	published: {
		label: 'Published',
		color: 'var(--color-success)',
	},
	archived: {
		label: 'Archived',
		color: 'var(--color-text-maxcontrast)',
	},
}

/**
 * The chip a status renders.
 *
 * An unknown status falls back to a neutral "Unknown" chip rather than
 * throwing: the interface has to render something for a status a newer
 * schema introduced that this build has never heard of.
 *
 * @param {string} status The stored `status` value.
 * @return {{label: string, color: string}} The chip.
 * @spec openspec/changes/marketing-article-hub/specs/marketing-articles/spec.md#requirement-an-article-moves-through-a-declared-lifecycle
 */
export function chipForStatus(status) {
	const chip = CHIPS[status]
	if (chip) {
		return { ...chip }
	}
	return {
		label: 'Unknown',
		color: 'var(--color-text-maxcontrast)',
	}
}

/**
 * Legal transitions from a status, mirroring the schema's declared
 * lifecycle. `endpoint` names which controller route drives the move:
 * `publish` and `archive` stamp a moment and so have their own routes;
 * everything else rides the generic `transition` route with the transition
 * name as its body.
 *
 * An unknown status offers no transitions: guessing a move for a status
 * this build has never heard of is the one guess that can silently take an
 * article somewhere the schema never declared.
 *
 * @param {string} status The stored `status` value.
 * @return {Array<{id: string, label: string, endpoint: string}>} The moves.
 * @spec openspec/changes/marketing-article-hub/specs/marketing-articles/spec.md#requirement-an-article-moves-through-a-declared-lifecycle
 */
export function transitionsForStatus(status) {
	const table = {
		draft: [
			{
				id: 'submitForReview',
				label: 'Submit for review',
				endpoint: 'transition',
			},
			{ id: 'publish', label: 'Publish', endpoint: 'publish' },
		],
		review: [
			{
				id: 'returnToDraft',
				label: 'Return to draft',
				endpoint: 'transition',
			},
			{ id: 'publish', label: 'Publish', endpoint: 'publish' },
		],
		published: [{ id: 'archive', label: 'Archive', endpoint: 'archive' }],
		archived: [
			{ id: 'restore', label: 'Restore as draft', endpoint: 'transition' },
		],
	}
	return (table[status] || []).map((entry) => ({ ...entry }))
}

/** Usage kinds the usages endpoint reports, in display order. */
export const USAGE_KINDS = ['template', 'blast']

const USAGE_GROUP_LABELS = {
	template: 'Campaign templates',
	blast: 'Blasts',
}

/**
 * Group usage rows by kind for the usage section.
 *
 * Every declared kind is present in the result even at zero, so the section
 * does not change shape as usages appear — the same reason
 * `countByState()` always returns every subscription state.
 *
 * @param {Array<object>} rows Usage payloads (`{kind, id, name, status}`).
 * @return {Array<{kind: string, label: string, items: Array<object>}>} The groups.
 * @spec openspec/changes/marketing-article-hub/specs/marketing-articles/spec.md#requirement-an-article-reports-where-it-has-been-used
 */
export function groupUsages(rows) {
	const safeRows = Array.isArray(rows) ? rows : []
	return USAGE_KINDS.map((kind) => ({
		kind,
		label: USAGE_GROUP_LABELS[kind],
		items: safeRows.filter((row) => row && row.kind === kind),
	}))
}
