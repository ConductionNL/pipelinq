/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 * SPDX-License-Identifier: EUPL-1.2
 *
 * `appinfo/attention.json`: what this app tells LaunchPad needs attention.
 *
 * LaunchPad's "First today" widget reads this file from every app a user has,
 * counts each item in OpenRegister as that user and shows one ranked list with
 * a link into the app. The contract is LaunchPad's
 * (`openspec/specs/attention-feed/spec.md`, REQ-ATT-001 and REQ-ATT-003 in
 * ConductionNL/launchpad); the rules below are a copy of its
 * `AttentionDeclarationValidator`, because that class is not available here.
 *
 * The file fails quietly on LaunchPad's side: an invalid file is left out of
 * the list, a filter that differs from the dashboard card shows a different
 * number than the app does, and a string without a translation is shown in
 * English. So every one of those is held here.
 *
 * @spec openspec/changes/simple-list-and-dashboard/specs/dashboard/spec.md
 */

import { buildManifest } from '@conduction/nextcloud-vue/src/utils/buildManifest.js'
import fs from 'fs'
import path from 'path'
import { describe, expect, it } from 'vitest'
import { buildProfiledManifest } from '../../src/utils/structureProfile.js'

const ROOT = path.resolve(__dirname, '../..')
const read = (...parts) => fs.readFileSync(path.join(ROOT, ...parts), 'utf8')
const readJson = (...parts) => JSON.parse(read(...parts))

const declaration = readJson('appinfo', 'attention.json')
const item = declaration.items.find((entry) => entry.id === 'tickets-past-deadline')

const fragments = fs
	.readdirSync(path.join(ROOT, 'src', 'manifest.d'))
	.filter((name) => name.endsWith('.json'))
	.sort()
	.map((name) => readJson('src', 'manifest.d', name))
const builtSimple = buildProfiledManifest(
	buildManifest,
	readJson('src', 'manifest.json'),
	fragments,
	readJson('src', 'menu-layout.simple.json'),
)
// The app's own "First today" card, as the simple dashboard ships it.
const card = builtSimple.pages
	.find((page) => page.id === 'KccWerkplek')
	.config.widgets.find((widget) => widget.id === 'simple-first-today').content

const SEVERITIES = ['error', 'warning', 'info']
const OPERATORS = ['gt', 'gte', 'lt', 'lte', 'eq', 'neq']
// The tokens LaunchPad resolves before it counts. Any other value that starts
// with `@` fails the count (REQ-ATT-003).
const TOKEN = /^@(me|now|today([+-]\d+d)?|monthStart|quarterStart|yearStart)$/

function isScalar(value) {
	return ['string', 'number', 'boolean'].includes(typeof value)
}
function isFlat(value) {
	return (
		isScalar(value)
		|| (Array.isArray(value) && value.length > 0 && value.every(isScalar))
	)
}

describe('appinfo/attention.json follows the attention feed contract', () => {
	it('is version 1 with a list of at most ten items, each id once', () => {
		expect(declaration.version).toBe(1)
		expect(Array.isArray(declaration.items)).toBe(true)
		expect(declaration.items.length).toBeGreaterThan(0)
		expect(declaration.items.length).toBeLessThanOrEqual(10)
		const ids = declaration.items.map((entry) => entry.id)
		expect(new Set(ids).size).toBe(ids.length)
	})

	it('gives every item an id, three texts, a known severity, operator and a numeric value', () => {
		for (const entry of declaration.items) {
			expect(entry.id).toMatch(/^[a-z0-9][a-z0-9-]{0,63}$/)
			for (const text of [entry.title, entry.reason, entry.action?.label]) {
				expect(typeof text, entry.id).toBe('string')
				expect(text.trim(), entry.id).not.toBe('')
			}
			expect(SEVERITIES, entry.id).toContain(entry.severity ?? 'info')
			expect(OPERATORS, entry.id).toContain(entry.op ?? 'gt')
			expect(typeof (entry.value ?? 0), entry.id).toBe('number')
		}
	})

	it('names its register and schema by slug and writes the filter flat', () => {
		const slug = /^[A-Za-z0-9][A-Za-z0-9_-]{0,127}$/
		for (const entry of declaration.items) {
			expect(entry.source.register, entry.id).toMatch(slug)
			expect(entry.source.schema, entry.id).toMatch(slug)
			const filter = entry.source.filter ?? {}
			expect(Array.isArray(filter), entry.id).toBe(false)
			for (const [key, value] of Object.entries(filter)) {
				// An operator sits in the key ("deadline[lt]"). A nested
				// object cannot be written into the link.
				expect(isFlat(value), `${entry.id}: ${key}`).toBe(true)
				for (const single of [value].flat()) {
					if (typeof single === 'string' && single.startsWith('@')) {
						expect(single, `${entry.id}: ${key}`).toMatch(TOKEN)
					}
				}
			}
		}
	})

	it('keeps every link inside the app', () => {
		for (const entry of declaration.items) {
			expect(entry.action.path, entry.id).toMatch(/^(\/[A-Za-z0-9._~-]+)+\/?$/)
			expect(entry.action.path, entry.id).not.toContain('..')
		}
	})
})

