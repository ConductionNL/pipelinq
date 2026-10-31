/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 * SPDX-License-Identifier: EUPL-1.2
 *
 * The project page (PqProject) over a delivery programme, and its list.
 *
 * @spec openspec/specs/project-portfolio/spec.md
 */

import { readFileSync } from 'node:fs'
import { resolve } from 'node:path'
import { describe, expect, it } from 'vitest'

const pages = JSON.parse(
	readFileSync(
		resolve(__dirname, '../../src/manifest.d/83-programmes.json'),
		'utf8',
	),
).pages
const detail = pages.find((p) => p.id === 'ProgrammeDetail')
const widget = (id) => detail.config.widgets.find((w) => w.id === id)

describe('project page', () => {
	it('reads the delivery programme, never a project slug', () => {
		expect(pages.map((p) => p.config.schema)).toEqual([
			'deliveryProgramme',
			'deliveryProgramme',
		])
	})

	it('puts four KPI tiles above one tab strip', () => {
		expect(detail.config.layout.map((item) => item.widgetId)).toEqual([
			'prog-kpi-progress',
			'prog-kpi-tasks',
			'prog-kpi-team',
			'prog-kpi-term',
			'prog-panels',
		])
		expect(widget('prog-panels').content.tabs.map((t) => t.label)).toEqual([
			'Overview',
			'Tasks',
			'Team',
			'Cycles',
			'Linked work',
			'History',
		])
	})

	it('takes progress from the server, which says why when it cannot', () => {
		expect(widget('prog-kpi-progress').content.endpointSource.url).toBe(
			'/apps/pipelinq/api/programmes/@objectId/progress',
		)
	})

	it('shows the client, the team and the term beside the body', () => {
		expect(detail.config.sideColumn).toEqual([
			'side-client',
			'side-team',
			'side-term',
			'side-history',
		])
	})

	it('lays the plan out in three columns, as PqProject draws it', () => {
		expect(widget('prog-plan').content.columns).toBe(3)
	})

	it('names people by their display name, not their user id', () => {
		const owner = widget('prog-plan').content.fields.find((f) => f.path === 'owner')
		expect(owner.format).toBe('user')
		expect(widget('side-team').content.title).toBe('{user|user}')
		for (const id of ['prog-tasks', 'prog-tasks-tab']) {
			const assignee = widget(id).content.columns.find((c) => c.key === 'assignee')
			expect(assignee.formatter, id).toBe('userDisplayName')
		}
		const person = widget('prog-team').content.columns.find((c) => c.key === 'user')
		expect(person.formatter).toBe('userDisplayName')
	})
})

