/**
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * Board PqWeekoverzicht: card titles sit on a normal line (20.4px, live
 * drew 24.75), and the missing-sources notice is a plain tinted card with
 * the board's 8px corners and no warning stripe.
 *
 * @spec openspec/changes/marketing-integrated-campaigns/specs/marketing-integrated-campaigns/spec.md#requirement-the-weekly-review-reads-four-sources-and-names-the-ones-with-nothing-in-them
 */
import { readFileSync } from 'fs'
import path from 'path'
import { describe, expect, it } from 'vitest'

const source = readFileSync(path.resolve(__dirname, '../../src/views/marketing/WeeklyReview.vue'), 'utf8')

/**
 * @param {string} selector A selector as written in the style block.
 * @return {string} The declarations of its first rule.
 */
function rule(selector) {
	const start = source.indexOf(`\n${selector} {`)
	expect(start).toBeGreaterThan(-1)
	return source.slice(start, source.indexOf('}', start))
}

describe('weekly review cards as the board', () => {
	it('sets card titles on a normal line', () => {
		expect(rule('.weekly-review__card h3')).toMatch(/line-height: normal;/)
	})

	it('draws the notice as a tinted card without the stripe', () => {
		expect(source).toMatch(/<NcNoteCard\s+v-if="degraded[^"]*"\s+class="weekly-review__notice"/)
		const notice = rule('.weekly-review__notice.notecard')
		expect(notice).toMatch(/border-inline-start: 0;/)
		expect(notice).toMatch(/border-radius: 8px;/)
		expect(notice).toMatch(/background: var\(--cn-board-notice-bg/)
	})
})
