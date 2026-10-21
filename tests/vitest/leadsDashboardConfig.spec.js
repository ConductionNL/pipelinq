/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 * SPDX-License-Identifier: EUPL-1.2
 *
 * The leads, pipeline and dashboard surfaces that became manifest config over
 * nextcloud-vue widgets (PL-ABSTRACT lane PL4). Each case pins what the old
 * custom component did, so the config cannot quietly lose it.
 *
 * @spec openspec/specs/dashboard/spec.md
 */

import fs from 'fs'
import path from 'path'
import { describe, expect, it } from 'vitest'

const ROOT = path.resolve(__dirname, '../..')
const manifest = JSON.parse(fs.readFileSync(path.join(ROOT, 'src/manifest.json'), 'utf8'))
const page = (id) => manifest.pages.find((p) => p.id === id)
const registrySource = fs.readFileSync(path.join(ROOT, 'src/registry.js'), 'utf8')

describe('operational dashboard', () => {
	const ops = page('OperationalDashboard')

	it('exports from a header action that names the records it downloads', () => {
		const action = ops.config.headerActions.find((a) => a.type === 'export')
		expect(action.handler).toBeUndefined()
		expect(action.entities.map((e) => e.id)).toEqual(['leads', 'requests', 'contact-moments', 'satisfaction'])
		for (const entity of action.entities) {
			expect(entity.register).toBe('pipelinq')
			expect(typeof entity.schema).toBe('string')
		}
		expect(action.entities.find((e) => e.id === 'requests').filter).toEqual({ ticketType: 'request' })
		expect(action.entities.find((e) => e.id === 'contact-moments').filter).toEqual({ ticketType: 'interaction' })
		expect(ops.config.widgets.some((w) => w.id === 'report-export')).toBe(false)
		expect(registrySource).not.toMatch(/ReportExportPanel/)
	})

	it('lists my work from the worklist, each item opening its own page, never 0 on a failure', () => {
		const myWork = ops.config.widgets.find((w) => w.id === 'my-work')
		expect(myWork.type).toBe('work-queue')
		expect(myWork.content.source.url).toBe('/apps/pipelinq/api/worklist/mine')
		expect(myWork.content.itemsPath).toBe('items')
		expect(myWork.content.lateField).toBe('isOverdue')
		expect(myWork.content.kind.labels).toEqual({ lead: 'Lead', request: 'Request' })
		expect(myWork.content.viewAll.route).toEqual({ name: 'MyWork' })
		expect(ops.slots['widget-my-work']).toBeUndefined()
		expect(registrySource).not.toMatch(/MyWorkWidget/)
	})
})
