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
import {
	commitMovements,
	isoWeekOf,
	latestPerOwner,
	sumSnapshotsByDate,
} from '../../src/services/forecastMath.js'

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
			{ date: '2026-10-05', commit: 1, best_case: 2, pipeline: 3, closed_won: 0 },
			{ date: '2026-10-12', commit: 15, best_case: 20, pipeline: 35, closed_won: 0 },
		])
	})

	it('answers an empty list with no points', () => {
		expect(sumSnapshotsByDate([])).toEqual([])
	})
})

describe('commitMovements', () => {
	it('names each seller whose commit moved between the two newest snapshots, largest first', () => {
		const moves = commitMovements([
			{ owner_id: 'fatma', as_of_date: '2026-09-28', commit: 22000 },
			{ owner_id: 'fatma', as_of_date: '2026-10-05', commit: 70000 },
			{ owner_id: 'joris', as_of_date: '2026-09-28', commit: 25500 },
			{ owner_id: 'joris', as_of_date: '2026-10-05', commit: 18000 },
			{ owner_id: 'pieter', as_of_date: '2026-09-28', commit: 48000 },
			{ owner_id: 'pieter', as_of_date: '2026-10-05', commit: 48000 },
			{ owner_id: 'pieter', as_of_date: '2026-09-21', commit: 1 },
		])
		expect(moves).toEqual([
			{ owner_id: 'fatma', direction: 'into', amount: 48000 },
			{ owner_id: 'joris', direction: 'out', amount: 7500 },
		])
	})

	it('has no moves with a single snapshot date', () => {
		expect(commitMovements([{ owner_id: 'a', as_of_date: '2026-10-05', commit: 1 }])).toEqual([])
	})
})

describe('isoWeekOf', () => {
	it('numbers the weeks as the board labels its bars', () => {
		expect(isoWeekOf('2026-10-05')).toBe(41)
		expect(isoWeekOf('2026-08-31')).toBe(36)
		expect(isoWeekOf('2027-01-01')).toBe(53)
		expect(isoWeekOf('nonsense')).toBe('')
	})
})

describe('latestPerOwner', () => {
	it('keeps the newest snapshot of each owner', () => {
		const rows = latestPerOwner([
			{ owner_id: 'a', as_of_date: '2026-10-05', commit: 1 },
			{ owner_id: 'b', as_of_date: '2026-10-05', commit: 2 },
			{ owner_id: 'a', as_of_date: '2026-10-12', commit: 3 },
		])
		expect(rows.map((r) => r.commit)).toEqual([3, 2])
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

	it('puts Export CSV in the quarter card\'s header, in the slot CnDetailCard draws', () => {
		expect(dashboard).toMatch(/<template #actions>\s*<NcButton[^>]*data-testid="forecast-export-csv"/)
		expect(dashboard).not.toMatch(/#header-actions/)
	})

	it('names each owner, and marks an overridden commit with a pill, the seller\'s figure and a note', () => {
		expect(dashboard).toMatch(/data-testid="forecast-owner">\{\{ ownerName\(row\) \}\}/)
		expect(dashboard).toMatch(/fetchUserDisplayName/)
		expect(dashboard).toMatch(/v-if="hasOverride\(row\)"[\s\S]*?data-testid="forecast-overridden"/)
		expect(dashboard).toMatch(/v-else[\s\S]*?@click="openOverride\(row\)"/)
		expect(dashboard).toMatch(/'was \{amount\}'/)
		expect(dashboard).toMatch(/data-testid="forecast-override-note"/)
	})

	it('says on the header line when the snapshot was taken', () => {
		expect(dashboard).toMatch(/'snapshot of \{date\}'/)
	})

	it('draws the trend as one bar per week, won plus committed, as PqForecast', () => {
		const trend = view('ForecastTrend')
		expect(trend).toMatch(/data-testid="forecast-trend-bars"/)
		expect(trend).toMatch(/Number\(point\.closed_won \|\| 0\) \+ Number\(point\.commit \|\| 0\)/)
		expect(trend).not.toMatch(/<polyline/)
	})

	it('lists the week\'s commit moves per seller as a table with pills', () => {
		const trend = view('ForecastTrend')
		expect(trend).toMatch(/data-testid="forecast-movements"/)
		expect(trend).toMatch(/forecast-trend__move--' \+ move\.direction/)
	})

	it('says what the chart and the accuracy cover', () => {
		const trend = view('ForecastTrend')
		expect(trend).toMatch(/:subtitle="t\('pipelinq', 'Weekly snapshots, taken every Monday'\)"/)
		expect(trend).toMatch(/:subtitle="accuracySubtitle"/)
		expect(trend).toMatch(/'Quarter \{quarter\}, closed'/)
	})

	it('keeps the standalone trend page with its owner field', () => {
		expect(view('ForecastTrend')).toMatch(/v-if="!embedded"/)
	})
})
