// @vitest-environment jsdom
/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 * SPDX-License-Identifier: EUPL-1.2
 *
 * The contact centre dashboard and the tickets list in the simple structure.
 *
 * Both get their simple shape from overlays in `src/menu-layout.simple.json`.
 * What has to stay true:
 *   - the full structure keeps both pages as they are;
 *   - no widget and no view is dropped;
 *   - every number is a filter on fields the schema has;
 *   - a number and the list it links to use the same filter.
 *
 * @spec openspec/changes/simple-contact-dashboard/specs/kcc-werkplek/spec.md
 */

import { buildManifest } from '@conduction/nextcloud-vue/src/utils/buildManifest.js'
import {
	compareVisibleWhen,
	readVisibleWhenValue,
} from '@conduction/nextcloud-vue/src/utils/visibleWhen.js'
import { execFileSync } from 'child_process'
import fs from 'fs'
import os from 'os'
import path from 'path'
import { afterEach, describe, expect, it, vi } from 'vitest'
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
const fullFile = readJson('src', 'menu-layout.json')
const simpleFile = readJson('src', 'menu-layout.simple.json')
const en = readJson('l10n', 'en.json').translations
const nl = readJson('l10n', 'nl.json').translations

const manifest = () => readJson('src', 'manifest.json')
function build(file) {
	return buildProfiledManifest(buildManifest, manifest(), fragments, file)
}
const builtSimple = build(applyMenuModules(simpleFile, []))
const builtFull = build(fullFile)
function page(built, id) {
	return built.pages.find((item) => item.id === id)
}
const pageIds = new Set(builtSimple.pages.map((item) => item.id))

/** The properties of a schema: the main register plus every fragment. */
function propertiesOf(schema) {
	const files = [
		readJson('lib', 'Settings', 'pipelinq_register.json'),
		...fs
			.readdirSync(path.join(ROOT, 'lib', 'Settings', 'register.d'))
			.filter((name) => name.endsWith('.json'))
			.map((name) => readJson('lib', 'Settings', 'register.d', name)),
	]
	// Per property, like the loader's deep merge: a fragment that adds only
	// `order` to a property must not drop the rest of its definition.
	const merged = {}
	for (const file of files) {
		const properties = file.components?.schemas?.[schema]?.properties || {}
		for (const [key, definition] of Object.entries(properties)) {
			merged[key] = { ...(merged[key] || {}), ...definition }
		}
	}
	return merged
}
const SCHEMAS = { ticket: propertiesOf('ticket'), crmTask: propertiesOf('crmTask'), client: propertiesOf('client') }

/**
 * A source filter as the query a list reads: `{ a: { lt: x } }` is `a[lt]=x`.
 *
 * @param {object} filter The widget's source filter.
 * @return {object} The flat query.
 */
function asQuery(filter) {
	const query = {}
	for (const [key, value] of Object.entries(filter)) {
		if (value && typeof value === 'object') {
			for (const [op, inner] of Object.entries(value)) {
				query[`${key}[${op}]`] = inner
			}
		} else {
			query[key] = value
		}
	}
	return query
}

describe('the full structure', () => {
	it('keeps the dashboard and the tickets list as they are', () => {
		const source = [
			...manifest().pages,
			...fragments.flatMap((f) => f.pages || []),
		]
		expect(page(builtFull, 'KccWerkplek')).toEqual(
			source.find((item) => item.id === 'KccWerkplek'),
		)
		// The tickets list differs only in the header row nextcloud-vue 2.62.0
		// turned on, which the full profile holds back (menu-layout.json
		// pageDefaults), so it renders as it did on ^2.61.0.
		const tickets = source.find((item) => item.id === 'Tickets')
		expect(page(builtFull, 'Tickets')).toEqual({
			...tickets,
			config: { ...tickets.config, headerFilters: false },
		})
	})
})

