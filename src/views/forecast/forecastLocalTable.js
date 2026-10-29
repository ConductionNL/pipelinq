/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Sorting and filtering of the forecast tables in the browser (board
 * PqForecast): the rows are already on the page, so CnDataTable's header
 * sort and header filters act on them here. A filter arrives as the query
 * keys the header filters speak: `key[like]` (contains), `key[gte]` and
 * `key[lte]` (a range), or `key` with a list of values.
 *
 * @module views/forecast/forecastLocalTable
 */

const OPERATOR = /^(.+)\[(like|gte|lte)\]$/

/**
 * Merge one header filter's parameters into the active map; an empty list
 * removes that key.
 *
 * @param {object} active The active map, `{ paramKey: values }`.
 * @param {object} params The filter's parameters, as CnDataTable emits them.
 * @return {object} A new active map.
 * @spec openspec/changes/forecast-table-heads/specs/forecast-table-heads/spec.md#requirement-the-forecast-tables-sort-and-filter-their-rows
 */
export function applyFilterParams(active, params) {
	const next = { ...(active || {}) }
	for (const [key, values] of Object.entries(params || {})) {
		if (Array.isArray(values) && values.length > 0) {
			next[key] = values
		} else {
			delete next[key]
		}
	}
	return next
}

/**
 * The rows that pass every active filter.
 *
 * @param {Array<object>} rows The rows.
 * @param {object} active The active map, `{ paramKey: values }`.
 * @return {Array<object>} The rows that pass.
 * @spec openspec/changes/forecast-table-heads/specs/forecast-table-heads/spec.md#requirement-the-forecast-tables-sort-and-filter-their-rows
 */
export function filterRows(rows, active) {
	const tests = Object.entries(active || {}).map(([param, values]) => {
		const match = OPERATOR.exec(param)
		const key = match ? match[1] : param
		const operator = match ? match[2] : 'in'
		const wanted = values[0]
		if (operator === 'like') {
			const needle = String(wanted).toLowerCase()
			return (row) => String(row[key] ?? '').toLowerCase().includes(needle)
		}
		if (operator === 'gte') {
			return (row) => Number(row[key]) >= Number(wanted)
		}
		if (operator === 'lte') {
			return (row) => Number(row[key]) <= Number(wanted)
		}
		const allowed = values.map(String)
		return (row) => allowed.includes(String(row[key]))
	})
	return (rows || []).filter((row) => tests.every((test) => test(row)))
}

/**
 * The rows in the order of one column, numbers by value and text by the
 * locale; without a key, as given. The input is not changed.
 *
 * @param {Array<object>} rows The rows.
 * @param {string|null} key The column to sort on.
 * @param {string|null} order `asc` or `desc`.
 * @return {Array<object>} The sorted copy.
 * @spec openspec/changes/forecast-table-heads/specs/forecast-table-heads/spec.md#requirement-the-forecast-tables-sort-and-filter-their-rows
 */
export function sortRows(rows, key, order) {
	const list = [...(rows || [])]
	if (!key) {
		return list
	}
	const direction = order === 'desc' ? -1 : 1
	return list.sort((a, b) => {
		const x = a[key]
		const y = b[key]
		if (typeof x === 'number' && typeof y === 'number') {
			return (x - y) * direction
		}
		return String(x ?? '').localeCompare(String(y ?? '')) * direction
	})
}
