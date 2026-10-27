// SPDX-License-Identifier: EUPL-1.2
// Copyright (C) 2026 Conduction B.V.

/**
 * Z report statuses read as words (boards PqZRapporten, PqZRapport).
 *
 * The Z report list draws its status through the `enumLabel` formatter,
 * which reads the property's `x-enum-labels` in the MERGED register and
 * translates the label. Without labels the list printed the stored codes
 * (`reconciled`, `submitted`).
 *
 * @spec openspec/specs/declarative-view-system/spec.md
 */

import fs from 'fs'
import path from 'path'
import { describe, expect, it } from 'vitest'

const ROOT = path.resolve(__dirname, '../..')
function readJson(...parts) {
	return JSON.parse(fs.readFileSync(path.join(ROOT, ...parts), 'utf8'))
}

/**
 * Deep-merge like ConfigFileLoaderService::deepMergeConfig: objects merge
 * key by key, everything else (lists included) is replaced.
 *
 * @param {object} base The base.
 * @param {object} override The fragment.
 * @return {object} The base, merged in place.
 */
function merge(base, override) {
	for (const [key, value] of Object.entries(override)) {
		const isObject = (v) => v && typeof v === 'object' && !Array.isArray(v)
		if (isObject(value) && isObject(base[key])) {
			merge(base[key], value)
		} else {
			base[key] = value
		}
	}
	return base
}

function mergedRegister() {
	const register = readJson('lib', 'Settings', 'pipelinq_register.json')
	const dir = path.join(ROOT, 'lib', 'Settings', 'register.d')
	fs.readdirSync(dir)
		.filter((name) => name.endsWith('.json'))
		.sort()
		.forEach((name) =>
			merge(register, readJson('lib', 'Settings', 'register.d', name)),
		)
	return register
}

const en = readJson('l10n', 'en.json').translations
const nl = readJson('l10n', 'nl.json').translations
const report = mergedRegister().components.schemas.posZReport

describe('posZReport status', () => {
	it('labels every status value, in English and Dutch', () => {
		const property = report.properties.status
		expect(Array.isArray(property.enum) && property.enum.length).toBeTruthy()
		const labels = property['x-enum-labels'] || {}
		for (const value of property.enum) {
			const label = labels[value]
			expect(label, value).toBeTruthy()
			expect(en[label], label).toBe(label)
			expect(nl[label], label).toBeTruthy()
		}
	})

	it('reads a posted and a reconciled report as the boards do', () => {
		const labels = report.properties.status['x-enum-labels']
		expect(nl[labels.posted]).toBe('Geboekt')
		expect(nl[labels.reconciled]).toBe('Afgestemd')
		expect(nl[labels.failed]).toBe('Mislukt')
	})
})
