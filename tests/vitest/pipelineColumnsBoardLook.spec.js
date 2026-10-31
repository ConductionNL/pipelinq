/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 * SPDX-License-Identifier: EUPL-1.2
 *
 * The pipeline board's stage columns as board PqPipeline draws them: grey
 * panels without a frame, 240px at the least, the title in sentence case.
 *
 * @spec openspec/specs/pipeline/spec.md
 */
import { readFileSync } from 'node:fs'
import { resolve } from 'node:path'
import { describe, expect, it } from 'vitest'

const source = readFileSync(resolve(__dirname, '../../src/views/pipeline/PipelineBoard.vue'), 'utf8')
function rule (selector) {
	const start = source.indexOf(`${selector} {`)
	return start < 0 ? '' : source.slice(start, source.indexOf('}', start))
}

describe('the pipeline board columns in the board look', () => {
	it('are grey panels without a frame, 240px at the least', () => {
		const column = rule('.cn-look-board .kanban-column')
		expect(column).toMatch(/min-width: 240px;/)
		expect(column).toMatch(/border: 0;/)
		expect(column).toMatch(/border-radius: 12px;/)
	})

	it('drop the coloured top rule and the upper-case title', () => {
		expect(rule('.cn-look-board .kanban-column__header')).toMatch(/border: 0;/)
		expect(rule('.cn-look-board .column-title')).toMatch(/text-transform: none;/)
	})
})
