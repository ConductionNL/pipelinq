/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 * SPDX-License-Identifier: EUPL-1.2
 *
 * A board-look list shows the columns that fit the content column at their
 * labels' own width, as the dossiq lists do (six data columns at most). The
 * columns that give way start hidden (`hidden: true`), so the Columns menu
 * still offers them; they are not removed.
 *
 * @spec openspec/specs/index-page/spec.md#requirement-a-board-look-list-starts-with-the-columns-that-fit
 */
import fs from 'node:fs'
import path from 'node:path'
import { describe, expect, it } from 'vitest'

const ROOT = path.resolve(__dirname, '../..')

/**
 * The columns of the first page of a manifest fragment.
 *
 * @param {string} file The fragment file name.
 * @return {Array<object>} The declared columns.
 */
function fragmentColumns(file) {
	const fragment = JSON.parse(fs.readFileSync(path.join(ROOT, 'src', 'manifest.d', file), 'utf8'))
	return fragment.pages[0].config.columns
}

/**
 * The keys of the columns that start visible.
 *
 * @param {Array<object|string>} columns The declared columns.
 * @return {Array<string>} The visible keys, in order.
 */
function shown(columns) {
	return columns
		.filter((column) => !(typeof column === 'object' && column.hidden === true))
		.map((column) => (typeof column === 'string' ? column : column.key))
}

describe('board-look lists show the columns that fit', () => {
	it('website submissions start without the page and the handler', () => {
		const columns = fragmentColumns('26-website-submissions.json')

		expect(shown(columns)).toEqual(['occurredAt', 'title', 'reporterName', 'reporterOrganisation', 'source', 'status'])
		expect(columns.map((column) => column.key)).toContain('sourceUrl')
		expect(columns.map((column) => column.key)).toContain('assignee')
	})

	it('contracts start without the billing period and name the number column briefly', () => {
		const columns = fragmentColumns('96-contracts.json')

		expect(shown(columns)).toEqual(['contractNumber', 'title', 'clientRef', 'status', 'valuePerInterval', 'endDate'])
		expect(columns.find((column) => column.key === 'contractNumber').label).toBe('Number')
	})

	it('export jobs start without the next run, which the schedule column already says', () => {
		const source = fs.readFileSync(path.join(ROOT, 'src', 'views', 'export', 'ExportJobs.vue'), 'utf8')

		expect(source).toMatch(/key: 'nextRunAt',\s*\n\s*hidden: true,/)
	})
})