describe('the dashboard', () => {
	const simple = page(builtSimple, 'KccWerkplek').config
	const before = page(builtFull, 'KccWerkplek').config
	const added = simple.widgets.filter((widget) => widget.id.startsWith('simple-'))
	const byId = Object.fromEntries(
		simple.widgets.map((widget) => [widget.id, widget]),
	)

	it('keeps every widget it had, unchanged', () => {
		// The in-call state (board PqWerkplekKlant) hides a widget by a
		// workspace flag; nothing else about a kept widget may change.
		const withoutInCall = ({ visibleWhen, ...rest }) => rest
		for (const widget of before.widgets) {
			expect(withoutInCall(byId[widget.id]), widget.id).toEqual(withoutInCall(widget))
		}
		expect(simple.widgets).toHaveLength(before.widgets.length + added.length)
		expect(added).toHaveLength(13)
	})

	it('moves the old cards down by one fixed number of rows and changes nothing else', () => {
		const shifts = new Set()
		for (const entry of before.layout) {
			const now = simple.layout.find((item) => item.id === entry.id)
			expect(now, entry.id).toBeTruthy()
			// `track` puts a card on the main or side column (gridSideColumn).
			const { track, ...kept } = now
			expect({ ...kept, gridY: entry.gridY }, entry.id).toEqual(entry)
			shifts.add(now.gridY - entry.gridY)
		}
		expect([...shifts]).toEqual([16])
	})

	it('puts every card in the grid once, and no two cards in one cell', () => {
		const taken = new Map()
		for (const entry of simple.layout) {
			expect(byId[entry.widgetId], entry.widgetId).toBeTruthy()
			expect(entry.gridX + entry.gridWidth).toBeLessThanOrEqual(12)
			for (let x = entry.gridX; x < entry.gridX + entry.gridWidth; x++) {
				for (let y = entry.gridY; y < entry.gridY + entry.gridHeight; y++) {
					const cell = `${x},${y}`
					expect(
						taken.get(cell),
						`${cell}: ${entry.widgetId}`,
					).toBeUndefined()
					taken.set(cell, entry.widgetId)
				}
			}
		}
		expect(new Set(simple.layout.map((entry) => entry.widgetId)).size).toBe(
			simple.widgets.length,
		)
	})

	it('opens with the page header, the attention card and four numbers in a row', () => {
		const top = simple.layout
			.filter((entry) => entry.gridY < 7)
			.sort((a, b) => a.gridY - b.gridY || a.gridX - b.gridX)
			.map((entry) => byId[entry.widgetId].type)
		expect(top).toEqual([
			'banner',
			'stat',
			'stat',
			'stat',
			'stat',
			'workspace-filter',
		])
		// PqDashboard: the page header carries the title, then "Klantcontact ·
		// <date> · <availability line>" and the actions; availability is the
		// page's own actions component, first in the row, so no grid cell
		// repeats it.
		expect(simple.showHeader).toBe(true)
		expect(simple.greeting).toEqual({ label: 'Contact centre' })
		expect(simple.descriptionSource).toEqual({ url: '/apps/pipelinq/api/kcc-werkplek/state', path: 'availabilityLine' })
		expect(simple.actionsPlacement).toBe('start')
		expect(page(builtSimple, 'KccWerkplek').title).toBe('My customer contact')
		expect(byId['simple-greeting']).toBeUndefined()
		expect(byId['simple-availability']).toBeUndefined()
		const stats = simple.layout.filter(
			(entry) => byId[entry.widgetId].type === 'stat' && entry.gridY === 2,
		)
		expect(stats.map((entry) => entry.gridWidth)).toEqual([3, 3, 3, 3])
	})

	it("draws the reader's own work in two thirds and what the reader looks up in the right third", () => {
		const at = (id) => simple.layout.find((entry) => entry.widgetId === id)
		expect(at('simple-waiting-list')).toMatchObject({
			gridX: 0,
			gridY: 7,
			gridWidth: 8,
		})
		expect(at('simple-per-channel')).toMatchObject({
			gridX: 0,
			gridY: 12,
			gridWidth: 8,
		})
		expect(at('simple-callback-list')).toMatchObject({
			gridX: 8,
			gridY: 7,
			gridWidth: 4,
		})
		expect(at('simple-latest-contact')).toMatchObject({
			gridX: 8,
			gridY: 12,
			gridWidth: 4,
		})
		// The cards the page had start at row 16. The board puts the Start a
		// contact card (row 5) above the lists (amended 10 Oct 2026). Rows are
		// the heights the grid settles on once the cards size to their content.
		for (const id of ['simple-per-channel', 'simple-latest-contact']) {
			expect(at(id).gridY + at(id).gridHeight, id).toBeLessThanOrEqual(16)
		}
		// The grid does not float, so every card closes up behind the one above.
		expect(simple.gridFloat).toBe(false)
	})

	it('offers one primary action on the attention card: answer the first ticket', () => {
		const card = byId['simple-first-today'].content
		expect(
			card.actions.filter((action) => action.primary === true),
		).toHaveLength(1)
		expect(card.actions[0].primary).toBe(true)
		expect(card.actions[0].route).toEqual({ name: 'TicketDetail', params: { id: '@subject.id' } })
	})

	it('shows the type of a waiting ticket as a pill with the labels the ticket page uses', () => {
		const pill = byId['simple-waiting-list'].content.pill
		expect(pill.field).toBe('ticketType')
		expect(Object.keys(pill.labels).sort()).toEqual(
			[...SCHEMAS.ticket.ticketType.enum].sort(),
		)
		expect(pill.labels).toEqual(
			page(builtSimple, 'TicketDetail').config.typePill.labels,
		)
		for (const label of Object.values(pill.labels)) {
			expect(en[label], `en "${label}"`).toBeTruthy()
			expect(nl[label], `nl "${label}"`).toBeTruthy()
		}
	})

	it('links Waiting for me to the whole queue', () => {
		const entry = simple.layout.find(
			(item) => item.widgetId === 'simple-waiting-list',
		)
		expect(entry.headerLink.label).toBe('Whole queue')
		expect(pageIds.has(entry.headerLink.route)).toBe(true)
	})

	it('counts only on fields the schema has', () => {
		let sources = 0
		for (const widget of added) {
			const content = widget.content || {}
			const source =
				content.source
				|| content.visibleWhen?.source
				|| (content.schema ? content : null)
			if (!source) {
				continue
			}
			sources += 1
			expect(source.register, widget.id).toBe('pipelinq')
			const properties = SCHEMAS[source.schema]
			expect(properties, `${widget.id}: ${source.schema}`).toBeTruthy()
			for (const key of [
				// A flat operator key, `field[lt]`, names the field before the bracket.
				...Object.keys(source.filter || {}).map((name) =>
					name.replace(/\[\w+\]$/, ''),
				),
				...(source.groupBy ? [source.groupBy] : []),
				...(content.sort ? [content.sort.field] : []),
				...(content.columns || []).map((column) => column.key),
			]) {
				expect(properties[key], `${widget.id}: ${key}`).toBeTruthy()
			}
			for (const [key, value] of Object.entries(source.filter || {})) {
				const allowed = properties[key.replace(/\[\w+\]$/, '')].enum
				if (allowed && typeof value === 'string') {
					expect(allowed, `${widget.id}: ${key}=${value}`).toContain(value)
				}
			}
		}
		// Four stats, three lists, the bar, the in-call task list and the in-call
		// "Who is it" card; the banner reads an endpoint.
		expect(sources).toBe(10)
	})

	it('links every number to the list with the same filter', () => {
		const stats = added.filter((widget) => widget.type === 'stat')
		expect(stats).toHaveLength(4)
		for (const widget of stats) {
			const { source, route } = widget.content
			expect(pageIds.has(route.name), widget.id).toBe(true)
			expect(route.query, widget.id).toEqual(asQuery(source.filter))
			const target = page(builtSimple, route.name)
			expect(target.type, widget.id).toBe('index')
			expect(target.config.schema, widget.id).toBe(source.schema)
		}
		const banner = byId['simple-first-today'].content
		for (const action of banner.actions) {
			const name =
				typeof action.route === 'string' ? action.route : action.route.name
			expect(pageIds.has(name), action.label).toBe(true)
		}
	})

	it('shows under Waiting for me exactly what the number of that name counts', () => {
		expect(byId['simple-waiting-list'].content.filter).toEqual(
			byId['simple-waiting-for-me'].content.source.filter,
		)
		expect(byId['simple-callback-list'].content.filter).toEqual(
			byId['simple-callbacks'].content.source.filter,
		)
		// Longest wait first.
		expect(byId['simple-waiting-list'].content.sort).toEqual({
			field: 'occurredAt',
			dir: 'asc',
		})
	})

	it('words the first ticket as the board does: who asks about what, and through which channel', async () => {
		const card = byId['simple-first-today'].content
		expect(card.title).toBe('Question from {subject.clientName} about {subject.title}')
		expect(card.fallbackTitle).toBe('{subject.title}')
		expect(card.reason).toBe('Via {subject.channel|enumWord} · waiting {subject.waitingDays} days for an answer.')
		expect(nl[card.title]).toBe('Vraag van {subject.clientName} over {subject.title}')
		const { CELL_FORMATTERS } = await import('../../src/services/cellFormatters.js')
		expect(CELL_FORMATTERS.enumWord('phone').toLowerCase()).toBe(CELL_FORMATTERS.enumWord('phone'))
		expect(CELL_FORMATTERS.enumWord('')).toBe('')
	})

	it('marks each latest contact with the small board dot and offers the example client', () => {
		expect(byId['simple-latest-contact'].content.dot).toEqual({ color: 'var(--color-primary-element)', size: 10 })
		expect(byId['simple-client-in-focus'].content.link).toEqual({ label: 'See an example', value: '@config.example_client?' })
	})

	it('opens with no client in focus, so the search is empty and the client cards ask for one', () => {
		// Without a default the object picker puts the first client in focus.
		expect(byId['simple-client-in-focus'].content.default).toBe('')
	})

	it('opens a row on a page that exists', () => {
		const lists = added.filter((item) => item.type === 'record-list')
		expect(lists).toHaveLength(3)
		for (const widget of lists) {
			expect(pageIds.has(widget.content.rowRoute), widget.id).toBe(true)
		}
	})

	it('keeps the number labels short enough to fit a quarter of the row', () => {
		for (const widget of added.filter((item) => item.type === 'stat')) {
			const label = widget.content.label
			expect(label.length, label).toBeLessThanOrEqual(22)
			expect(nl[label].length, nl[label]).toBeLessThanOrEqual(22)
			// The board draws the four numbers without an icon.
			expect(widget.content.icon, widget.id).toBeUndefined()
		}
	})
})

