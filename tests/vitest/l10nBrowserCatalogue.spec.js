/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 * SPDX-License-Identifier: EUPL-1.2
 *
 * The generated browser catalogue for the source locale (en) carries only the
 * entries that differ from their key; every other locale, and l10n/en.json
 * itself, stays complete.
 *
 * @spec PERF-HANDOFF.md (all apps: drop identity entries from the English catalogue at build time)
 */

import fs from 'node:fs'
import { createRequire } from 'node:module'
import path from 'node:path'
import { describe, expect, it } from 'vitest'

const require = createRequire(import.meta.url)
const { browserEntries, renderJs, SOURCE_LOCALE } = require('../../scripts/build-l10n-js.js')

const ROOT = path.resolve(__dirname, '..', '..')

/**
 * The translations object a generated l10n/<locale>.js registers.
 *
 * @param {string} locale - the locale name
 * @return {object} key -> translation
 */
function served(locale) {
	const js = fs.readFileSync(path.join(ROOT, 'l10n', `${locale}.js`), 'utf8')
	const body = js.match(/OC\.L10N\.register\(\s*"[^"]+"\s*,\s*(\{.*\})\s*,\s*"nplurals/s)
	expect(body).not.toBeNull()
	return JSON.parse(body[1])
}

/**
 * The translations of l10n/<locale>.json.
 *
 * @param {string} locale - the locale name
 * @return {object} key -> translation
 */
function catalogue(locale) {
	return JSON.parse(fs.readFileSync(path.join(ROOT, 'l10n', `${locale}.json`), 'utf8')).translations
}

describe('browserEntries', () => {
	it('drops entries equal to their key for the source locale', () => {
		expect(SOURCE_LOCALE).toBe('en')
		expect(
			browserEntries(SOURCE_LOCALE, {
				Save: 'Save',
				'Import failed:': 'Import failed: ',
				'reload now': 'Reload now',
			}),
		).toEqual({ 'Import failed:': 'Import failed: ', 'reload now': 'Reload now' })
	})

	it('keeps plural entries, which are arrays and never equal their key', () => {
		const plural = { '_%n lead_::_%n leads_': ['%n lead', '%n leads'] }
		expect(browserEntries(SOURCE_LOCALE, plural)).toEqual(plural)
	})

	it('keeps every entry for any other locale, identity ones included', () => {
		const nl = { Save: 'Opslaan', OK: 'OK' }
		expect(browserEntries('nl', nl)).toEqual(nl)
	})

	it('renders a valid register call when every source entry is an identity', () => {
		expect(browserEntries(SOURCE_LOCALE, { A: 'A', B: 'B' })).toEqual({})
		expect(renderJs('pipelinq', {}, 'nplurals=2; plural=(n != 1);')).toBe(
			'OC.L10N.register(\n    "pipelinq",\n    {\n\n    },\n    "nplurals=2; plural=(n != 1);"\n)\n',
		)
	})
})

describe('committed catalogues', () => {
	it('l10n/en.js holds exactly the non-identity entries of l10n/en.json', () => {
		const json = catalogue('en')
		const expected = Object.fromEntries(Object.entries(json).filter(([key, value]) => key !== value))
		expect(served('en')).toEqual(expected)
		expect(Object.keys(json).length).toBeGreaterThan(Object.keys(expected).length)
	})

	it('l10n/nl.js stays complete', () => {
		expect(served('nl')).toEqual(catalogue('nl'))
	})
})
