// SPDX-License-Identifier: EUPL-1.2
// Copyright (C) 2026 Conduction B.V.

/**
 * What the interface needs to know about each social network.
 *
 * Pure and dependency-free, so it can be unit-tested offline the way
 * `articleStatus.js` is.
 *
 * THE LIMITS ARE A MIRROR, NOT A SECOND OPINION. Every number here is the
 * same number the matching PHP adapter's `bodyLimit()` returns, and the
 * vitest spec asserts the table against the values in
 * `lib/Service/Social/*Adapter.php`. Two copies that can disagree would mean
 * a composer that says a post fits and a server that refuses it, which is the
 * one failure this whole module exists to prevent.
 *
 * @spec openspec/changes/social-publishing/specs/social-posts/spec.md#requirement-one-post-carries-per-network-variants
 */

/** Every network the `socialAccount.network` enum declares, in its order. */
export const SOCIAL_NETWORKS = [
	'mastodon',
	'bluesky',
	'linkedin',
	'x',
	'facebook',
	'instagram',
	'threads',
]

const NETWORKS = {
	mastodon: { label: 'Mastodon', bodyLimit: 500, maxMedia: 4 },
	bluesky: { label: 'Bluesky', bodyLimit: 300, maxMedia: 4 },
	linkedin: { label: 'LinkedIn', bodyLimit: 3000, maxMedia: 1 },
	x: { label: 'X', bodyLimit: 280, maxMedia: 4 },
	facebook: { label: 'Facebook page', bodyLimit: 5000, maxMedia: 1 },
	instagram: { label: 'Instagram business', bodyLimit: 2200, maxMedia: 1 },
	threads: { label: 'Threads', bodyLimit: 500, maxMedia: 1 },
}

const STATUS_CHIPS = {
	pending: { label: 'Connecting', color: 'var(--color-text-maxcontrast)' },
	active: { label: 'Connected', color: 'var(--color-success)' },
	expired: { label: 'Expired', color: 'var(--color-warning)' },
	relink_needed: { label: 'Reconnect needed', color: 'var(--color-error)' },
	disabled: { label: 'Disabled', color: 'var(--color-text-maxcontrast)' },
	not_configured: {
		label: 'Not configured',
		color: 'var(--color-text-maxcontrast)',
	},
}

const PUBLICATION_CHIPS = {
	pending: { label: 'Waiting to go out', color: 'var(--color-text-maxcontrast)' },
	published: { label: 'Published', color: 'var(--color-success)' },
	failed: { label: 'Failed', color: 'var(--color-error)' },
	awaiting_share: {
		label: 'Waiting for the owner to share',
		color: 'var(--color-warning)',
	},
	shared: { label: 'Shared by the owner', color: 'var(--color-success)' },
	skipped: { label: 'Skipped', color: 'var(--color-text-maxcontrast)' },
}

/** The failure codes a Retry button should be offered for. */
export const RETRYABLE_FAILURES = ['rejected_by_network', 'unavailable']

/**
 * What one network accepts.
 *
 * An unknown network answers with a generous default rather than throwing:
 * the interface has to render something for a network a newer schema
 * introduced that this build has never heard of, and refusing to render is
 * worse than showing a limit that is too kind.
 *
 * @param {string} network The network name.
 * @return {{label: string, bodyLimit: number, maxMedia: number}} Its limits.
 * @spec openspec/changes/social-publishing/specs/social-posts/spec.md#requirement-one-post-carries-per-network-variants
 */
export function networkLimits(network) {
	const known = NETWORKS[network]
	if (known) {
		return { ...known }
	}
	return { label: network || 'Unknown', bodyLimit: 5000, maxMedia: 1 }
}

/**
 * The chip an account status renders.
 *
 * @param {string} status The stored `status` value.
 * @return {{label: string, color: string}} The chip.
 * @spec openspec/changes/social-publishing/specs/social-accounts/spec.md#requirement-a-network-with-no-filing-says-so-instead-of-failing-quietly
 */
export function accountStatusChip(status) {
	return { ...(STATUS_CHIPS[status] || STATUS_CHIPS.pending) }
}

/**
 * The chip a publication status renders.
 *
 * @param {string} status The stored `status` value.
 * @return {{label: string, color: string}} The chip.
 * @spec openspec/changes/social-publishing/specs/social-posts/spec.md#requirement-publishing-runs-on-a-timed-job-one-account-at-a-time
 */
export function publicationStatusChip(status) {
	return { ...(PUBLICATION_CHIPS[status] || PUBLICATION_CHIPS.pending) }
}

/**
 * Whether a failed publication is worth offering a Retry for.
 *
 * A dead grant and a missing developer application are both permanent until a
 * person does something else, so a Retry button on either is a button that
 * cannot work.
 *
 * @param {object} publication The publication row.
 * @return {boolean} True when a retry may help.
 * @spec openspec/changes/social-publishing/specs/social-posts/spec.md#requirement-publishing-runs-on-a-timed-job-one-account-at-a-time
 */
export function isRetryable(publication) {
	return (
		publication?.status === 'failed'
		&& RETRYABLE_FAILURES.includes(publication?.failureCode)
	)
}

/**
 * The engagement rate of one ranking row, as a percentage string, or a dash
 * when the account has no followers recorded.
 *
 * No followers means no rate. Showing a zero would read as "nobody engaged"
 * when what happened is that nobody counted the audience.
 *
 * @param {object} row A ranking row from `GET /api/social-performance`.
 * @return {string} The rate, or a hyphen.
 * @spec openspec/changes/social-publishing/specs/social-metrics/spec.md#requirement-posts-are-ranked-by-engagement-rate-per-network
 */
export function formatEngagementRate(row) {
	const rate = row?.engagementRate
	if (rate === null || rate === undefined || Number.isNaN(Number(rate))) {
		return '-'
	}
	return `${(Number(rate) * 100).toFixed(2)}%`
}
