// SPDX-License-Identifier: EUPL-1.2
// Copyright (C) 2026 Conduction B.V.

/**
 * Unit tests for src/services/articleStatus.js — the status chip vocabulary,
 * the legal lifecycle transitions and the usage grouping the ArticleDetail
 * page sections share.
 *
 * The unknown-status case matters most, the same way it does for
 * subscriptionState.js: a status this build has never seen must never offer
 * a transition the server would refuse.
 */

import { describe, expect, it } from 'vitest'
import {
	ARTICLE_STATUSES,
	chipForStatus,
	groupUsages,
	transitionsForStatus,
	USAGE_KINDS,
} from '../../src/services/articleStatus.js'

describe('chipForStatus', () => {
	it('gives every declared status a label and a themed colour', () => {
		for (const status of ARTICLE_STATUSES) {
			const chip = chipForStatus(status)
			expect(chip.label).toBeTruthy()
			expect(chip.color).toMatch(/^var\(--color-/)
		}
	})

	it('falls back to an Unknown chip for a status it has never seen', () => {
		const chip = chipForStatus('retracted')
		expect(chip.label).toBe('Unknown')
	})

	it('falls back for a missing status rather than throwing', () => {
		expect(chipForStatus(undefined).label).toBe('Unknown')
		expect(chipForStatus(null).label).toBe('Unknown')
		expect(chipForStatus('').label).toBe('Unknown')
	})

	it('returns a copy, so a caller cannot edit the shared vocabulary', () => {
		const first = chipForStatus('draft')
		first.label = 'Tampered'
		expect(chipForStatus('draft').label).toBe('Draft')
	})
})

describe('transitionsForStatus', () => {
	it('offers submit-for-review and publish from draft', () => {
		const ids = transitionsForStatus('draft').map((t) => t.id)
		expect(ids).toEqual(['submitForReview', 'publish'])
	})

	it('offers return-to-draft and publish from review', () => {
		const ids = transitionsForStatus('review').map((t) => t.id)
		expect(ids).toEqual(['returnToDraft', 'publish'])
	})

	it('offers only archive from published', () => {
		const ids = transitionsForStatus('published').map((t) => t.id)
		expect(ids).toEqual(['archive'])
	})

	it('offers only restore from archived', () => {
		const ids = transitionsForStatus('archived').map((t) => t.id)
		expect(ids).toEqual(['restore'])
	})

	it('routes publish and archive to their own stamping endpoints, everything else to transition', () => {
		const draft = transitionsForStatus('draft')
		expect(draft.find((t) => t.id === 'publish').endpoint).toBe('publish')
		expect(draft.find((t) => t.id === 'submitForReview').endpoint).toBe(
			'transition',
		)
		expect(
			transitionsForStatus('published').find((t) => t.id === 'archive')
				.endpoint,
		).toBe('archive')
		expect(
			transitionsForStatus('archived').find((t) => t.id === 'restore')
				.endpoint,
		).toBe('transition')
	})

	it('offers no transition for a status it has never seen', () => {
		expect(transitionsForStatus('retracted')).toEqual([])
		expect(transitionsForStatus(undefined)).toEqual([])
	})

	it('returns copies, so a caller cannot edit the shared vocabulary', () => {
		const first = transitionsForStatus('draft')
		first[0].label = 'Tampered'
		expect(transitionsForStatus('draft')[0].label).toBe('Submit for review')
	})
})

describe('groupUsages', () => {
	it('reports every declared kind even when there are no usages', () => {
		const groups = groupUsages([])
		expect(groups.map((g) => g.kind)).toEqual(USAGE_KINDS)
		for (const group of groups) {
			expect(group.items).toEqual([])
		}
	})

	it('splits a mixed list of usages into their kind', () => {
		const groups = groupUsages([
			{ kind: 'template', id: 't1', name: 'Nieuwsbrief' },
			{ kind: 'blast', id: 'b1', name: 'Augustus-mailing' },
			{ kind: 'blast', id: 'b2', name: 'September-mailing' },
		])
		const templates = groups.find((g) => g.kind === 'template')
		const blasts = groups.find((g) => g.kind === 'blast')
		expect(templates.items).toHaveLength(1)
		expect(blasts.items).toHaveLength(2)
	})

	it('survives a non-array', () => {
		expect(groupUsages(null).every((g) => g.items.length === 0)).toBe(true)
		expect(groupUsages(undefined).every((g) => g.items.length === 0)).toBe(true)
	})
})
