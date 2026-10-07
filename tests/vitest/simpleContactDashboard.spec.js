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
import { buildQueryString } from '@conduction/nextcloud-vue/src/utils/headers.js'
import { resolveFilterTokens } from '@conduction/nextcloud-vue/src/utils/resolveFilterTokens.js'
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
const iconsSource = read('src', 'icons.js')
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
const SCHEMAS = { ticket: propertiesOf('ticket'), crmTask: propertiesOf('crmTask') }

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
		for (const id of ['KccWerkplek', 'Tickets']) {
			expect(page(builtFull, id), id).toEqual(
				source.find((item) => item.id === id),
			)
		}
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
		for (const widget of before.widgets) {
			expect(byId[widget.id], widget.id).toEqual(widget)
		}
		expect(simple.widgets).toHaveLength(before.widgets.length + added.length)
		expect(added).toHaveLength(10)
	})

	it('moves the old cards down by one fixed number of rows and changes nothing else', () => {
		const shifts = new Set()
		for (const entry of before.layout) {
			const now = simple.layout.find((item) => item.id === entry.id)
			expect(now, entry.id).toBeTruthy()
			expect({ ...now, gridY: entry.gridY }, entry.id).toEqual(entry)
			shifts.add(now.gridY - entry.gridY)
		}
		expect([...shifts]).toEqual([15])
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

	it('opens with the greeting, the attention card and four numbers in a row', () => {
		const top = simple.layout
			.filter((entry) => entry.gridY < 6)
			.sort((a, b) => a.gridY - b.gridY || a.gridX - b.gridX)
			.map((entry) => byId[entry.widgetId].type)
		expect(top).toEqual(['header', 'banner', 'stat', 'stat', 'stat', 'stat'])
		const stats = simple.layout.filter(
			(entry) => byId[entry.widgetId].type === 'stat' && entry.gridY === 4,
		)
		expect(stats.map((entry) => entry.gridWidth)).toEqual([3, 3, 3, 3])
	})

	it("draws the reader's own work in two thirds and what the reader looks up in the right third", () => {
		const at = (id) => simple.layout.find((entry) => entry.widgetId === id)
		expect(at('simple-waiting-list')).toMatchObject({
			gridX: 0,
			gridY: 6,
			gridWidth: 8,
		})
		expect(at('simple-per-channel')).toMatchObject({
			gridX: 0,
			gridY: 11,
			gridWidth: 8,
		})
		expect(at('simple-callback-list')).toMatchObject({
			gridX: 8,
			gridY: 6,
			gridWidth: 4,
		})
		expect(at('simple-latest-contact')).toMatchObject({
			gridX: 8,
			gridY: 11,
			gridWidth: 4,
		})
		// The cards the page had start at row 15; the design's end above it.
		for (const id of ['simple-per-channel', 'simple-latest-contact']) {
			expect(at(id).gridY + at(id).gridHeight, id).toBeLessThanOrEqual(15)
		}
	})

	it('offers one primary action on the attention card: the late tickets', () => {
		const card = byId['simple-first-today'].content
		expect(
			card.actions.filter((action) => action.primary === true),
		).toHaveLength(1)
		expect(card.actions[0].primary).toBe(true)
		expect(card.actions[0].route.query).toEqual(card.visibleWhen.source.filter)
	})

	it('shows the type of a waiting ticket as a pill with the labels the ticket page uses', () => {
		const column = byId['simple-waiting-list'].content.columns.find(
			(item) => item.key === 'ticketType',
		)
		expect(column.enum).toEqual(SCHEMAS.ticket.ticketType.enum)
		expect(column.enumLabels).toEqual(
			page(builtSimple, 'TicketDetail').config.typePill.labels,
		)
		for (const label of Object.values(column.enumLabels)) {
			expect(en[label], `en "${label}"`).toBeTruthy()
			expect(nl[label], `nl "${label}"`).toBeTruthy()
		}
	})

	it('links Waiting for me to the whole list with the filter it counts by', () => {
		const list = byId['simple-waiting-list'].content
		expect(pageIds.has(list.viewAllRoute)).toBe(true)
		expect(list.viewAllQuery).toEqual(list.filter)
		expect(list.viewAllQuery).toEqual(
			byId['simple-waiting-for-me'].content.source.filter,
		)
	})

	it('counts only on fields the schema has', () => {
		let sources = 0
		for (const widget of added) {
			const content = widget.content
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
		// The banner, four stats, three lists and the bar.
		expect(sources).toBe(9)
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
		expect(banner.actions[0].route.query).toEqual(
			asQuery(banner.visibleWhen.source.filter),
		)
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

	it('opens a row on a page that exists', () => {
		for (const widget of added.filter((item) => item.type === 'object-list')) {
			expect(pageIds.has(widget.content.rowRoute), widget.id).toBe(true)
		}
	})

	it('keeps the number labels short enough to fit a quarter of the row', () => {
		for (const widget of added.filter((item) => item.type === 'stat')) {
			const label = widget.content.label
			expect(label.length, label).toBeLessThanOrEqual(22)
			expect(nl[label].length, nl[label]).toBeLessThanOrEqual(22)
			expect(iconsSource, widget.id).toContain(`\n\t${widget.content.icon},\n`)
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

	it('builds its count request with a flat operator key and no JSON in the address', () => {
		const source = page(builtSimple, 'KccWerkplek').config.widgets.find(
			(widget) => widget.id === 'simple-first-today',
		).content.visibleWhen.source
		// The two calls the library's own visibleWhen reader makes.
		const filter = resolveFilterTokens(source.filter, {})
		const query = buildQueryString({ ...filter, _limit: 1 })
		const address = decodeURIComponent(query)
		expect(address).toMatch(/slaDeadline\[lt\]=\d{4}-\d{2}-\d{2}(&|$)/)
		expect(address).toContain('status=in_progress')
		expect(address).not.toContain('{')
		expect(address).not.toContain('@today')
	})

	it('holds no nested operator in any visibleWhen source, on any page of the simple structure', () => {
		const sources = builtSimple.pages.flatMap((item) =>
			visibleWhenSources(item.config || {}),
		)
		// The control: there is at least the one this file is about.
		expect(sources.length).toBeGreaterThan(0)
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
		expect(card().text).toBe(card().title)
		expect(card().layout).toBe('attention')
	})

	it.each([
		[1, true],
		[0, false],
	])('is met with %i late ticket(s): %s', async (total, met) => {
		const asked = []
		vi.stubGlobal(
			'fetch',
			vi.fn(async (url) => {
				asked.push(String(url))
				return {
					ok: true,
					status: 200,
					json: async () => ({
						results: total > 0 ? [{ id: 'T1' }] : [],
						total,
						page: 1,
						pages: 1,
						limit: 1,
					}),
				}
			}),
		)
		const cond = card().visibleWhen
		// The library's own reader and comparison, as the dashboard runs them.
		const actual = await readVisibleWhenValue(cond, {})
		expect(actual).toBe(total)
		expect(compareVisibleWhen(actual, cond.op, cond.value, {})).toBe(met)
		expect(asked).toHaveLength(1)
		expect(asked[0]).toContain('/apps/openregister/api/objects/pipelinq/ticket?')
		expect(decodeURIComponent(asked[0])).not.toContain('{')
	})

	it('shows dates in the lists as dates, not as stored text', () => {
		const widgets = page(builtSimple, 'KccWerkplek').config.widgets.filter(
			(widget) =>
				widget.id.startsWith('simple-') && widget.type === 'object-list',
		)
		let dates = 0
		for (const widget of widgets) {
			for (const column of widget.content.columns) {
				if (
					SCHEMAS[widget.content.schema][column.key].format === 'date-time'
				) {
					dates += 1
					expect(column.format, `${widget.id}: ${column.key}`).toBe(
						'date-time',
					)
				}
			}
		}
		expect(dates).toBe(3)
	})
})

describe('the tickets list', () => {
	const simple = page(builtSimple, 'Tickets').config
	const before = page(builtFull, 'Tickets').config
	const view = (label) => simple.quickFilters.find((item) => item.label === label)

	it('keeps every view it had, with the same filter, and counts them', () => {
		for (const old of before.quickFilters) {
			expect(view(old.label), old.label).toEqual({ ...old, showCount: true })
		}
		expect(simple.quickFilters).toHaveLength(before.quickFilters.length + 3)
		expect(simple.quickFilters.every((item) => item.showCount)).toBe(true)
	})

	it('shows five views first and keeps the rest behind the overflow', () => {
		expect(simple.quickFilterMaxVisible).toBe(5)
		expect(simple.quickFilters.map((item) => item.label)).toEqual([
			'All',
			'Waiting for me',
			'New',
			'Tickets',
			'Complaints',
			'Contactmomenten',
			'Waiting for customer',
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
			for (const key of Object.keys(item.filter)) {
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

	it('shows the handler as an avatar and colours a deadline that is today or past', () => {
		const column = (key) =>
			simple.columns.find(
				(item) => typeof item === 'object' && item.key === key,
			)
		expect(column('assignee')).toMatchObject({
			widget: 'avatar',
			widgetProps: { user: true },
		})
		expect(column('slaDeadline').widgetProps.variantWhen).toEqual([
			{ op: 'lte', value: 0, variant: 'error' },
			{ op: 'lte', value: 2, variant: 'warning' },
		])
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
			} else if (typeof value === 'string' && TEXT.includes(key)) {
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
