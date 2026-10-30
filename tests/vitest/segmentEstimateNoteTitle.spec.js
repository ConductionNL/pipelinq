/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 * SPDX-License-Identifier: EUPL-1.2
 *
 * PqSegmentBouwer's "Geschat aantal leden" note titles as the Dq boards title
 * a tonal note in a dialog: 14px bold on a 1.5 line, 12px after the icon
 * (DqArchiveren "Daarna alleen lezen"). Live drew it 15px at 600 on a 22.5px
 * line, 6px after the icon (COMPDIFF pl6). Read as text.
 *
 * @spec openspec/changes/segment-estimate-note-title/specs/segment-estimate-note-title/spec.md
 */
import { readFileSync } from 'node:fs'
import { resolve } from 'node:path'
import { describe, expect, it } from 'vitest'

describe('the segment estimate note title', () => {
	it('is 14px bold on a 1.5 line, 12px after the icon', () => {
		const src = readFileSync(resolve(__dirname, '../../src/components/SegmentBuilder.vue'), 'utf8')
		const style = src.slice(src.indexOf('<style'))
		const start = style.indexOf('.segment-builder__count {')
		const rule = style.slice(start, style.indexOf('}', start))
		expect(rule).toContain('gap: 12px;')
		expect(rule).toContain('font-size: 14px;')
		expect(rule).toContain('font-weight: 700;')
		expect(rule).toContain('line-height: 1.5;')
	})
})
