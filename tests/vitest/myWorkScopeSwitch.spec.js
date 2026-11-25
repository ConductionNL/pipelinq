/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 * SPDX-License-Identifier: EUPL-1.2
 *
 * My work's scope switch (REQ-MW-180): a manager switches between their own
 * items and the team's, narrows to one member and goes back to the team; an
 * administrator also gets the organisation. The lists, the download and the
 * week tiles pass the scope on.
 *
 * @spec openspec/changes/my-work-roadmap/specs/my-work/spec.md#requirement-role-based-content-req-mw-180
 */

import fs from 'fs'
import path from 'path'
import { describe, expect, it } from 'vitest'
import nl from '../../l10n/nl.json'

const ROOT = path.resolve(__dirname, '../..')
const manifest = JSON.parse(fs.readFileSync(path.join(ROOT, 'src/manifest.json'), 'utf8'))
const config = manifest.pages.find((page) => page.id === 'MyWork').config
const widget = (id) => config.widgets.find((entry) => entry.id === id)
const SCOPE_PARAMS = 'scope=@workspace.scope&member=@workspace.member'

describe('the scope switch on My work', () => {
	it('offers the scopes the user has, and shows only when they have more than their own', () => {
		const scope = widget('my-scope')
		expect(scope.type).toBe('workspace-filter')
		expect(scope.visibleWhen).toEqual({ endpoint: '/apps/pipelinq/api/my-work/team', field: 'scopes', op: 'notEmpty' })
		expect(scope.content).toMatchObject({
			writes: '@workspace.scope',
			urlKey: 'scope',
			default: 'mine',
			endpointSource: { url: '/apps/pipelinq/api/my-work/team', responsePath: 'scopes' },
		})
	})

	it('lists the team members with their open items on Team, with Back to team', () => {
		const team = widget('my-team')
		expect(team.visibleWhen).toEqual({ workspace: 'scope', op: 'eq', value: 'team' })
		expect(team.content).toMatchObject({
			writes: '@workspace.member',
			urlKey: 'member',
			allLabel: 'Back to team',
			showCounts: true,
			endpointSource: { url: '/apps/pipelinq/api/my-work/team', responsePath: 'members' },
		})
	})

	it('places both in the side column, above the week', () => {
		const cell = (id) => config.layout.find((entry) => entry.widgetId === id)
		expect(cell('my-scope')).toMatchObject({ track: 'side', gridY: 0 })
		expect(cell('my-team')).toMatchObject({ track: 'side', gridY: 1 })
		expect(cell('my-week').gridY).toBeGreaterThan(cell('my-team').gridY)
		const ids = config.layout.map((entry) => entry.id)
		expect(new Set(ids).size).toBe(ids.length)
	})

	it('passes the scope and member to every list of the work and to its download', () => {
		for (const id of ['my-items-phone', 'my-answer-today', 'my-waiting-on-me', 'my-waiting-on-customer']) {
			expect(widget(id).content.endpointSource.url, id).toContain(SCOPE_PARAMS)
		}
		const download = config.headerActions.find((action) => action.id === 'my-work-download')
		expect(download.href).toContain(SCOPE_PARAMS)
	})

	it('counts the week of the chosen scope', () => {
		for (const stat of widget('my-week').content.stats) {
			expect(stat.endpointSource.url).toBe('/apps/pipelinq/api/my-work/week-stats?scope=@workspace.scope')
		}
	})

	it('has Dutch for every label it shows, the scope names included', () => {
		for (const text of ['Whose work', 'Your team', 'Team member', 'Back to team', 'My items', 'Team', 'Organisation']) {
			expect(nl.translations[text], text).toBeTruthy()
		}
	})
})
