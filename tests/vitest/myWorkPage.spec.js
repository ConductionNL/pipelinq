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
			.filter((id) =>
				[
					'my-answer-today',
					'my-waiting-on-me',
					'my-waiting-on-customer',
				].includes(id),
			)
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
		expect(bar.controls.map((c) => c.style)).toEqual([
			'chips',
			'toggle',
			'select',
		])
		const chips = bar.controls[0].options.map((o) => o.value)
		expect(chips).toEqual(['all', 'tickets', 'leads', 'tasks'])
	})

	it('follows the chips, and lets the controls sort and show completed work', () => {
		for (const id of [
			'my-answer-today',
			'my-waiting-on-me',
			'my-waiting-on-customer',
			'my-leads',
			'my-follow-ups',
		]) {
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

	it('badges urgent and high work, and draws the due date as text', () => {
		const lists = {
			'my-answer-today': 'slaDeadline',
			'my-waiting-on-me': 'slaDeadline',
			'my-waiting-on-customer': 'slaDeadline',
			'my-leads': 'expectedCloseDate',
			'my-follow-ups': 'deadline',
		}
		for (const [id, field] of Object.entries(lists)) {
			const c = widget(id).content
			expect(c.due.field, id).toBe(field)
			// The due text replaces the old "Deadline {date}" trailing.
			expect(JSON.stringify(c.trailing ?? ''), id).not.toContain('Deadline')
			if (id === 'my-follow-ups') continue
			const priority = c.badges.find((b) => b.field === 'priority')
			expect(priority.hideValues, id).toEqual(['normal', 'low'])
		}
	})

	it('marks a lead stale on the same field the Leads list filters on', () => {
		const stale = widget('my-leads').content.badges.find(
			(b) => b.kind === 'stale',
		)
		// Lead objects carry `@self.updated`; there is no `_dateModified` on them.
		expect(stale.field).toBe('@self.updated')
		expect(stale.afterDays).toBe(14)
		expect(widget('my-leads').content.trailing).toBe('{value|currency:currency}')
	})

	it('sorts priority first by the rank, not by the stored string', () => {
		const select = widget('my-lens').content.controls.find(
			(c) => c.style === 'select',
		)
		expect(select.options.map((o) => o.value)).toContain('priority')
		for (const id of [
			'my-answer-today',
			'my-waiting-on-me',
			'my-waiting-on-customer',
			'my-leads',
		]) {
			const entry = widget(id).content.pageControls.sort.map.priority
			expect(entry.rank, id).toEqual(['urgent', 'high', 'normal', 'low'])
			expect(entry.then.dir, id).toBe('asc')
		}
	})

	it('keeps the meta line when a row has no customer', () => {
		expect(widget('my-answer-today').content.meta.parts).toEqual([
			'{client.name}',
			'{channel}',
		])
	})

	it('puts the bar above the grid and the week in one card', () => {
		// In the KPI row the bar is no grid item, so the grid's two-row minimum
		// does not leave a band under it.
		expect(page.config.kpiRow).toEqual(['my-lens'])
		expect(page.config.layout.some((l) => l.widgetId === 'my-lens')).toBe(false)
		const week = widget('my-week')
		expect(week.type).toBe('stats-block')
		expect(week.content.layout).toBe('grid')
		for (const stat of week.content.stats) {
			expect(stat.source.filter.assignee, stat.label).toBe('@me')
		}
		expect(week.content.stats.map((s) => s.source.kind)).toContain('elapsed')
	})

	it('says what each list shows when it is empty, and labels the channel', () => {
		expect(widget('my-answer-today').content.emptyText).toBe(
			'No ticket of yours is due today',
		)
		for (const id of [
			'my-answer-today',
			'my-waiting-on-me',
			'my-waiting-on-customer',
		]) {
			const labels = widget(id).content.valueLabels.channel
			// The data holds both spellings of the phone channel.
			expect(labels.phone, id).toBe('Phone')
			expect(labels.telefoon, id).toBe('Phone')
		}
	})

	it('carries the start-here banner and the saved views', () => {
		expect(widget('my-start-here').type).toBe('banner')
		expect(widget('my-saved-views').type).toBe('saved-views')
	})

	// Board PqMijnWerk: a 300px side column titled as side cards, and the
	// three ticket lists headed by what they hold instead of a link.
	it('draws the side column at the board width, with side-card titles', () => {
		expect(page.config.gridSideColumn).toEqual({ width: 300 })
		const track = (id) => page.config.layout.find((l) => l.widgetId === id).track
		expect(['my-answer-today', 'my-waiting-on-me', 'my-waiting-on-customer'].map(track)).toEqual(['main', 'main', 'main'])
		expect(['my-week', 'my-start-here', 'my-saved-views'].map(track)).toEqual(['side', 'side', 'side'])
		expect(widget('my-week').titleTone).toBe('side')
		expect(widget('my-saved-views').titleTone).toBe('side')
	})

	it('heads each ticket list with what it holds', () => {
		expect(widget('my-answer-today').content.headerNote).toBe('deadline is today or has passed')
		expect(widget('my-waiting-on-me').content.headerNote).toBe('the customer has replied')
		expect(widget('my-waiting-on-customer').content.headerNote).toBe('nothing to do until they reply')
		expect(widget('my-answer-today').headerLink).toBeUndefined()
	})

	it('keeps the grid cells apart', () => {
		const layout = page.config.layout
		for (const a of layout) {
			for (const b of layout) {
				if (a === b) continue
				const overlap =
					a.gridX < b.gridX + b.gridWidth
					&& b.gridX < a.gridX + a.gridWidth
					&& a.gridY < b.gridY + b.gridHeight
					&& b.gridY < a.gridY + a.gridHeight
				expect(overlap, `${a.widgetId} / ${b.widgetId}`).toBe(false)
			}
		}
	})
})
