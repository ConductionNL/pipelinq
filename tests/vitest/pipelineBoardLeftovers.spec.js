/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 * SPDX-License-Identifier: EUPL-1.2
 *
 * The pipeline board as board PqPipeline draws it, past the columns: the
 * header row 4px under a 24px content top with 40px buttons and the count
 * line 6px under that row, the view chips without a band, the settings link
 * as a bare link, the stage heading on a normal line with its count pill and
 * total line, and the owner's 26px chip in the light primary.
 *
 * @spec openspec/changes/pipeline-board-leftovers/specs/pipeline/spec.md
 */
import { readFileSync } from 'node:fs'
import { resolve } from 'node:path'
import { describe, expect, it } from 'vitest'

const board = readFileSync(resolve(__dirname, '../../src/views/pipeline/PipelineBoard.vue'), 'utf8')
const card = readFileSync(resolve(__dirname, '../../src/views/pipeline/PipelineCard.vue'), 'utf8')
const nl = JSON.parse(readFileSync(resolve(__dirname, '../../l10n/nl.json'), 'utf8')).translations

/**
 * @param {string} source The SFC text.
 * @param {string} selector The rule's selector, as written.
 * @return {string} The rule's body, or '' when absent.
 */
function rule(source, selector) {
	const start = source.indexOf(`${selector} {`)
	return start < 0 ? '' : source.slice(start, source.indexOf('}', start))
}

describe('the pipeline board leftovers in the board look', () => {
	it('starts the header 4px under a 24px content top', () => {
		expect(rule(board, '.cn-look-board.pipeline-board')).toMatch(/padding-top: 24px;/)
		const header = rule(board, '.cn-look-board.pipeline-board > .cn-page-header')
		expect(header).toMatch(/display: grid;/)
		expect(header).toMatch(/row-gap: 6px;/)
		expect(header).toMatch(/column-gap: 10px;/)
		expect(header).toMatch(/padding: 4px 0 8px;/)
		expect(header).toMatch(/margin-bottom: 20px;/)
	})

	it('puts the buttons on the title row at 40px and the count line under the row', () => {
		expect(rule(board, '.cn-look-board.pipeline-board > .cn-page-header > .button-vue')).toMatch(/height: var\(--cn-board-control-height, 40px\) !important;/)
		expect(rule(board, '.cn-look-board.pipeline-board > .cn-page-header :deep(.cn-page-header__description)')).toMatch(/grid-row: 2;/)
		expect(rule(board, '.cn-look-board.pipeline-board > .cn-page-header :deep(.cn-page-header__description)')).toMatch(/margin: 0;/)
	})

	it('reaches the page header title and text through :deep, which a scoped rule needs to place them', () => {
		expect(board).not.toMatch(/\.cn-page-header \.cn-page-header__(text|title|description) \{/)
		expect(rule(board, '.cn-look-board.pipeline-board > .cn-page-header :deep(.cn-page-header__text)')).toMatch(/display: contents;/)
		expect(rule(board, '.cn-look-board.pipeline-board > .cn-page-header :deep(.cn-page-header__title)')).toMatch(/grid-column: 1;/)
	})

	it('draws the view chips without a band', () => {
		const chips = rule(board, '.cn-look-board .pipeline-board__toolbar .pipeline-board__chips')
		expect(chips).toMatch(/padding: 0;/)
		expect(chips).toMatch(/background: transparent;/)
	})

	it('draws the settings link as a bare link under a theme', () => {
		const link = rule(board, '.cn-look-board .pipeline-board__toolbar button.pipeline-board__settings-link')
		expect(link).toMatch(/margin: 0 !important;/)
		expect(link).toMatch(/background: transparent !important;/)
		expect(nl['Pipeline settings']).toBe('Pijplijninstellingen')
	})

	it('sets the stage heading on a normal line, the count 12px bold and the total line 18px in', () => {
		expect(rule(board, '.cn-look-board .kanban-column__header')).toMatch(/line-height: normal;/)
		const count = rule(board, '.cn-look-board .column-count')
		expect(count).toMatch(/padding: 1px 8px;/)
		expect(count).toMatch(/font-size: 12px;/)
		expect(count).toMatch(/font-weight: 700;/)
		expect(rule(board, '.cn-look-board .column-value-wrapper')).toMatch(/padding-inline-start: 18px;/)
		expect(rule(board, '.cn-look-board .column-value')).toMatch(/font-weight: 700;/)
	})

	it('draws the owner as a 26px chip in the light primary', () => {
		const owner = rule(card, '.pipeline-card--board .pipeline-card__owner')
		expect(owner).toMatch(/width: 26px;/)
		expect(owner).toMatch(/height: 26px;/)
		expect(owner).toMatch(/background: var\(--color-primary-element-light\);/)
		expect(owner).toMatch(/color: var\(--color-primary-element-light-text\);/)
	})
})
