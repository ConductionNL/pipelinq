// @vitest-environment node
/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Suggested colleagues on the ticket and lead pages (pipelinq#2039,
 * pipelinq#2049) are a nextcloud-vue `record-list` over the routing
 * endpoint: the ranked colleagues for the record, an Assign button per row
 * that writes the colleague into the record's `assignee` (one PATCH, so the
 * rest of the record stays), the assigned colleague marked as such, and the
 * colleagues at capacity counted under the list. The drawing and the write are
 * nextcloud-vue's (CnRecordListWidgetSetField.spec.js); this file pins the
 * config.
 *
 * @spec openspec/changes/reverse-2026-05-26-fe-routing-ui/tasks.md#task-1
 */

import { readFileSync } from 'node:fs'
import { resolve } from 'node:path'
import { describe, expect, it } from 'vitest'

const read = (...parts) => readFileSync(resolve(__dirname, '../..', ...parts), 'utf8')
const manifest = JSON.parse(read('src', 'manifest.json'))
const page = (id) => manifest.pages.find((p) => p.id === id)

describe.each([
	['TicketDetail', 'request', () => page('TicketDetail').config.bodyWidgets.find((w) => w.id === 'routing-suggestions')],
	['LeadDetail', 'lead', () => page('LeadDetail').config.widgets.find((w) => w.id === 'side-suggested')],
])('suggested colleagues on %s', (pageId, entityType, find) => {
	it('asks the routing endpoint for this record', () => {
		const widget = find()
		expect(widget.type).toBe('record-list')
		expect(widget.content.endpointSource).toEqual({
			url: '/apps/pipelinq/api/routing/suggestions',
			params: { entityType, entityId: '@objectId' },
			responsePath: 'suggestions',
		})
	})

	it('writes the chosen colleague into the assignee and marks the assigned one', () => {
		const [action] = find().content.rowActions
		expect(action).toMatchObject({
			type: 'set-field',
			field: 'assignee',
			value: '{userId}',
			label: 'Assign',
			ariaLabel: 'Assign to {displayName}',
			doneLabel: 'Assigned',
			errorText: 'Could not save the assignee.',
		})
	})

	it('shows workload against capacity and counts the colleagues at capacity', () => {
		const { content } = find()
		expect(content.meta).toBe('{workload}/{maxConcurrent} items')
		expect(content.bar).toEqual({ valueField: 'workload', maxField: 'maxConcurrent', overVariant: 'error' })
		expect(content.footnote).toEqual({ path: 'atCapacity', text: '{value} matching colleagues at capacity' })
	})
})

describe('the lead side', () => {
	it('places the suggestions in the lead side column', () => {
		expect(page('LeadDetail').config.sideColumn).toContain('side-suggested')
	})

	it('lets a lead carry a category on the form and the list', () => {
		const schema = JSON.parse(read('lib', 'Settings', 'pipelinq_register.json')).components.schemas.lead
		expect(schema.properties.category.type).toBe('string')
		expect(schema.required || []).not.toContain('category')

		const form = read('src', 'views', 'leads', 'LeadForm.vue')
		expect(form).toContain(':modelValue="form.category"')
		expect(form).toMatch(/category: this\.lead\.category/)
	})
})

describe('every string the config shows is in the catalogues', () => {
	it.each(['en', 'nl'])('%s', (lang) => {
		const catalogue = JSON.parse(read('l10n', `${lang}.json`)).translations
		for (const text of ['Assign', 'Assigned', 'Assign to {displayName}', '{workload}/{maxConcurrent} items', '{value} matching colleagues at capacity', 'No agents with matching skills', 'Failed to load suggestions', 'Could not save the assignee.']) {
			expect(catalogue[text], text).toBeTruthy()
		}
	})
})
