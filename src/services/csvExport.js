/**
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * Rows as a semicolon CSV (the separator Dutch spreadsheets open), and the
 * browser download of one.
 *
 * @spec openspec/specs/pipeline/spec.md
 */

/**
 * @param {Array<object>} rows The rows.
 * @param {Array<string>} keys The columns, in order; the first line names them.
 * @return {string} The CSV text.
 * @spec openspec/specs/pipeline/spec.md
 */
export function toCsv(rows, keys) {
	const cell = (value) => {
		const text = value === null || value === undefined ? '' : String(value)
		return /[";\n]/.test(text) ? `"${text.replace(/"/g, '""')}"` : text
	}
	return [keys.join(';'), ...rows.map((row) => keys.map((key) => cell(row?.[key])).join(';'))].join('\n')
}

/**
 * Hand rows to the browser as a CSV file.
 *
 * @param {string} filename The file name.
 * @param {Array<object>} rows The rows.
 * @param {Array<string>} keys The columns.
 * @return {void}
 * @spec openspec/specs/pipeline/spec.md
 */
export function downloadCsv(filename, rows, keys) {
	const blob = new Blob(['﻿' + toCsv(rows, keys)], { type: 'text/csv;charset=utf-8' })
	const link = document.createElement('a')
	link.href = URL.createObjectURL(blob)
	link.download = filename
	link.click()
	URL.revokeObjectURL(link.href)
}
