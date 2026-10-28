/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 * SPDX-License-Identifier: EUPL-1.2
 *
 * The project page as the PqProject board draws it: the four figures across
 * the page above the tabs and the side column, "Cyclus sluiten" in the header,
 * the plan in three columns, a count line under the cycle's tasks, no add
 * button on the overview's cycles card, and history without read entries.
 *
 * @spec openspec/changes/project-close-current-cycle/specs/project-portfolio/spec.md#requirement-a-project-page-can-close-its-current-cycle
 */

import { readFileSync } from 'node:fs'
import { resolve } from 'node:path'
import { describe, expect, it } from 'vitest'

const fragment = JSON.parse(readFileSync(resolve(__dirname, '../../src/manifest.d/83-programmes.json'), 'utf8'))
const page = fragment.pages.find((p) => p.id === 'ProgrammeDetail')
const config = page.config
const widget = (id) => config.widgets.find((w) => w.id === id)

describe('PqProject: the project page', () => {
	it('draws the four figures as a band across the page', () => {
		expect(config.band).toEqual([
			{ widgetId: 'prog-kpi-progress', span: 3 },
			{ widgetId: 'prog-kpi-tasks', span: 3 },
			{ widgetId: 'prog-kpi-team', span: 3 },
			{ widgetId: 'prog-kpi-term', span: 3 },
		])
		expect(config.layout.map((l) => l.widgetId)).toEqual(['prog-panels'])
	})

	it('closes the current cycle from the header while the project is active', () => {
		const action = config.headerActions.find((a) => a.id === 'close-cycle')
		expect(action).toMatchObject({
			type: 'api-call',
			label: 'Close cycle',
			method: 'POST',
			url: '/apps/pipelinq/api/programmes/@objectId/close-cycle',
			visibleWhen: { field: 'status', op: 'eq', value: 'active' },
		})
	})

	it('sets the plan in three columns', () => {
		expect(widget('prog-plan').content.columns).toBe(3)
	})

	it('ends the cycle tasks with a count line, on the overview and the tab', () => {
		for (const id of ['prog-tasks', 'prog-tasks-tab']) {
			expect(widget(id).content.summary.text).toMatch(/^\{total\} tasks · /)
			expect(widget(id).content.footerNote).toBeUndefined()
		}
	})

	it('adds cycles from the Cycles tab only', () => {
		expect(widget('prog-cycles').content.allowCreate).toBe(false)
		expect(widget('prog-cycles-tab').content.allowCreate).toBe(true)
	})

	it('shows history without read entries', () => {
		for (const id of ['side-history', 'prog-history']) {
			expect(widget(id).type).toBe('timeline')
			expect(widget(id).content.excludeActions).toEqual(['read'])
		}
		expect(widget('side-history').content.limit).toBe(4)
	})
})
