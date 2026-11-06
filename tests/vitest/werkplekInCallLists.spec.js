/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 * SPDX-License-Identifier: EUPL-1.2
 *
 * In a call the client's open matters, tasks and earlier contact are rows, as
 * board PqWerkplekKlant draws them: a title, a meta line and a pill, no table
 * head. The header drops "Log contact" while the call is on, and the BRP
 * lookup carries its icon.
 *
 * @spec openspec/changes/werkplek-in-call-lists/specs/kcc-werkplek/spec.md#requirement-the-in-call-lists-are-rows
 */

import { readFileSync } from 'node:fs'
import { resolve } from 'node:path'
import { describe, expect, it } from 'vitest'

const simple = JSON.parse(readFileSync(resolve(__dirname, '../../src/menu-layout.simple.json'), 'utf8'))
const page = simple.pages.find((p) => p.id === 'KccWerkplek')
const widgets = page.configAppend.widgets
const widget = (id) => widgets.find((w) => w.id === id)
const inCall = page.config.layoutWhen[0].layout
const inCallWidget = (title) => widget(inCall.find((item) => item.title === title).widgetId)
const callOnly = { workspace: 'callActive', op: 'eq', value: true }

describe('werkplek in-call lists (PqWerkplekKlant)', () => {
	it('draws the open matters as rows with a deadline or done pill', () => {
		const matters = inCallWidget('Open matters')
		expect(matters.type).toBe('record-list')
		expect(matters.visibleWhen).toEqual(callOnly)
		expect(matters.content.presentation).toBe('rows')
		expect(matters.content.filter.client).toBe('@workspace.selectedClient')
		expect(matters.content.meta.parts).toEqual(['{reference}', '{ticketType|lowerfirst}', '{assignee|user}'])
		const pill = matters.content.pill
		expect(pill.cases[0]).toEqual({ when: { field: 'status', op: 'in', value: ['resolved', 'completed', 'closed', 'rejected', 'converted'] }, label: 'Done' })
		expect(pill.cases[1]).toEqual({ when: { field: 'slaDeadline', op: 'notEmpty' }, label: 'By {slaDeadline|date}', variant: 'warning' })
	})

	it('draws the tasks and callback requests as rows with their status pill', () => {
		const tasks = widget('simple-call-tasks')
		expect(tasks.type).toBe('record-list')
		expect(tasks.content.presentation).toBe('rows')
		expect(tasks.content.columns).toBeUndefined()
		expect(tasks.content.title).toBe('{subject}')
		expect(tasks.content.pill.field).toBe('status')
	})

	it('draws the earlier contact as rows with a link to all of them', () => {
		const earlier = inCallWidget('Earlier contact')
		expect(earlier.type).toBe('record-list')
		expect(earlier.visibleWhen).toEqual(callOnly)
		expect(earlier.content.presentation).toBe('rows')
		expect(earlier.content.meta.parts).toEqual(['{occurredAt|datetime}', '{channel}', '{direction}'])
		expect(earlier.content.headerLink.label).toBe('All {total}')
	})

	it('hides Log contact in the header while a call is on', () => {
		const log = page.config.headerActions.find((a) => a.id === 'log-contact')
		expect(log.visibleWhen).toEqual({ workspace: 'callActive', op: 'neq', value: true })
	})

	it('gives the BRP lookup its icon', () => {
		const brp = widget('simple-call-who').content.actions[0]
		expect(brp.icon).toBe('CardAccountDetailsOutline')
	})
})