describe('the declared item is the dashboard\'s own "First today" card', () => {
	it('declares the item the card shows', () => {
		expect(item).toBeTruthy()
		expect(card.layout).toBe('attention')
		expect(item.title).toBe(card.title)
		expect(item.reason).toBe(card.reason)
		expect(item.severity).toBe(card.variant)
		expect(item.op ?? 'gt').toBe(card.visibleWhen.op)
		expect(item.value ?? 0).toBe(card.visibleWhen.value)
		expect(item.action.label).toBe(card.actions[0].label)
	})

	it('counts in the same register and schema with the same filter, key by key', () => {
		const own = card.visibleWhen.source
		expect(item.source.register).toBe(own.register)
		expect(item.source.schema).toBe(own.schema)
		expect(Object.keys(item.source.filter).sort()).toEqual(
			Object.keys(own.filter).sort(),
		)
		for (const [key, value] of Object.entries(own.filter)) {
			expect(item.source.filter[key], key).toStrictEqual(value)
		}
	})

	it('counts in a register and schema the app ships, on fields the schema carries', () => {
		const dir = path.join(ROOT, 'lib', 'Settings', 'register.d')
		const files = [
			readJson('lib', 'Settings', 'pipelinq_register.json'),
			...fs
				.readdirSync(dir)
				.filter((name) => name.endsWith('.json'))
				.sort()
				.map((name) => readJson('lib', 'Settings', 'register.d', name)),
		]
		const registers = Object.values(files[0].components.registers)
		expect(registers.map((register) => register.slug)).toContain(
			item.source.register,
		)
		// The schema is the main register plus what the fragments add to it.
		const properties = {}
		let found = false
		for (const file of files) {
			for (const schema of Object.values(file.components?.schemas ?? {})) {
				if (schema?.slug === item.source.schema) {
					found = true
					Object.assign(properties, schema.properties ?? {})
				}
			}
		}
		expect(found, item.source.schema).toBe(true)
		for (const key of Object.keys(item.source.filter)) {
			const field = key.replace(/\[.*$/, '')
			expect(properties[field], field).toBeTruthy()
		}
	})

	it("opens the list the card's button opens, with the filter the card links with", () => {
		const route = card.actions[0].route
		const target = builtSimple.pages.find((page) => page.id === route.name)
		expect(target, route.name).toBeTruthy()
		expect(item.action.path).toBe(target.route)
		// LaunchPad writes the filter into the link as the query. The card's
		// own link must carry the same keys and the same values.
		expect(Object.keys(route.query).sort()).toEqual(
			Object.keys(item.source.filter).sort(),
		)
		for (const [key, value] of Object.entries(item.source.filter)) {
			expect(route.query[key], key).toBe(String(value))
		}
	})
})

describe('every text of the declaration is translated', () => {
	// LaunchPad translates with THIS app's catalogues, on the server.
	const catalogues = {
		en: readJson('l10n', 'en.json').translations,
		nl: readJson('l10n', 'nl.json').translations,
	}

	it('has the title, the reason and the action label in English and in Dutch', () => {
		for (const entry of declaration.items) {
			for (const text of [entry.title, entry.reason, entry.action.label]) {
				for (const [language, catalogue] of Object.entries(catalogues)) {
					expect(typeof catalogue[text], `${language}: ${text}`).toBe(
						'string',
					)
					expect(catalogue[text].trim(), `${language}: ${text}`).not.toBe(
						'',
					)
				}
				// English reads as written, Dutch is a translation.
				expect(catalogues.en[text], text).toBe(text)
				expect(catalogues.nl[text], text).not.toBe(text)
			}
			// The count survives the translation.
			if (entry.reason.includes('{value}')) {
				expect(catalogues.nl[entry.reason], entry.id).toContain('{value}')
			}
		}
	})
})
