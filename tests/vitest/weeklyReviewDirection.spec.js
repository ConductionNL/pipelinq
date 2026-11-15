/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 * SPDX-License-Identifier: EUPL-1.2
 *
 * The weekly review's "What moved" lines carry an arrow for the direction of
 * their figure against the week before, as board PqWeekoverzicht draws them.
 *
 * @spec openspec/changes/marketing-integrated-campaigns/specs/marketing-integrated-campaigns/spec.md#requirement-the-weekly-review-reads-four-sources-and-names-the-ones-with-nothing-in-them
 */

import { readFileSync } from 'node:fs'
import { resolve } from 'node:path'
import { describe, expect, it } from 'vitest'
import { highlightLines } from '../../src/services/journeyLabels.js'

const view = readFileSync(resolve(__dirname, '../../src/views/marketing/WeeklyReview.vue'), 'utf8')

describe('highlight lines', () => {
	it('pairs each highlight with its direction, by position', () => {
		expect(highlightLines({ highlights: ['a', 'b', 'c'], highlightDirections: ['up', 'down', 'flat'] }))
			.toEqual([{ text: 'a', direction: 'up' }, { text: 'b', direction: 'down' }, { text: 'c', direction: 'flat' }])
	})

	it('gives a line without a known direction none, as a review stored before directions existed', () => {
		expect(highlightLines({ highlights: ['a', 'b'], highlightDirections: ['sideways'] }))
			.toEqual([{ text: 'a', direction: '' }, { text: 'b', direction: '' }])
		expect(highlightLines({ highlights: ['a'] })).toEqual([{ text: 'a', direction: '' }])
		expect(highlightLines(null)).toEqual([])
	})
})

describe('the What moved card (board PqWeekoverzicht 8-10)', () => {
	it('draws an up or down arrow before a line that rose or fell', () => {
		expect(view).toMatch(/v-for="\(line, index\) in highlights"/)
		expect(view).toMatch(/<ArrowUp\s+v-if="line\.direction === 'up'"/)
		expect(view).toMatch(/<ArrowDown\s+v-else-if="line\.direction === 'down'"/)
		expect(view).toMatch(/weekly-review__direction--up[\s\S]*?color: var\(--color-success-text/)
		expect(view).toMatch(/weekly-review__direction--down[\s\S]*?color: var\(--color-error-text/)
	})
})
