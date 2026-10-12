/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 * SPDX-License-Identifier: EUPL-1.2
 *
 * My work is a manifest page over record lists (PqMijnWerk), not a custom
 * component: every list is scoped to the signed-in user and none of the
 * widgets overlap in the grid.
 *
 * @spec openspec/specs/mobile-experience/spec.md#requirement-my-work-works-as-a-phone-list-req-mob-002
 */

import { readFileSync } from 'node:fs'
import { resolve } from 'node:path'
import { describe, expect, it } from 'vitest'

const manifest = JSON.parse(
	readFileSync(resolve(__dirname, '../../src/manifest.json'), 'utf8'),
)
const page = manifest.pages.find((p) => p.id === 'MyWork')
const widget = (id) => page.config.widgets.find((w) => w.id === id)

describe('the My work page', () => {
	it('is a dashboard page, not a custom component', () => {
		expect(page.type).toBe('dashboard')
		expect(page.component).toBeUndefined()
		expect(page.route).toBe('/my-work')
	})

	it('shows today first, then what waits on the user, then on the customer', () => {
		const order = page.config.layout
			.slice()
			.sort((a, b) => a.gridY - b.gridY)
			.map((l) => l.widgetId)
			.filter((id) => ['my-answer-today', 'my-waiting-on-me', 'my-waiting-on-customer'].includes(id))
		expect(order).toEqual([
			'my-answer-today',
			'my-waiting-on-me',
			'my-waiting-on-customer',
		])
		expect(widget('my-answer-today').content.filter['slaDeadline[lt]']).toBe(
			'@today+1d',
		)
	})

	it('scopes every list and count to the signed-in user', () => {
		for (const w of page.config.widgets) {
			const filter = w.content.filter || w.content.source?.filter
			if (!filter) continue // the bar, the banner and the saved views carry their own sources
			const own = filter.assignee ?? filter.assigneeUserId
			expect(own, w.id).toBe('@me')
		}
	})

	it('has a bar with the lens chips, the completed switch and the sort select', () => {
		const bar = widget('my-lens').content
		expect(bar.layout).toBe('bar')
		expect(bar.controls.map((c) => c.style)).toEqual(['chips', 'toggle', 'select'])
		const chips = bar.controls[0].options.map((o) => o.value)
		expect(chips).toEqual(['all', 'tickets', 'leads', 'tasks'])
		expect(page.config.layout.find((l) => l.widgetId === 'my-lens').sizeToContent).toBe(true)
	})

	it('follows the chips, and lets the controls sort and show completed work', () => {
		for (const id of ['my-answer-today', 'my-waiting-on-me', 'my-waiting-on-customer', 'my-leads', 'my-follow-ups']) {
			const w = widget(id)
			expect(w.visibleWhen.workspace, id).toBe('lens')
			expect(w.content.pageControls.sort.key, id).toBe('sort')
		}
		expect(widget('my-leads').visibleWhen.value).toEqual(['all', 'leads'])
		expect(widget('my-follow-ups').visibleWhen.value).toEqual(['all', 'tasks'])
	})

	it('lists the follow-up tasks assigned to the user', () => {
		const tasks = widget('my-follow-ups').content
		expect(tasks.schema).toBe('crmTask')
		expect(tasks.filter.assigneeUserId).toBe('@me')
	})

	it('names the customer on each ticket row', () => {
		const tickets = widget('my-answer-today').content
		expect(tickets.extend).toContain('client')
		expect(tickets.avatar).toBe('{client.name}')
	})

	it('carries the start-here banner and the saved views', () => {
		expect(widget('my-start-here').type).toBe('banner')
		expect(widget('my-saved-views').type).toBe('saved-views')
	})

	it('keeps the grid cells apart', () => {
		const layout = page.config.layout
		for (const a of layout) {
			for (const b of layout) {
				if (a === b) continue
				const overlap = a.gridX < b.gridX + b.gridWidth
					&& b.gridX < a.gridX + a.gridWidth
					&& a.gridY < b.gridY + b.gridHeight
					&& b.gridY < a.gridY + a.gridHeight
				expect(overlap, `${a.widgetId} / ${b.widgetId}`).toBe(false)
			}
		}
	})
})
