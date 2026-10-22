// @vitest-environment node
/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 * SPDX-License-Identifier: EUPL-1.2
 *
 * A client's activity is the nextcloud-vue `timeline` over GET /api/timeline,
 * the endpoint that merges contact moments, worklogs, tasks, emails and
 * calendar entries (ActivityTimelineControllerTest). Paging, the type chips
 * and the empty sentence are nextcloud-vue's (CnTimelineWidgetEndpoint); this
 * file pins the config.
 *
 * @spec openspec/changes/activity-timeline-as-configuration/specs/activity-timeline/spec.md#requirement-a-clients-activity-is-the-library-timeline
 */

import { readFileSync } from 'node:fs'
import { resolve } from 'node:path'
import { describe, expect, it } from 'vitest'

const read = (...parts) => readFileSync(resolve(__dirname, '../..', ...parts), 'utf8')
const manifest = JSON.parse(read('src', 'manifest.json'))
const section = manifest.pages.find((p) => p.id === 'ClientDetail').config.bodyWidgets.find((w) => w.id === 'activity')

describe('the activity on a client page', () => {
	it('is a timeline over the merged activity endpoint for this client', () => {
		expect(section).toMatchObject({ type: 'timeline', title: 'Activity', card: true })
		expect(section.component).toBeUndefined()
		expect(section.content.endpointSource).toMatchObject({
			url: '/apps/pipelinq/api/timeline',
			params: { entityType: 'client', entityId: '@objectId' },
			itemsPath: 'items',
			pagesPath: 'pages',
			typesParam: 'types[]',
			limit: 20,
		})
	})

	it('names every type the endpoint answers and narrows by the four chips', () => {
		expect(Object.keys(section.content.endpointSource.typeLabels)).toEqual(['interaction', 'worklog', 'task', 'email', 'calendar'])
		expect(section.content.typeChips.map((c) => c.value)).toEqual(['interaction', 'task', 'email', 'calendar'])
	})

	it('shows a contact moment by its channel and every other event by its type, as before', () => {
		expect(section.content.endpointSource.icons).toEqual({
			path: 'metadata.channel',
			map: { phone: 'Phone', email: 'Email' },
			typeMap: { interaction: 'Message', worklog: 'ClockOutline', task: 'CheckCircle', email: 'EmailOutline', calendar: 'Calendar' },
			default: 'Message',
		})
	})

	it('says what will appear when there is nothing yet', () => {
		expect(section.content.emptyText).toMatch(/^Activities will appear here/)
	})

	it('no longer ships the app-local component', () => {
		expect(read('src', 'registry.js')).not.toContain('ActivityTimeline')
	})
})