describe('the attention card asks a question OpenRegister can answer', () => {
	/**
	 * Every `visibleWhen.source` in a value, however deep.
	 *
	 * @param {unknown} value A page config.
	 * @return {Array<object>} The sources.
	 */
	function visibleWhenSources(value) {
		if (Array.isArray(value)) {
			return value.flatMap(visibleWhenSources)
		}
		if (!value || typeof value !== 'object') {
			return []
		}
		const own = value.visibleWhen?.source ? [value.visibleWhen.source] : []
		return [...own, ...Object.values(value).flatMap(visibleWhenSources)]
	}

	it('holds no nested operator in any visibleWhen source, on any page of the simple structure', () => {
		const sources = builtSimple.pages.flatMap((item) =>
			visibleWhenSources(item.config || {}),
		)
		for (const source of sources) {
			for (const [key, value] of Object.entries(source.filter || {})) {
				expect(
					value !== null && typeof value === 'object',
					`${source.schema}.${key} is nested: the query builder writes it as JSON`,
				).toBe(false)
			}
		}
	})

	const card = () =>
		page(builtSimple, 'KccWerkplek').config.widgets.find(
			(widget) => widget.id === 'simple-first-today',
		).content

	afterEach(() => {
		vi.unstubAllGlobals()
	})

	it('carries text, or the dashboard gives up its cell before reading the condition', () => {
		// nextcloud-vue 2.60.0 collapses a banner whose `text` is empty,
		// whatever its condition says. The title is what the card shows.
		expect(card().text).toBeTruthy()
		// The text is the ticket's own title, which the title falls back to.
		expect(card().text).toBe(card().fallbackTitle)
		expect(card().layout).toBe('attention')
	})

	it.each([
		[{ found: true, id: 'T1', title: 'Afvalpas werkt niet bij de container', clientName: 'M. de Graaf', waitingDays: 3 }, true],
		[{ found: false }, false],
	])('names the first ticket the endpoint answers (%o): %s', async (answer, met) => {
		const asked = []
		vi.stubGlobal(
			'fetch',
			vi.fn(async (url) => {
				asked.push(String(url))
				return { ok: true, status: 200, json: async () => answer }
			}),
		)
		const cond = card().visibleWhen
		const sink = {}
		// The library's own reader and comparison, as the dashboard runs them.
		const actual = await readVisibleWhenValue(cond, {}, sink)
		expect(compareVisibleWhen(actual, cond.op, cond.value, {})).toBe(met)
		expect(asked).toHaveLength(1)
		expect(asked[0]).toContain('/apps/pipelinq/api/worklist/first')
		expect(sink.row).toEqual(answer)
	})

	it('shows dates in the lists as dates, not as stored text', () => {
		const widgets = page(builtSimple, 'KccWerkplek').config.widgets.filter(
			(widget) =>
				widget.id.startsWith('simple-') && widget.type === 'record-list',
		)
		let dates = 0
		for (const widget of widgets) {
			const text = JSON.stringify(widget.content)
			for (const [, field, format] of text.matchAll(/\{([\w.]+)(?:\|(\w+))?\}/g)) {
				const property = SCHEMAS[widget.content.schema][field]
				if (property?.format === 'date-time') {
					dates += 1
					expect(format, `${widget.id}: ${field}`).toBeTruthy()
				}
			}
		}
		expect(dates).toBe(3)
	})
})

