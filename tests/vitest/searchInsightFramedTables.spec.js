/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Board PqZoekinzicht draws its four tables alike (pl8 1-12): framed in the
 * card (1px edge, 8px radius, 18px outer insets), the head on the board head
 * ground, and a sort and a filter button in every data column head. The
 * search queries table was a CnDataTable without the frame or head controls;
 * the keyword, competitor and connection audit tables were the app's own
 * markup. All four are now framed CnDataTables that sort and filter the rows
 * they hold. Read as text.
 *
 * @spec openspec/changes/search-insight-framed-tables/specs/marketing/spec.md#requirement-the-search-insight-tables-are-framed-and-sort-and-filter-from-their-heads
 */
import { readFileSync } from 'node:fs'
import { resolve } from 'node:path'
import { describe, expect, it } from 'vitest'

const read = (file) => readFileSync(resolve(__dirname, '../../src/views/marketing', file), 'utf8')
const template = (source) => source.slice(source.indexOf('<template>'), source.indexOf('<script>'))
const tables = (source) => [...template(source).matchAll(/<CnDataTable([^>]*)>/g)].map((match) => match[1])

describe('the search insight tables', () => {
	it.each([
		['SearchQueries.vue', 2],
		['KeywordIntelligence.vue', 2],
		['CompetitorWatches.vue', 1],
	])('%s draws %i framed tables that sort and filter from their heads', (file, count) => {
		const found = tables(read(file))
		expect(found).toHaveLength(count)
		for (const attrs of found) {
			expect(attrs).toMatch(/\bframed\b/)
			expect(attrs).toMatch(/\bfilterable\b/)
			expect(attrs).toMatch(/\blocalHeaders\b/)
		}
	})

	it('keeps no own table markup on the search insight page', () => {
		expect(template(read('SearchQueries.vue'))).not.toMatch(/<table/)
		expect(template(read('CompetitorWatches.vue'))).not.toMatch(/<table/)
	})

	it('keeps only the per-page table of a two-pages finding as markup, on the full keywords page', () => {
		const markup = template(read('KeywordIntelligence.vue')).match(/<table/g) || []
		expect(markup).toHaveLength(1)
	})

	it('draws the heads on the board head ground, not on white', () => {
		expect(read('SearchQueries.vue')).not.toMatch(/--cn-board-table-head-bg: var\(--color-main-background\)/)
	})

	it.each([
		['SearchQueries.vue', 'columns', 0],
		['SearchQueries.vue', 'auditColumns', 0],
		['KeywordIntelligence.vue', 'strikingColumns', 1],
		['KeywordIntelligence.vue', 'gapColumns', 1],
		['CompetitorWatches.vue', 'watchColumns', 1],
	])('%s %s: the data columns sort, %i action column carries no head controls', (file, computed, actionColumns) => {
		const body = read(file).match(new RegExp(`\\t\\t${computed}\\(\\) \\{([\\s\\S]*?)\\n\\t\\t\\},`))[1]
		expect(body).toMatch(/sortable: true/)
		expect((body.match(/sortable: false, filterable: false/g) || []).length).toBe(actionColumns)
		expect((body.match(/sortable: false/g) || []).length).toBe(actionColumns)
	})
})
