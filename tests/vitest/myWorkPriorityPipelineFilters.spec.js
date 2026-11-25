/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 * SPDX-License-Identifier: EUPL-1.2
 *
 * My work's priority and pipeline filters (REQ-MW-200): a priority select that
 * takes several priorities and a pipeline picker next to the type buttons
 * write the workspace, the bar counts the filters set and clears them, and
 * every list and the download pass them to the worklist, which narrows the
 * items server side.
 *
 * @spec openspec/changes/archive/2026-11-25-my-work-roadmap/specs/my-work/spec.md#requirement-priority-and-pipeline-filters-req-mw-200
 */

import fs from 'fs'
import path from 'path'
import { describe, expect, it } from 'vitest'
import nl from '../../l10n/nl.json'

const ROOT = path.resolve(__dirname, '../..')
const manifest = JSON.parse(fs.readFileSync(path.join(ROOT, 'src/manifest.json'), 'utf8'))
const config = manifest.pages.find((page) => page.id === 'MyWork').config
const lens = config.widgets.find((entry) => entry.id === 'my-lens')
const control = (writes) => lens.content.controls.find((entry) => entry.writes === writes)
const FILTER_PARAMS = 'priority=@workspace.priority&pipeline=@workspace.pipeline'

describe('the priority and pipeline filters on My work', () => {
	it('offers the four priorities, several at once', () => {
		const priority = control('@workspace.priority')
		expect(priority).toMatchObject({ style: 'select', label: 'Priority', urlKey: 'priority', multiple: true, filter: true })
		expect(priority.options.map((option) => option.value)).toEqual(['urgent', 'high', 'normal', 'low'])
	})

	it('counts the type, priority and pipeline filters and clears them, not the sorting or Show completed', () => {
		expect(lens.content.clear).toEqual({})
		const counted = lens.content.controls.filter((entry) => entry.filter === true).map((entry) => entry.writes)
		expect(counted).toEqual(['@workspace.lens', '@workspace.priority', '@workspace.pipeline'])
		expect(control('@workspace.lens').default).toBe('all')
	})

	it('picks one pipeline by its title, with All pipelines to clear the choice', () => {
		const pipeline = control('@workspace.pipeline')
		expect(pipeline).toMatchObject({ label: 'Pipeline', urlKey: 'pipeline', allLabel: 'All pipelines', filter: true })
		expect(pipeline.objectSource).toMatchObject({ register: 'pipelinq', schema: 'pipeline', labelField: 'title' })
	})

	it('sits next to the type buttons, before the sorting', () => {
		const order = lens.content.controls.map((entry) => entry.writes)
		expect(order.indexOf('@workspace.lens')).toBeLessThan(order.indexOf('@workspace.priority'))
		expect(order.indexOf('@workspace.pipeline')).toBeLessThan(order.indexOf('@workspace.sort'))
	})

	it('passes both filters on every worklist read and the download', () => {
		const text = JSON.stringify(config)
		const reads = text.match(/\/apps\/pipelinq\/api\/worklist\/items\?[^"]*lens=[^"]*/g)
		expect(reads.length).toBeGreaterThanOrEqual(5)
		for (const url of reads) {
			expect(url).toContain(FILTER_PARAMS)
		}
	})

	it('has Dutch text for every new label', () => {
		for (const key of ['Priority', 'Pipeline', 'All pipelines', 'Urgent', 'High', 'Normal', 'Low']) {
			expect(nl.translations[key], key).toBeTruthy()
		}
	})
})