describe('the board PqDashboard', () => {
	const simple = page(builtSimple, 'KccWerkplek').config
	const byId = Object.fromEntries(simple.widgets.map((widget) => [widget.id, widget]))

	it('draws the side column at the board\'s 320 pixels beside the main column', () => {
		expect(simple.gridSideColumn).toEqual({ width: 320 })
		for (const id of ['simple-callback-list', 'simple-latest-contact']) {
			expect(simple.layout.find((entry) => entry.widgetId === id).gridX, id).toBe(8)
		}
	})

	it('puts every card beside the side column on its track, so the side column is 320 wide', () => {
		// Rows 0-6 are the attention card, the four counts and the client
		// picker: full width or four equal tiles, on no track.
		for (const entry of simple.layout) {
			const end = entry.gridX + entry.gridWidth
			let expected
			if (entry.gridY >= 7) {
				expect(end <= 8 || entry.gridX >= 8, entry.id).toBe(true)
				expected = entry.gridX >= 8 ? 'side' : 'main'
			}
			expect(entry.track, entry.id).toBe(expected)
		}
	})

	it('spaces its cards 20 pixels apart, as the board and the Dq dashboards do', () => {
		expect(simple.gridMargin).toBe(10)
	})

	it('writes the availability button at the header buttons\' 14px', () => {
		const style = read('src', 'components', 'werkplek', 'WerkplekAgentStatus.vue').split('<style')[1]
		const toggle = style.match(/\.werkplek-agent-status__toggle \{([^}]*)\}/)[1]
		expect(toggle).toMatch(/font-size: 14px;/)
	})

	it('draws the log form\'s summary three rows high', () => {
		expect(byId.interaction.content.summaryRows).toBe(3)
	})

	it('rules above the first row of every list, as under the card title', () => {
		for (const id of ['simple-waiting-list', 'simple-callback-list', 'simple-latest-contact']) {
			expect(byId[id].content.ruleAbove, id).toBe(true)
		}
	})

	it('lists a callback by the client, with what to call about and when it is due', () => {
		const list = byId['simple-callback-list'].content
		expect(list.extend).toEqual(['clientId'])
		expect(SCHEMAS.crmTask.clientId.$ref).toBe('client')
		expect(list.title).toEqual(['{clientId.name}', '{subject}'])
		expect(list.meta).toBe('{subject}')
		expect(list.trailing).toBe('{deadline|soon}')
	})

	it('says how long ago the latest contact was', () => {
		expect(byId['simple-latest-contact'].content.meta).toBe('{occurredAt|ago}')
	})

	it('names every channel the seeded contact moments use', () => {
		const labels = byId['simple-per-channel'].content.labels
		for (const channel of ['telefoon', 'phone', 'email', 'balie', 'web', 'portal', 'chat', 'brief', 'sms', 'whatsapp']) {
			expect(labels[channel], channel).toBeTruthy()
		}
	})

	it('names the client search for what it does', () => {
		expect(byId['simple-client-in-focus'].content.objectSource.ariaLabel).toBe('Client search')
	})
})

