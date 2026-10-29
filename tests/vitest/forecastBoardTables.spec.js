/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 * SPDX-License-Identifier: EUPL-1.2
 *
 * The forecast page's tables as the PqForecast board draws them: CnDataTables
 * with a sort chevron and a filter funnel on their heads, in the board look's
 * own bordered table card, sorted and filtered in the browser.
 *
 * @spec openspec/changes/forecast-table-heads/specs/forecast-table-heads/spec.md
 */

import { readFileSync } from 'node:fs'
import { resolve } from 'node:path'
import { describe, expect, it } from 'vitest'

const read = (...parts) => readFileSync(resolve(__dirname, '../..', ...parts), 'utf8')

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

describe('the forecast trend tables (PqForecast 12, 13)', () => {
	const source = read('src', 'views', 'forecast', 'ForecastTrend.vue')

	it('are CnDataTables with sortable, filterable heads, sorted and filtered in the browser', () => {
		expect(source).not.toMatch(/<table/)
		expect(source).toMatch(/<CnDataTable[\s\S]*?:columns="movementColumns"[\s\S]*?:rows="movementRows"[\s\S]*?filterable/)
		expect(source).toMatch(/<CnDataTable[\s\S]*?:columns="accuracyColumns"[\s\S]*?:rows="accuracyTableRows"[\s\S]*?filterable/)
		expect(source.match(/sortRows\(filterRows\(/g)).toHaveLength(2)
		expect(source).toMatch(/key: 'direction'[^}]*enum: \['into', 'out'\]/)
	})
})

describe('the old table style', () => {
	it('is gone with its last table', () => {
		expect(read('src', 'assets', 'app.css')).not.toContain('pq-board-table')
	})
})
