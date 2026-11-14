/**
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * Board PqVerzendmonitor: the five totals are the board's stat tiles, 16px
 * from the top and 18px from the sides, while the progress and events
 * cards keep their 20px 22px inset.
 *
 * @spec openspec/specs/marketing-ui/spec.md#requirement-live-send-monitor
 */
import { readFileSync } from 'fs'
import path from 'path'
import { describe, expect, it } from 'vitest'

const source = readFileSync(path.resolve(__dirname, '../../src/views/blasts/BlastMonitor.vue'), 'utf8')

/**
 * @param {string} selector A selector as written in the style block.
 * @return {string} The declarations of its first rule.
 */
function rule(selector) {
	const start = source.indexOf(`\n${selector} {`)
	expect(start).toBeGreaterThan(-1)
	return source.slice(start, source.indexOf('}', start))
}

describe('send monitor totals as stat tiles', () => {
	it('insets a total 16px 18px', () => {
		expect(rule('.blast-monitor__card.blast-monitor__total')).toMatch(/padding: 16px 18px;/)
	})

	it('keeps the cards at 20px 22px', () => {
		expect(rule('.blast-monitor__card')).toMatch(/padding: 20px 22px;/)
	})
})