describe('the tickets list', () => {
	const simple = page(builtSimple, 'Tickets').config
	const before = page(builtFull, 'Tickets').config
	const view = (label) => simple.quickFilters.find((item) => item.label === label)

	it('keeps every view it had, with the same filter, and counts them', () => {
		for (const old of before.quickFilters) {
			// The board's list leaves contact moments out of All (they have their
			// own view), so All carries that one filter and stays the view the
			// list opens on. Waiting for customer is the agent's own, as the
			// dashboard number that links here counts it. Every other view is
			// untouched.
			const changed = {
				'All': { default: true, filter: { 'ticketType[ne]': 'interaction' } },
				'All open': { default: false },
				'Waiting for customer': { filter: { assignee: '@me', status: 'awaiting_customer' } },
			}
			const expected = { ...old, ...(changed[old.label] || {}) }
			expect(view(old.label), old.label).toEqual({
				...expected,
				showCount: true,
			})
		}
		expect(simple.quickFilters).toHaveLength(before.quickFilters.length + 2)
		expect(simple.quickFilters.every((item) => item.showCount)).toBe(true)
	})

	it('shows five views first and keeps the rest behind the overflow', () => {
		expect(simple.quickFilterMaxVisible).toBe(6)
		expect(simple.quickFilters.map((item) => item.label)).toEqual([
			'All',
			'Waiting for me',
			'New',
			'Waiting for customer',
			'Requests',
			'Complaints',
			'All open',
			'Mine',
			'Unassigned',
			'Past the deadline',
			'Contactmomenten',
		])
		expect(simple.quickFilters.filter((item) => item.default)).toHaveLength(1)
	})

	it('counts the same tickets as the dashboard numbers that link here', () => {
		const dashboard = page(builtSimple, 'KccWerkplek').config.widgets
		const stat = (id) =>
			dashboard.find((widget) => widget.id === id).content.source.filter
		expect(view('Waiting for me').filter).toEqual(stat('simple-waiting-for-me'))
		expect(view('New').filter).toEqual(stat('simple-new'))
		expect(view('Waiting for customer').filter).toEqual(
			stat('simple-waiting-for-customer'),
		)
	})

	it('filters and shows only fields a ticket has', () => {
		for (const item of simple.quickFilters) {
			for (const key of Object.keys(item.filter).map((name) =>
				name.replace(/\[\w+\]$/, ''),
			)) {
				expect(SCHEMAS.ticket[key], `${item.label}: ${key}`).toBeTruthy()
			}
		}
		for (const column of simple.columns) {
			const key = typeof column === 'string' ? column : column.key
			expect(SCHEMAS.ticket[key], key).toBeTruthy()
		}
		// Nothing the list showed is gone, except the direction of a contact
		// moment, which made room for the handler and the deadline.
		const keys = simple.columns.map((column) =>
			typeof column === 'string' ? column : column.key,
		)
		for (const old of before.columns) {
			if (old !== 'direction' && old !== 'priority') {
				expect(keys, old).toContain(old)
			}
		}
	})

	it('shows the handler as an avatar, last, as the board draws it', () => {
		const column = (key) =>
			simple.columns.find(
				(item) => typeof item === 'object' && item.key === key,
			)
		expect(column('assignee')).toMatchObject({
			widget: 'avatar',
			widgetProps: { user: true },
		})
		const last = simple.columns[simple.columns.length - 1]
		expect(last.key).toBe('assignee')
		expect(SCHEMAS.ticket.slaDeadline.format).toBe('date-time')
	})
})

