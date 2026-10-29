/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Board PqForecast draws the forecast tables with sortable and filterable
 * heads. The rows are already on the page (one snapshot per owner), so the
 * tables sort and filter them in the browser, on the query keys the header
 * filters speak (`name[like]`, `commit[gte]`, `commit[lte]`).
 */
import { describe, expect, it } from 'vitest'
import { applyFilterParams, filterRows, sortRows } from '../../src/views/forecast/forecastLocalTable.js'

const rows = [
	{ id: 'a', name: 'Pieter Jansen', commit: 48000 },
	{ id: 'b', name: 'Fatma Yildiz', commit: 30000 },
	{ id: 'c', name: 'Joris Bakker', commit: 18000 },
]

describe('the forecast tables, sorted and filtered in the browser', () => {
	it('keeps the order without a sort key, and sorts text and figures both ways', () => {
		expect(sortRows(rows, null, null).map((r) => r.id)).toEqual(['a', 'b', 'c'])
		expect(sortRows(rows, 'name', 'asc').map((r) => r.id)).toEqual(['b', 'c', 'a'])
		expect(sortRows(rows, 'commit', 'asc').map((r) => r.id)).toEqual(['c', 'b', 'a'])
		expect(sortRows(rows, 'commit', 'desc').map((r) => r.id)).toEqual(['a', 'b', 'c'])
		expect(rows.map((r) => r.id)).toEqual(['a', 'b', 'c'])
	})

	it('merges a header filter into the active map, and an empty list clears it', () => {
		const active = applyFilterParams({}, { 'name[like]': ['jo'] })
		expect(active).toEqual({ 'name[like]': ['jo'] })
		expect(applyFilterParams(active, { 'name[like]': [] })).toEqual({})
	})

	it('filters on contains, case-insensitive, and on a figure range', () => {
		expect(filterRows(rows, { 'name[like]': ['JA'] }).map((r) => r.id)).toEqual(['a'])
		expect(filterRows(rows, { 'commit[gte]': ['20000'] }).map((r) => r.id)).toEqual(['a', 'b'])
		expect(filterRows(rows, { 'commit[gte]': ['20000'], 'commit[lte]': ['40000'] }).map((r) => r.id)).toEqual(['b'])
		expect(filterRows(rows, {}).length).toBe(3)
	})
})
