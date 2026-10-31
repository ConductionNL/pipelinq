/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 * SPDX-License-Identifier: EUPL-1.2
 *
 * The app's own cards and tables draw the board's card title and card table
 * head, as the Dq boards and the library widgets do (COMPDIFF pl6):
 * - a card title 17px at 700 on the font's normal line (20.4px), not the
 *   body's 1.5 (25.5px): PqZoekinzicht's four cards, PqVerzendmonitor
 *   "Recente gebeurtenissen" (which was also 600);
 * - a table head 14px/600 in the muted ink on the board head ground with no
 *   rule under it: the theme's bare `th` rule drew PqZoekinzicht's and
 *   PqZRapport's heads on #edeff2, 15px, some bold;
 * - PqVerkoopoverzicht's three row lists rule above their first row
 *   ("Sluit binnenkort", "Recent gewonnen / verloren", "Passende prospects").
 * Read as text.
 *
 * @spec openspec/changes/board-card-and-table-heads/specs/board-card-and-table-heads/spec.md
 */
import { readFileSync } from 'node:fs'
import { resolve } from 'node:path'
import { describe, expect, it } from 'vitest'

const read = (file) => readFileSync(resolve(__dirname, '../../src', file), 'utf8')

/**
 * The body of the first rule in a file's style block that opens with this
 * selector line (nested SCSS rules included).
 *
 * @param {string} file The SFC under src.
 * @param {string} opener The rule's opening, e.g. `.x th {`.
 * @param {number} from Start looking at the n-th occurrence (0-based).
 * @return {string} The rule body, or ''.
 */
function ruleOf(file, opener, from = 0) {
	const src = read(file)
	const style = src.slice(src.indexOf('<style'))
	let start = -1
	for (let i = 0; i <= from; i++) {
		start = style.indexOf(opener, start + 1)
		if (start === -1) {
			return ''
		}
	}
	return style.slice(start, style.indexOf('}', start))
}

const GROUND = 'background: var(--cn-board-table-head-bg'

describe('board card titles', () => {
	it('PqZoekinzicht card titles sit on the normal line', () => {
		const rule = ruleOf('views/marketing/SearchQueries.vue', '.search-queries__card-title {')
		expect(rule).toContain('font-weight: 700;')
		expect(rule).toContain('line-height: normal;')
	})

	it('PqVerzendmonitor "Recent events" is 17px at 700 on the normal line', () => {
		const rule = ruleOf('views/blasts/BlastMonitor.vue', '\th3 {')
		expect(rule).toContain('font-size: 17px;')
		expect(rule).toContain('font-weight: 700;')
		expect(rule).toContain('line-height: normal;')
	})
})

describe('board card table heads', () => {
	const heads = [
		['views/marketing/SearchQueries.vue', '\tth {'],
		['views/marketing/CompetitorWatches.vue', '\t\tth {'],
		['views/marketing/KeywordIntelligence.vue', '\tth {'],
		['components/pos/ZReportBookkeepingWidget.vue', '.z-bookkeeping__table th {'],
	]
	for (const [file, opener] of heads) {
		it(`${file} draws the head 600 in the muted ink on the board ground, no rule`, () => {
			const rule = ruleOf(file, opener)
			expect(rule).toContain(GROUND)
			expect(rule).toContain('font-weight: 600;')
			expect(rule).toContain('color: var(--color-text-maxcontrast);')
			expect(rule).not.toContain('border-bottom: 1px')
		})
	}
})

describe('PqVerkoopoverzicht row lists', () => {
	it('rule above the first row of the three lists the board rules', () => {
		const manifest = JSON.parse(read('manifest.json'))
		const widgets = manifest.pages.flatMap((page) => (page.config && page.config.widgets) || [])
		for (const title of ['Closing soon', 'Recent won / lost', 'Matching prospects']) {
			const widget = widgets.find((w) => w.title === title && w.type === 'record-list')
			expect(widget, title).toBeTruthy()
			expect(widget.content.ruleAbove, title).toBe(true)
		}
	})
})