describe('the words', () => {
	it('exist in English and Dutch for every label the two overlays add', () => {
		const words = new Set()
		const TEXT = [
			'label',
			'title',
			'kicker',
			'reason',
			'emptyText',
			'description',
		]
		const collect = (value, key) => {
			if (Array.isArray(value)) {
				value.forEach((item) => collect(item, key))
			} else if (value && typeof value === 'object') {
				for (const [name, inner] of Object.entries(value)) {
					collect(inner, key === 'labels' ? 'label' : name)
				}
			} else if (
				typeof value === 'string'
				&& TEXT.includes(key)
				// A row template that is one field ("{title}") holds no words.
				&& !/^\{[^}]+\}$/.test(value)
			) {
				words.add(value)
			}
		}
		for (const id of ['KccWerkplek', 'Tickets']) {
			const { _note, ...overlay } = simpleFile.pages.find(
				(item) => item.id === id,
			)
			collect(overlay, '')
		}
		expect(words.size).toBeGreaterThan(30)
		for (const word of words) {
			expect(en[word], `en "${word}"`).toBeTruthy()
			expect(nl[word], `nl "${word}"`).toBeTruthy()
		}
		expect(nl['Waiting for me']).toBe('Wacht op mij')
		expect(nl['By phone']).toBe('Telefoon')
	})
})

