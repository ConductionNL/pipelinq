/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 * SPDX-License-Identifier: EUPL-1.2
 *
 * The forecast page as the PqForecast board draws it: the level as a
 * segmented control, four KPI tiles, the quarter card and the trend of the
 * level shown on the same page.
 *
 * @spec openspec/changes/review-finish/specs/commercial-dashboard/spec.md
 */

import { readFileSync } from 'node:fs'
import { resolve } from 'node:path'
import { describe, expect, it } from 'vitest'
import { sumSnapshotsByDate } from '../../src/services/forecastMath.js'

function view(name) {
	return readFileSync(
		resolve(__dirname, `../../src/views/forecast/${name}.vue`),
		'utf8',
	)
}

describe('sumSnapshotsByDate', () => {
	it('adds up every owner per snapshot date, oldest first', () => {
		const points = sumSnapshotsByDate([
			{ as_of_date: '2026-10-12', commit: 10, best_case: 20, pipeline: 30 },
			{ as_of_date: '2026-10-05', commit: 1, best_case: 2, pipeline: 3 },
			{ as_of_date: '2026-10-12', commit: '5', best_case: null, pipeline: 5 },
		])
		expect(points).toEqual([
			{ date: '2026-10-05', commit: 1, best_case: 2, pipeline: 3 },
			{ date: '2026-10-12', commit: 15, best_case: 20, pipeline: 35 },
		])
	})

	it('answers an empty list with no points', () => {
		expect(sumSnapshotsByDate([])).toEqual([])
	})
})

describe('forecast page', () => {
	const dashboard = view('ForecastDashboard')

	it('picks the level in a segmented control, seller first', () => {
		expect(dashboard).toMatch(/<CnSegmentedControl/)
		expect(dashboard).toMatch(/value: 'rep', label: t\('pipelinq', 'Seller'\)/)
	})

	it('draws the quarter card and the trend of the level shown', () => {
		expect(dashboard).toMatch(/:title="quarterTitle"/)
		expect(dashboard).toMatch(/<ForecastTrend embedded :level="childLevel"/)
	})

	it('keeps the standalone trend page with its owner field', () => {
		expect(view('ForecastTrend')).toMatch(/v-if="!embedded"/)
	})
})
