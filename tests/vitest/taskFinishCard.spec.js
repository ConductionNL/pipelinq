/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Board PqTaak: "Deze taak afronden" between the task and its documents, a
 * Resultaat text area with Poging vastleggen and Afronden, both through the
 * callback endpoints, which check the user may change the task.
 *
 * @spec openspec/specs/callback-management/spec.md#requirement-a-task-is-finished-from-its-page
 */

import fs from 'fs'
import path from 'path'
import { describe, expect, it } from 'vitest'

const ROOT = path.resolve(__dirname, '../..')
const manifest = JSON.parse(fs.readFileSync(path.join(ROOT, 'src', 'manifest.json'), 'utf8'))
const routes = fs.readFileSync(path.join(ROOT, 'appinfo', 'routes.php'), 'utf8')
const nl = JSON.parse(fs.readFileSync(path.join(ROOT, 'l10n', 'nl.json'), 'utf8')).translations
const config = manifest.pages.find((p) => p.id === 'TaskDetail').config

describe('the task finish card', () => {
	const card = config.widgets.find((w) => w.id === 'task-finish')

	it('sits between the task and its documents while the task is open', () => {
		const overview = config.widgets.find((w) => w.id === 'task-panels').content.tabs[0]
		expect(overview.widgetIds).toEqual(['task-data', 'task-finish', 'task-files'])
		expect(nl[card.title]).toBe('Deze taak afronden')
		expect(card.visibleWhen).toEqual({ field: 'status', op: 'in', value: ['open', 'in_progress'] })
	})

	it('asks the result and sends it as an attempt or as the finish', () => {
		expect(card.type).toBe('record-notes')
		const content = card.content
		expect(content.field).toBe('resultText')
		expect(content.autosave).toBe(false)
		expect(nl[content.label]).toBe('Resultaat')
		expect(nl[content.placeholder]).toBe('Wat kwam er uit het terugbellen?')
		const [attempt, finish] = content.actions
		expect(nl[attempt.label]).toBe('Poging vastleggen')
		expect(attempt.request).toEqual({ url: '/apps/pipelinq/api/callbacks/@objectId/attempts', body: { result: '{text}' } })
		expect(nl[finish.label]).toBe('Afronden')
		expect(finish.variant).toBe('primary')
		// On an open task Finish first starts it (the lifecycle's claim, which
		// checks the user may update the task), then completes it.
		expect(finish.visibleWhen).toBeUndefined()
		expect(finish.requests).toEqual([
			{ when: { field: 'status', op: 'eq', value: 'open' }, url: '/apps/openregister/api/objects/@objectId/transition', body: { action: 'claim' } },
			{ url: '/apps/pipelinq/api/callbacks/@objectId/complete', body: { resultText: '{text}' } },
		])
		expect(routes).toContain("'url' => '/api/callbacks/{id}/attempts'")
		expect(routes).toContain("'url' => '/api/callbacks/{id}/complete'")
		for (const action of content.actions) {
			expect(nl[action.doneText], action.doneText).toBeTruthy()
		}
	})
})
