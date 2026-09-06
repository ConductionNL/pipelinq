// SPDX-License-Identifier: EUPL-1.2
// Copyright (C) 2026 Conduction B.V.

/**
 * The composer's two rules, as pure functions.
 *
 * THIS IS THE SAME MERGE THE SERVER DOES. `SocialPostService::resolveVariant()`
 * and `overlongVariants()` implement exactly what is below, and both sides are
 * tested against the same table. That is not duplication for its own sake: a
 * marketer needs the preview and the character count before a round trip, and
 * a preview produced by a different rule from the sending is a preview that
 * lies. The rule lives twice on purpose and is asserted twice on purpose.
 *
 * A VARIANT MERGES, IT DOES NOT REPLACE. A variant carrying only a body still
 * gets the post's link and media. A full copy per network would drift the
 * moment somebody fixed a typo in one of five places.
 *
 * @spec openspec/changes/social-publishing/specs/social-posts/spec.md#requirement-one-post-carries-per-network-variants
 */

import { networkLimits } from './socialNetworks.js'

/**
 * The body, link and media one network gets.
 *
 * @param {object} post The post being composed.
 * @param {string} network The network to resolve for.
 * @return {{body: string, link: string, media: Array<object>}} The resolved values.
 * @spec openspec/changes/social-publishing/specs/social-posts/spec.md#requirement-one-post-carries-per-network-variants
 */
export function resolveVariant(post, network) {
	const resolved = {
		body: String(post?.body || ''),
		link: String(post?.link || ''),
		media: Array.isArray(post?.media) ? post.media : [],
	}

	const variant = post?.variants?.[network]
	if (!variant || typeof variant !== 'object') {
		return resolved
	}

	for (const field of ['body', 'link']) {
		const value = String(variant[field] || '').trim()
		if (value !== '') {
			resolved[field] = value
		}
	}

	if (Array.isArray(variant.media) && variant.media.length > 0) {
		resolved.media = variant.media
	}

	return resolved
}

/**
 * How the post stands against one network's limit.
 *
 * @param {object} post The post being composed.
 * @param {string} network The network.
 * @return {{network: string, label: string, length: number, limit: number, over: number, fits: boolean}} The fit.
 * @spec openspec/changes/social-publishing/specs/social-posts/spec.md#requirement-one-post-carries-per-network-variants
 */
export function fitForNetwork(post, network) {
	const limits = networkLimits(network)
	const length = resolveVariant(post, network).body.length
	const over = Math.max(0, length - limits.bodyLimit)

	return {
		network,
		label: limits.label,
		length,
		limit: limits.bodyLimit,
		over,
		fits: over === 0,
	}
}

/**
 * The fit for every network the post goes to, in the order given.
 *
 * @param {object} post The post being composed.
 * @param {Array<string>} networks The networks the chosen accounts live on.
 * @return {Array<object>} One fit per network.
 * @spec openspec/changes/social-publishing/specs/social-posts/spec.md#requirement-one-post-carries-per-network-variants
 */
export function fitsForNetworks(post, networks) {
	const seen = []
	const out = []
	for (const network of networks || []) {
		if (seen.includes(network)) {
			continue
		}
		seen.push(network)
		out.push(fitForNetwork(post, network))
	}
	return out
}

/**
 * Whether the post may be submitted for approval, and what is wrong when it
 * may not.
 *
 * The check runs here rather than only on the server so a marketer finds out
 * while they are still typing. The server refuses the same cases; this just
 * refuses them sooner.
 *
 * @param {object} post The post being composed.
 * @param {Array<string>} networks The networks the chosen accounts live on.
 * @return {{ok: boolean, problems: Array<string>}} Whether it may be submitted.
 * @spec openspec/changes/social-publishing/specs/social-posts/spec.md#requirement-one-post-carries-per-network-variants
 */
export function canSubmit(post, networks) {
	const problems = []

	if (String(post?.body || '').trim() === '') {
		problems.push('A post needs something to say.')
	}

	if (!Array.isArray(networks) || networks.length === 0) {
		problems.push('Pick at least one account for this post to go to.')
	}

	for (const fit of fitsForNetworks(post, networks)) {
		if (fit.fits) {
			continue
		}
		problems.push(
			`The text for ${fit.label} is ${fit.length} characters and ${fit.label} accepts ${fit.limit}.`,
		)
	}

	return { ok: problems.length === 0, problems }
}
