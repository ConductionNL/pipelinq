/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 * SPDX-License-Identifier: EUPL-1.2
 *
 * The forecast page's tables as the PqForecast board draws them: each one in
 * a bordered card, the head 14px/600 in the muted ink on the card's white,
 * 18px at the row ends, 14px cells and the figures right-aligned. The look is
 * one shared app style (`pq-board-table`), so a theme's plain `th` rule does
 * not repaint the head.
 *
 * @spec openspec/changes/review-finish/specs/commercial-dashboard/spec.md
 */

import { readFileSync } from 'node:fs'
import { resolve } from 'node:path'
import { describe, expect, it } from 'vitest'

const read = (...parts) => readFileSync(resolve(__dirname, '../..', ...parts), 'utf8')
const appCss = read('src', 'assets', 'app.css')

/**
 * The `<table …>` opening tags of a view, each with what precedes it.
 *
 * @param {string} name The forecast view's file name.
 * @return {Array<{tag: string, before: string}>} One entry per table.
 */
function tables(name) {
	const source = read('src', 'views', 'forecast', `${name}.vue`)
	return [...source.matchAll(/<table[^>]*>/g)].map((match) => ({
		tag: match[0],
		before: source.slice(Math.max(0, match.index - 200), match.index),
		body: source.slice(match.index, source.indexOf('</table>', match.index)),
	}))
}

/**
 * The declarations of one rule in app.css.
 *
 * @param {string} selector The rule's selector, as written.
 * @return {string} The declarations, or an empty string.
 */
function rule(selector) {
	const at = appCss.indexOf(`${selector} {`)
	return at === -1 ? '' : appCss.slice(at, appCss.indexOf('}', at))
}

describe('the forecast quarter table (PqForecast 9)', () => {
	const source = read('src', 'views', 'forecast', 'ForecastDashboard.vue')

	it('is a CnDataTable with sortable, filterable heads, sorted and filtered in the browser', () => {
		expect(source).not.toMatch(/<table/)
		expect(source).toMatch(/<CnDataTable[\s\S]*?:columns="quarterColumns"[\s\S]*?:rows="quarterRows"[\s\S]*?filterable[\s\S]*?@sort="onQuarterSort"[\s\S]*?@columnFilter="onQuarterFilter"/)
		expect(source).toMatch(/sortRows\(filterRows\(/)
		expect(source).toMatch(/key: 'name'[^}]*sortable: true/)
		expect(source).toMatch(/const figure = \(key, label\) => \(\{ key, label, type: 'number', sortable: true/)
		for (const key of ['commit', 'best_case', 'pipeline', 'closed_won', 'quota']) {
			expect(source, key).toContain(`figure('${key}'`)
		}
		expect(source).toMatch(/key: 'override'[^}]*sortable: false, filterable: false/)
	})
})

describe('the forecast tables', () => {
	for (const name of ['ForecastTrend']) {
		it(`${name}: every table is a board table inside a bordered card`, () => {
			const found = tables(name)
			expect(found.length).toBeGreaterThan(0)
			for (const table of found) {
				expect(table.tag).toContain('pq-board-table')
				expect(table.before).toMatch(/class="pq-board-table-card[ "]/)
			}
		})

		it(`${name}: the figure columns are right-aligned`, () => {
			for (const table of tables(name)) {
				expect(table.body).toContain('pq-board-table__num')
			}
		})
	}

	it('draws the head and cells as the board, above a theme th rule', () => {
		const head = rule('table.pq-board-table th')
		expect(head).toMatch(/font-size:\s*14px/)
		expect(head).toMatch(/font-weight:\s*600/)
		expect(head).toMatch(/color:\s*var\(--color-text-maxcontrast\)/)
		expect(head).toMatch(/background(-color)?:\s*var\(--color-main-background\)/)
		expect(rule('table.pq-board-table td')).toMatch(/padding:\s*12px/)
		expect(appCss).toMatch(/table\.pq-board-table :is\(th, td\):first-child \{\s*padding-inline-start:\s*18px/)
		expect(rule('table.pq-board-table .pq-board-table__num')).toMatch(/text-align:\s*end/)
		expect(rule('.pq-board-table-card')).toMatch(/border:\s*1px solid var\(--color-border\)/)
	})
})
