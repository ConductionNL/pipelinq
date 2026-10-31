// SPDX-License-Identifier: EUPL-1.2
// Copyright (C) 2026 Conduction B.V.

/**
 * A count as a whole percentage of another number on the same row
 * (`"formatter": "share", "formatterOptions": { "over": "totals.delivered" }`):
 * 71 opened over 119 delivered reads "60%". Empty when either number is
 * missing or the base is zero, since 0% would claim a measurement.
 *
 * @param {unknown} value The count.
 * @param {object} row The row.
 * @param {object} _property The schema property.
 * @param {{over?: string}} options The dot path of the base on the row.
 * @return {string} The percentage, or ''.
 * @spec openspec/specs/marketing-analytics/spec.md#requirement-overview-metrics-table
 */
export function share(value, row, _property, options) {
	const path = options?.over || ''
	const base = path.split('.').reduce((node, key) => (node && typeof node === 'object' ? node[key] : undefined), row)
	const count = Number(value)
	const total = Number(base)
	if (value === null || value === undefined || value === '' || !Number.isFinite(count) || !Number.isFinite(total) || total === 0) {
		return ''
	}
	return `${Math.round((count / total) * 100)}%`
}
