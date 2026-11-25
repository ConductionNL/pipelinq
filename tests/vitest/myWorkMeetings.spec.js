// SPDX-License-Identifier: EUPL-1.2
// SPDX-FileCopyrightText: 2026 Conduction B.V.

/**
 * Upcoming meetings on My work (REQ-MW-130): the section reads the user's
 * linked meetings from the meetings route, shows the title, the date and time
 * and the linked record's name, opens the record's page, and is hidden when
 * the route answers no meetings.
 *
 * @spec openspec/changes/archive/2026-11-25-my-work-roadmap/specs/my-work/spec.md#requirement-upcoming-meetings-from-the-calendar-req-mw-130
 */
import { readFileSync } from 'node:fs'
import { resolve } from 'node:path'
import { describe, expect, it } from 'vitest'

const manifest = JSON.parse(readFileSync(resolve(__dirname, '../../src/manifest.json'), 'utf8'))
const page = manifest.pages.find((p) => p.id === 'MyWork')
const widget = page.config.widgets.find((w) => w.id === 'my-meetings')

describe('My work: Upcoming meetings', () => {
	it('reads the linked meetings of the meetings route', () => {
		expect(widget.type).toBe('record-list')
		expect(widget.title).toBe('Upcoming meetings')
		expect(widget.content.endpointSource).toEqual({ url: '/apps/pipelinq/api/my-work/meetings', responsePath: 'items' })
	})

	it('shows the title, date and time, and the linked record, and opens the record', () => {
		expect(widget.content.title).toBe('{title}')
		expect(widget.content.meta.parts).toEqual(['{startsAt|datetime}', '{recordName}'])
		expect(widget.content.rowRouteField).toBe('routeName')
		expect(widget.content.rowIdField).toBe('recordId')
	})

	it('is hidden when there are no meetings', () => {
		expect(widget.visibleWhen).toEqual({ endpoint: '/apps/pipelinq/api/my-work/meetings', field: 'items', op: 'notEmpty' })
	})

	it('has a place in the layout', () => {
		expect(page.config.layout.some((item) => item.widgetId === 'my-meetings')).toBe(true)
	})
})
