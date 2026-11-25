// SPDX-License-Identifier: EUPL-1.2
// SPDX-FileCopyrightText: 2026 Conduction B.V.

/**
 * Recent activity on My work (REQ-MW-110): the section reads the latest
 * audit-trail entries of the user's work from its route, shows the kind as a
 * badge, the item's title, how long ago, who and what changed, opens the
 * item's page, and says so when there is nothing.
 *
 * @spec openspec/changes/my-work-roadmap/specs/my-work/spec.md#requirement-recent-activity-on-my-items-req-mw-110
 */
import { readFileSync } from 'node:fs'
import { resolve } from 'node:path'
import { describe, expect, it } from 'vitest'

const manifest = JSON.parse(readFileSync(resolve(__dirname, '../../src/manifest.json'), 'utf8'))
const routes = readFileSync(resolve(__dirname, '../../appinfo/routes.php'), 'utf8')
const page = manifest.pages.find((p) => p.id === 'MyWork')
const widget = page.config.widgets.find((w) => w.id === 'my-activity')

describe('My work: Recent activity', () => {
	it('reads the ten latest entries of the recent-activity route', () => {
		expect(widget.type).toBe('record-list')
		expect(widget.title).toBe('Recent activity')
		expect(widget.content.endpointSource).toEqual({ url: '/apps/pipelinq/api/my-work/recent-activity', responsePath: 'items' })
		expect(widget.content.limit).toBe(10)
		expect(routes).toContain("'url' => '/api/my-work/recent-activity'")
	})

	it('shows the kind, the title, how long ago, who and what changed, and opens the item', () => {
		expect(widget.content.pill.field).toBe('kind')
		expect(widget.content.pill.labels.lead).toBe('Lead')
		expect(widget.content.title).toBe('{title}')
		expect(widget.content.meta.parts).toEqual(['{created|ago}', '{userName}', '{changes}'])
		expect(widget.content.rowRouteField).toBe('routeName')
		expect(widget.content.rowIdField).toBe('recordId')
	})

	it('says so when nothing changed', () => {
		expect(widget.content.emptyText).toBe('No recent activity on your items')
	})

	it('folds to its header, and the per-user layout keeps it folded after a reload', () => {
		// The library stores the fold with the page's per-user layout, which
		// the layout panel turns on (nextcloud-vue widget-collapsible-section).
		expect(widget.collapsible).toBe(true)
		expect(page.config.layoutPanel).toBe(true)
		// Recent activity is not part of the work list, so it may also fold.
		expect(page.config.layoutPanelLocked).not.toContain('my-activity')
		// A folded card gives its rows back to the side column.
		const item = page.config.layout.find((entry) => entry.widgetId === 'my-activity')
		expect(item.sizeToContent).toBe(true)
	})

	it('has a place in the side column', () => {
		const item = page.config.layout.find((entry) => entry.widgetId === 'my-activity')
		expect(item.track).toBe('side')
		expect(new Set(page.config.layout.map((entry) => entry.id)).size).toBe(page.config.layout.length)
	})
})
