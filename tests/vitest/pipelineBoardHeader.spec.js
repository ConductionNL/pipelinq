/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 * SPDX-License-Identifier: EUPL-1.2
 *
 * The pipeline board's header as board PqPipeline draws it: the board look,
 * Download (the leads on the board as CSV), the buildiq square and New lead.
 *
 * @spec openspec/specs/pipeline/spec.md
 */
import { readFileSync } from 'node:fs'
import { resolve } from 'node:path'
import { describe, expect, it } from 'vitest'
import { toCsv } from '../../src/services/csvExport.js'

describe('the pipeline board header', () => {
	it('writes rows as semicolon CSV, quoting what needs it', () => {
		const csv = toCsv([{ title: 'Garage "Bakker"', value: 9800 }, { title: 'Delta; BV', value: null }], ['title', 'value'])
		expect(csv).toBe('title;value\n"Garage ""Bakker""";9800\n"Delta; BV";')
	})

	it('draws the board look, Download, the buildiq square and New lead', () => {
		const source = readFileSync(resolve(__dirname, '../../src/views/pipeline/PipelineBoard.vue'), 'utf8')
		expect(source).toMatch(/<div\s+class="pipeline-board"\s+:class="lookClass"/)
		expect(source).toContain('data-testid="pipeline-download"')
		expect(source).toContain('<CnBuildiqEditButton />')
		expect(source.indexOf('pipeline-download')).toBeLessThan(source.indexOf('<CnBuildiqEditButton />'))
		expect(source.indexOf('<CnBuildiqEditButton />')).toBeLessThan(source.indexOf('data-testid="pipeline-new-lead"'))
	})
})
