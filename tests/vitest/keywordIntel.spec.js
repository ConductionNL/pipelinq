// SPDX-License-Identifier: EUPL-1.2
// Copyright (C) 2026 Conduction B.V.

/**
 * Unit tests for src/services/keywordIntel.js, the pure helpers behind the
 * Keywords page (marketing-search-intelligence, phase 5).
 *
 * Two of these matter more than the formatting. `confirmPayload` has to refuse
 * a status or a proposal kind the server's vocabulary does not admit, because
 * the server rejects one and the page would report only that saving failed.
 * And `crawlNotice` is what keeps "we did not look" apart from "there are no
 * gaps", which is the most confident wrong answer this phase could give.
 */

import { describe, expect, it } from 'vitest'
import {
	confirmPayload,
	crawlNotice,
	isConfirmed,
	pageShare,
	percent,
	PROPOSAL_KINDS,
	shortfall,
	TARGET_STATUSES,
} from '../../src/services/keywordIntel.js'

describe('percent', () => {
	it('renders a ratio with one decimal', () => {
		expect(percent(0.0353)).toBe('3.5%')
		expect(percent(1)).toBe('100.0%')
	})

	it('treats an absent value as zero rather than NaN', () => {
		expect(percent(null)).toBe('0.0%')
		expect(percent(undefined)).toBe('0.0%')
	})
})

describe('shortfall', () => {
	it('renders the gap between expected and actual click-through', () => {
		expect(shortfall({ shortfall: 0.021 })).toBe('2.1%')
	})

	it('never shows a negative gap', () => {
		expect(shortfall({ shortfall: -0.01 })).toBe('0.0%')
		expect(shortfall({})).toBe('0.0%')
	})
})

describe('pageShare', () => {
	it('renders a page share of the query impressions', () => {
		expect(pageShare({ impressions: 200 }, 1000)).toBe('20.0%')
	})

	it('answers a dash rather than dividing by zero', () => {
		expect(pageShare({ impressions: 5 }, 0)).toBe('-')
		expect(pageShare({ impressions: 5 }, null)).toBe('-')
	})
})

describe('isConfirmed', () => {
	it('matches a term whatever its case or padding', () => {
		expect(isConfirmed('  Woo Verzoek ', ['woo verzoek'])).toBe(true)
	})

	it('does not match a different term', () => {
		expect(isConfirmed('woo verzoek', ['subsidie'])).toBe(false)
	})

	it('treats an empty term as unconfirmed', () => {
		expect(isConfirmed('', ['woo verzoek'])).toBe(false)
		expect(isConfirmed('woo', undefined)).toBe(false)
	})
})

describe('confirmPayload', () => {
	it('carries the term, the chosen status and the section kind', () => {
		const body = confirmPayload(
			{ query: 'woo verzoek indienen', topPage: 'https://example.org/woo' },
			{ status: 'use-more', notes: 'Positie elf.' },
			'striking-distance',
		)

		expect(body.term).toBe('woo verzoek indienen')
		expect(body.status).toBe('use-more')
		expect(body.proposalKind).toBe('striking-distance')
		expect(body.targetPageRef).toBe('https://example.org/woo')
		expect(body.notes).toBe('Positie elf.')
	})

	it('falls back to a status the server admits', () => {
		const body = confirmPayload(
			{ query: 'x' },
			{ status: 'gebruik-meer' },
			'content-gap',
		)

		expect(TARGET_STATUSES).toContain(body.status)
		expect(body.status).toBe('watch')
	})

	it('falls back to a proposal kind the server admits', () => {
		const body = confirmPayload({ query: 'x' }, {}, 'guessed')

		expect(PROPOSAL_KINDS).toContain(body.proposalKind)
		expect(body.proposalKind).toBe('manual')
	})

	it('never sends volume or difficulty, which nothing measures', () => {
		const body = confirmPayload({ query: 'x' }, {}, 'manual')

		expect(body).not.toHaveProperty('volume')
		expect(body).not.toHaveProperty('difficulty')
	})

	it('trims the term so a padded proposal cannot mint a second target', () => {
		expect(confirmPayload({ query: '  woo  ' }, {}, 'manual').term).toBe('woo')
	})
})

describe('crawlNotice', () => {
	it('is empty when the crawl ran', () => {
		expect(crawlNotice({ crawled: true, reason: '' })).toBe('')
	})

	it('carries the reason when it did not, so an empty list is not read as no gaps', () => {
		expect(
			crawlNotice({
				crawled: false,
				reason: 'No crawl source is configured, so the content gap check did not run.',
			}),
		).toContain('did not run')
	})

	it('survives a missing crawl block', () => {
		expect(crawlNotice(undefined)).toBe('')
		expect(crawlNotice({ crawled: false })).toBe('')
	})
})
