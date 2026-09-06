// SPDX-License-Identifier: EUPL-1.2
// Copyright (C) 2026 Conduction B.V.

/**
 * The composer's two rules, offline.
 *
 * These are the same rules `SocialPostService::resolveVariant()` and
 * `overlongVariants()` apply on the way to a network, and the two sides are
 * asserted against the same table on purpose: a preview produced by a
 * different rule from the sending is a preview that lies. The PHP side is
 * asserted in tests/Unit/Service/SocialPostServiceTest.php.
 *
 * @spec openspec/changes/social-publishing/specs/social-posts/spec.md#requirement-one-post-carries-per-network-variants
 */

import { describe, expect, it } from 'vitest'
import {
	canSubmit,
	fitForNetwork,
	fitsForNetworks,
	resolveVariant,
} from '../../src/services/socialComposer.js'
import {
	formatEngagementRate,
	isRetryable,
	networkLimits,
	SOCIAL_NETWORKS,
} from '../../src/services/socialNetworks.js'

const post = {
	body: 'OpenRegister 3.0 is uit, met schemas die een levenscyclus dragen.',
	link: 'https://conduction.nl/nieuws/or3',
	media: [{ url: 'https://conduction.nl/i.jpg', alt: 'Schermafdruk' }],
	variants: {
		x: { body: 'OpenRegister 3.0 is uit.' },
	},
}

describe('resolveVariant', () => {
	it('merges a variant onto the post rather than replacing it', () => {
		const forX = resolveVariant(post, 'x')

		expect(forX.body).toBe('OpenRegister 3.0 is uit.')
		expect(forX.link).toBe('https://conduction.nl/nieuws/or3')
		expect(forX.media).toEqual(post.media)
	})

	it('uses the post body for a network with no variant', () => {
		expect(resolveVariant(post, 'mastodon').body).toBe(post.body)
	})

	it('lets a variant carry its own link and media', () => {
		const withOwn = {
			...post,
			variants: {
				linkedin: {
					link: 'https://conduction.nl/nieuws/or3-lang',
					media: [{ url: 'https://conduction.nl/other.jpg' }],
				},
			},
		}
		const resolved = resolveVariant(withOwn, 'linkedin')

		expect(resolved.body).toBe(post.body)
		expect(resolved.link).toBe('https://conduction.nl/nieuws/or3-lang')
		expect(resolved.media).toHaveLength(1)
		expect(resolved.media[0].url).toBe('https://conduction.nl/other.jpg')
	})

	it('survives a post with nothing on it', () => {
		expect(resolveVariant({}, 'mastodon')).toEqual({
			body: '',
			link: '',
			media: [],
		})
	})
})

describe('fitForNetwork', () => {
	it('measures the resolved body, not the post body', () => {
		const fit = fitForNetwork(post, 'x')

		expect(fit.length).toBe('OpenRegister 3.0 is uit.'.length)
		expect(fit.limit).toBe(280)
		expect(fit.fits).toBe(true)
	})

	it('reports how far over the limit a variant is', () => {
		const long = { ...post, variants: { x: { body: 'a'.repeat(400) } } }
		const fit = fitForNetwork(long, 'x')

		expect(fit.fits).toBe(false)
		expect(fit.over).toBe(120)
	})

	it('falls back to a generous limit for a network it has never heard of', () => {
		const fit = fitForNetwork(post, 'friendica')

		expect(fit.limit).toBe(5000)
		expect(fit.fits).toBe(true)
	})
})

describe('fitsForNetworks', () => {
	it('reports one fit per distinct network, in the order given', () => {
		const fits = fitsForNetworks(post, ['x', 'mastodon', 'x'])

		expect(fits.map((fit) => fit.network)).toEqual(['x', 'mastodon'])
	})
})

describe('canSubmit', () => {
	it('allows a post that fits every network it goes to', () => {
		expect(canSubmit(post, ['x', 'mastodon']).ok).toBe(true)
	})

	it('refuses a post with nothing to say', () => {
		const result = canSubmit({ ...post, body: '   ', variants: {} }, [
			'mastodon',
		])

		expect(result.ok).toBe(false)
		expect(result.problems.join(' ')).toContain('something to say')
	})

	it('refuses a post that names no accounts', () => {
		expect(canSubmit(post, []).ok).toBe(false)
	})

	it('names the network and its limit when a variant does not fit', () => {
		const long = { ...post, variants: { x: { body: 'a'.repeat(400) } } }
		const result = canSubmit(long, ['x'])

		expect(result.ok).toBe(false)
		expect(result.problems[0]).toContain('X')
		expect(result.problems[0]).toContain('280')
	})
})

describe('socialNetworks', () => {
	it('knows a limit for every network the schema enum names', () => {
		for (const network of SOCIAL_NETWORKS) {
			expect(networkLimits(network).bodyLimit).toBeGreaterThan(0)
			expect(networkLimits(network).label).not.toBe('')
		}
	})

	it('mirrors the PHP adapters, network for network', () => {
		// These are the values the matching *Adapter::bodyLimit() returns. Two
		// copies that could disagree would mean a composer that says a post
		// fits and a server that refuses it.
		expect(networkLimits('mastodon').bodyLimit).toBe(500)
		expect(networkLimits('bluesky').bodyLimit).toBe(300)
		expect(networkLimits('linkedin').bodyLimit).toBe(3000)
		expect(networkLimits('x').bodyLimit).toBe(280)
		expect(networkLimits('facebook').bodyLimit).toBe(5000)
		expect(networkLimits('instagram').bodyLimit).toBe(2200)
		expect(networkLimits('threads').bodyLimit).toBe(500)
	})

	it('offers a retry only for the failures a retry can fix', () => {
		expect(
			isRetryable({ status: 'failed', failureCode: 'rejected_by_network' }),
		).toBe(true)
		expect(isRetryable({ status: 'failed', failureCode: 'unavailable' })).toBe(
			true,
		)
		expect(isRetryable({ status: 'failed', failureCode: 'relink_needed' })).toBe(
			false,
		)
		expect(
			isRetryable({ status: 'failed', failureCode: 'not_configured' }),
		).toBe(false)
		expect(
			isRetryable({ status: 'published', failureCode: 'unavailable' }),
		).toBe(false)
	})

	it('shows no engagement rate for an account with no followers', () => {
		expect(formatEngagementRate({ engagementRate: null })).toBe('-')
		expect(formatEngagementRate({})).toBe('-')
		expect(formatEngagementRate({ engagementRate: 0.0325 })).toBe('3.25%')
	})
})
