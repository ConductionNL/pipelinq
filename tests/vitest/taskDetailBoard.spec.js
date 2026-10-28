/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Board PqTaak as configuration: the subject names the task, kind and
 * status are coloured pills, Call dials the callback number and leads while
 * the task is open, the next step reads per stage, and the task sits in an
 * Overview tab beside History with the related records aside.
 *
 * @spec openspec/specs/declarative-view-system/spec.md
 */

import fs from 'fs'
import path from 'path'
import { describe, expect, it } from 'vitest'

const ROOT = path.resolve(__dirname, '../..')
const manifest = JSON.parse(fs.readFileSync(path.join(ROOT, 'src', 'manifest.json'), 'utf8'))
const nl = JSON.parse(fs.readFileSync(path.join(ROOT, 'l10n', 'nl.json'), 'utf8')).translations
const config = manifest.pages.find((p) => p.id === 'TaskDetail').config

describe('task page', () => {
	it('names the task and colours its pills', () => {
		expect(config.titleField).toBe('subject')
		expect(config.typePill.field).toBe('type')
		expect(config.statusPill.colorMap.in_progress).toBe('purple')
		expect(nl[config.headerMeta]).toBe('uiterlijk {deadline|datetime}')
	})

	it('calls the callback number, first while the task is open', () => {
		const call = config.headerActions.find((action) => action.id === 'task-call')
		expect(call.target).toBe('tel:@object.callbackPhoneNumber')
		expect(nl[call.label]).toBe('Bellen')
		expect(config.primaryActionByStage.open.id).toBe('task-call-next')
		expect(Object.keys(config.nextStep.stages)).toEqual(['open', 'in_progress'])
	})

	it('puts the task in an Overview tab beside History, the related records aside', () => {
		const tabs = config.widgets.find((w) => w.id === 'task-panels').content.tabs
		expect(tabs.map((tab) => tab.label)).toEqual(['Overview', 'History'])
		expect(config.sideColumn[0]).toBe('task-related')
	})

	it('puts Call and Finish in the header beside Edit', () => {
		expect(config.quickActions).toEqual(['task-call', 'task-complete'])
		const finish = config.headerActions.find((action) => action.id === 'task-complete')
		expect(nl[finish.label]).toBe('Afronden')
		expect(finish.url).toBe('/apps/openregister/api/objects/@objectId/transition')
		expect(finish.payload).toEqual({ action: 'complete' })
		expect(finish.visibleWhen).toEqual({ field: 'status', op: 'eq', value: 'in_progress' })
	})

	it('names the number on the next step\'s call button', () => {
		const next = config.primaryActionByStage.in_progress
		expect(next.target).toBe('tel:@object.callbackPhoneNumber')
		expect(nl[next.primaryLabel]).toBe('Bel {callbackPhoneNumber}')
		expect(config.primaryActionByStage.open).toEqual(next)
	})

	it('lists the latest history in a side card under the related records', () => {
		expect(config.sideColumn).toEqual(['task-related', 'task-side-history'])
		const history = config.widgets.find((w) => w.id === 'task-side-history')
		expect(history.type).toBe('audit-trail')
		expect(nl[history.title]).toBe('Geschiedenis')
		expect(history.content.excludeActions).toEqual(['read'])
	})
})