describe('the werkplek in a call (board PqWerkplekKlant)', () => {
	const page = () => simpleFile.pages.find((item) => item.id === 'KccWerkplek')
	const who = () => page().configAppend.widgets.find((widget) => widget.id === 'simple-call-who')

	it('names the caller in the title while the call is on', () => {
		expect(page().config.titleWhen).toEqual([{ when: { workspace: 'callActive', op: 'eq', value: true }, title: 'Call with {workspace.selectedClientName}' }])
	})

	it('says who is calling: the customer in the call, what was checked, the source, preference and language', () => {
		expect(who().type).toBe('facts')
		expect(who().visibleWhen).toEqual({ workspace: 'callActive', op: 'eq', value: true })
		expect(who().content.source).toEqual({ register: 'pipelinq', schema: 'client', id: '@workspace.selectedClient' })
		expect(who().content.fields.map((field) => field.path || field.as || field.label)).toEqual(['name', 'line', 'identityCheck', 'Source', 'source', 'preferredChannel', 'language'])
		// "Bron: BRP, opgezocht 10:24, vastgelegd"; the client's own source stays as "Aangemeld via".
		expect(who().content.fields.find((field) => field.label === 'Source').template).toBe('{identityVerifiedVia}, looked up {identityVerifiedAt:today-time}, recorded')
		// "Opzoeken in de BRP" verifies the identity of the customer in the call.
		expect(who().content.actions).toEqual([{ type: 'open-modal', target: 'BrpClientCheckDialog', label: 'Look up in the BRP', props: { clientId: '@source.id' }, variant: 'tonal' }])
	})
})

