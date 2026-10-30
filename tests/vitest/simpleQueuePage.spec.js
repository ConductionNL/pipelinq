/**
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * The queue in the simple structure, as board PqWachtrij: Download and Take the
 * oldest in the header, three tiles, the channel strip, Take on and View per
 * row, and the team and agreements cards beside the list. The full structure
 * keeps its queue page as it was.
 *
 * @spec openspec/changes/detail-pages-read-at-a-glance/specs/my-work/spec.md
 */
import { buildManifest } from '@conduction/nextcloud-vue/src/utils/buildManifest.js'
import fs from 'fs'
import path from 'path'
import { describe, expect, it } from 'vitest'
import { applyMenuModules } from '../../src/utils/menuModules.js'
import { buildProfiledManifest } from '../../src/utils/structureProfile.js'

const ROOT = path.resolve(__dirname, '../..')
const read = (...parts) => fs.readFileSync(path.join(ROOT, ...parts), 'utf8')
const readJson = (...parts) => JSON.parse(read(...parts))
const fragments = fs
	.readdirSync(path.join(ROOT, 'src', 'manifest.d'))
	.filter((name) => name.endsWith('.json'))
	.sort()
	.map((name) => readJson('src', 'manifest.d', name))
function build(file) {
	return buildProfiledManifest(
		buildManifest,
		readJson('src', 'manifest.json'),
		fragments,
		file,
	)
}
const pageOf = (built) => built.pages.find((page) => page.id === 'Queue')
const simple = pageOf(
	build(applyMenuModules(readJson('src', 'menu-layout.simple.json'), [])),
).config
const full = pageOf(build(readJson('src', 'menu-layout.json')))
const base = readJson('src', 'manifest.json').pages.find(
	(page) => page.id === 'Queue',
)
const routes = read('appinfo', 'routes.php')
const en = readJson('l10n', 'en.json').translations
const nl = readJson('l10n', 'nl.json').translations

describe('the queue, simple structure', () => {
	it('leaves the full structure as it was', () => {
		expect(full.config.headerButtons).toEqual(base.config.headerButtons)
		expect(full.config.sidePanel).toEqual(base.config.sidePanel)
		expect(full.config.statRow).toEqual(base.config.statRow)
		expect(full.config.columns).toEqual(base.config.columns)
	})

	it('offers Take the oldest in the header, backed by a route', () => {
		expect(simple.headerButtons.map((button) => button.action)).toEqual([
			'export',
			'queue-take-oldest',
		])
		const action = simple.headerActions.find(
			(item) => item.id === 'queue-take-oldest',
		)
		expect(action).toMatchObject({ type: 'api-call', method: 'POST' })
		expect(action.url).toBe('/apps/pipelinq/api/tickets/assign-oldest')
		expect(routes).toContain("'url' => '/api/tickets/assign-oldest'")
	})

	it('draws three tiles, the oldest wait second, from the same filter as the list', () => {
		expect(simple.statRow.map((tile) => tile.id)).toEqual([
			'queue-unassigned',
			'queue-longest-waiting',
			'queue-within-deadline',
		])
		const longest = simple.statRow[1].content
		expect(longest.display).toBe('countdown')
		expect(longest.endpointSource.params.assignee).toBe(
			base.config.filter.assignee,
		)
		expect(longest.endpointSource.params.status_notIn).toEqual(
			base.config.filter.status_notIn,
		)
		expect(longest.endpointSource.params['_order[occurredAt]']).toBe('asc')
	})

	it('picks rows up rather than selecting them: Take on and View inline, no checkbox', () => {
		expect(simple.selectable).toBe(false)
		expect(simple.inlineActionCount).toBe(2)
		expect(simple.actions.map((action) => action.id)).toEqual([
			'assign-to-me',
			'view',
		])
		expect(simple.filterStrip.field).toBe('channel')
		// The first pill reads "Alle", as the type lenses above it and board
		// PqWachtrij write it, not the library's own "Alles".
		expect(simple.filterStrip.allLabel).toBe('All')
		// channel has no enum and each ticket type stores its own spelling, so
		// the strip names its pills and gathers the spellings under each
		expect(simple.filterStrip.options).toEqual([
			{ values: ['phone', 'telefoon'], label: 'Phone' },
			{ values: ['portal', 'web'], label: 'Portal' },
			{ values: ['email'], label: 'Email' },
			{ values: ['balie', 'counter'], label: 'Desk' },
		])
		expect(simple.columns.map((column) => column.key)).toEqual([
			'occurredAt',
			'title',
			'ticketType',
			'priority',
		])
	})

	it('shows the team load and the agreements beside the list', () => {
		expect(simple.sidePanel.map((card) => card.id)).toEqual([
			'queue-team',
			'queue-agreements',
		])
		expect(simple.sidePanel[0].content.endpointSource.url).toBe(
			'/apps/pipelinq/api/routing/team-load',
		)
		expect(routes).toContain("'url' => '/api/routing/team-load'")
	})

	it('has every word it adds in English and Dutch', () => {
		const overlay = readJson('src', 'menu-layout.simple.json').pages.find(
			(page) => page.id === 'Queue',
		)
		const words = [
			...overlay.config.headerButtons.map((button) => button.label),
			...overlay.configAppend.headerActions.flatMap((action) => [
				action.label,
				action.successMessage,
				action.errorMessage,
			]),
			...overlay.configAppend.statRow.flatMap((tile) => [
				tile.title,
				tile.content.caption,
				...(tile.content.countdown ? [tile.content.countdown.pastLabel] : []),
			]),
			...overlay.config.sidePanel.map((card) => card.title),
			overlay.config.sidePanel[1].content.text,
			overlay.config.sidePanel[1].content.link.label,
			overlay.config.footerNote,
			overlay.config.filterStrip.label,
			overlay.config.filterStrip.allLabel,
			...overlay.config.filterStrip.options.map((option) => option.label),
		]
		for (const word of words) {
			expect(en[word], word).toBeTruthy()
			expect(nl[word], word).toBeTruthy()
		}
	})
})
