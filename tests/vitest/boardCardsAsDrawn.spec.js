/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Cards that drew off the board: the loyalty tiers card ran its bars to the
 * card's edge with a rule above the table (the sections widget reads
 * `dividers: false`, not `plain`); the Z-report's side stats printed their
 * label under the card title a second time; the pipeline column's total sat
 * 11px under the stage name, a button with the server's 34px height.
 *
 * @spec openspec/specs/pipeline/spec.md
 */
import { readFileSync } from 'node:fs'
import { resolve } from 'node:path'
import { describe, expect, it } from 'vitest'

const read = (path) => readFileSync(resolve(__dirname, '../..', path), 'utf8')
const manifest = JSON.parse(read('src/manifest.json'))
const loyalty = JSON.parse(read('src/manifest.d/70-loyalty-program.json'))
const board = read('src/views/pipeline/PipelineBoard.vue')

function widgets (fragment) {
	return fragment.pages.flatMap((page) => page.config?.widgets ?? [])
}

function rule (selector) {
	const start = board.indexOf(`${selector} {`)
	return start < 0 ? '' : board.slice(start, board.indexOf('}', start))
}

describe('the loyalty tiers card', () => {
	const tiers = widgets(loyalty).find((widget) => widget.id === 'loyalty-tiers')

	it('draws its bars and table as plain sections 14px apart', () => {
		expect(tiers.content.dividers).toBe(false)
		expect(tiers.content.gap).toBe(14)
		expect(tiers.content).not.toHaveProperty('plain')
	})
})

describe('the Z-report side stats', () => {
	const page = manifest.pages.find((p) => p.id === 'ZReportDetail')
	const stats = page.config.widgets.filter((widget) => page.config.sideColumn.includes(widget.id) && widget.type === 'stat')

	it('are three cards whose title is the only label', () => {
		expect(stats.map((widget) => widget.id)).toEqual(['z-day-total', 'z-day-tax', 'z-day-count'])
		for (const widget of stats) {
			expect(widget.title).toBeTruthy()
			expect(widget.content).not.toHaveProperty('label')
		}
	})
})

describe('the pipeline column header in the board look', () => {
	it('starts the stage name on the column padding, the dot 10px and 8px from it', () => {
		expect(rule('.cn-look-board .kanban-column__header')).toMatch(/padding: 0;/)
	})

	it('sets the total 2px under the name, without the server button height and margin', () => {
		const value = rule('.cn-look-board .column-value.column-value')
		expect(value).toMatch(/min-height: 0;/)
		expect(value).toMatch(/margin: 0;/)
		expect(value).toMatch(/line-height: normal;/)
	})
})