describe('the page header loses nothing', () => {
	const simple = page(builtSimple, 'KccWerkplek')
	const full = page(builtFull, 'KccWerkplek')
	const layoutOf = (widgetId) =>
		simple.config.layout.find((entry) => entry.widgetId === widgetId)

	it('shows the header in the simple structure, and hides the widget actions there only', () => {
		expect(simple.config.showHeader).toBe(true)
		expect(simple.config.showWidgetActions).toBe(false)
		expect(full.config.showHeader).toBeUndefined()
		expect(full.config.showWidgetActions).toBeUndefined()
	})

	it('moves New request to the navigation, as the same dialog', () => {
		const headerAction = full.config.headerActions.find(
			(action) => action.id === 'new-request',
		)
		const primary = simpleFile.nav.primaryAction
		expect(primary.action).toMatchObject({
			type: 'open-modal',
			target: headerAction.target,
		})
		expect(fullFile.nav?.primaryAction).toBeUndefined()
	})

	it('keeps the availability switch in the header and the client in focus above the cards that use it', () => {
		expect(simple.actionsComponent).toBe(full.actionsComponent)
		// The card is the library's start card (workspace-filter), not a slot:
		// it writes the same workspace key the full page's picker writes.
		const startCard = simple.config.widgets.find(
			(widget) => widget.id === 'simple-client-in-focus',
		)
		expect(startCard.type).toBe('workspace-filter')
		expect(startCard.content.layout).toBe('start')
		expect(startCard.content.writes).toBe('@workspace.selectedClient')
		expect(simple.slots['widget-simple-client-in-focus']).toBeUndefined()
		expect(full.slots['title-meta']).toBe('WerkplekClientSelect')
		// The start card picks the client, so the header carries no second picker.
		expect(simple.slots['title-meta']).toBeUndefined()
		const client = layoutOf('simple-client-in-focus')
		// At 1100 wide the description wraps to three lines: a fixed two-row
		// cell cut the client picker in half behind a scrollbar.
		expect(client.sizeToContent).toBe(true)
		const clientBound = full.config.widgets
			.filter((widget) =>
				JSON.stringify(widget.content || {}).includes(
					'@workspace.selectedClient',
				),
			)
			.map((widget) => layoutOf(widget.id))
		expect(clientBound.length).toBeGreaterThan(0)
		for (const entry of clientBound) {
			expect(entry.gridY, entry.widgetId).toBeGreaterThan(client.gridY)
		}
	})

	it('puts Settings and Help in the footer, with Help on the documentation the header linked', () => {
		expect(simpleFile.nav.footer).toEqual(['settings', 'help'])
		expect(simpleFile.nav.help.href).toBe(full.config.documentationUrl)
		expect(fullFile.nav?.footer).toBeUndefined()
	})
})

describe('the built pages', () => {
	it('validate against the manifest schema the installed library ships', () => {
		const dir = fs.mkdtempSync(path.join(os.tmpdir(), 'pipelinq-dashboard-'))
		const file = path.join(dir, 'manifest.json')
		fs.writeFileSync(file, JSON.stringify(builtSimple))
		try {
			execFileSync(
				'node',
				[path.join(ROOT, 'tests', 'validate-manifest.js')],
				{
					env: { ...process.env, APP_MANIFEST: file },
					stdio: 'pipe',
				},
			)
		} catch (error) {
			throw new Error(`${error.stdout}\n${error.stderr}`, { cause: error })
		} finally {
			fs.rmSync(dir, { recursive: true, force: true })
		}
	}, 60_000)
})
