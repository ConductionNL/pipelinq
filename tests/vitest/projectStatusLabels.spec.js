// SPDX-License-Identifier: EUPL-1.2
// Copyright (C) 2026 Conduction B.V.

/**
 * A project's tasks and cycles read their status as words (PqProject:
 * "Klaar", "In behandeling", "Niet gestart"; a cycle "Open" or "Gesloten"),
 * not as the stored codes `closed`, `inProgress` and `open`. nextcloud-vue
 * shows a property's `x-enum-labels` in the table pills and filters.
 *
 * @spec openspec/specs/project-portfolio/spec.md#requirement-a-project-shall-be-a-record-with-a-plan-a-team-and-tasks-req-prj-001
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
		if (key === '__proto__' || key === 'constructor' || key === 'prototype') {
			continue
		}
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
const schemas = mergedRegister().components.schemas

describe('project task and cycle status', () => {
	const expected = {
		programmeTask: { open: 'Niet gestart', inProgress: 'In behandeling', closed: 'Klaar' },
		programmeCycle: { open: 'Open', closed: 'Gesloten' },
	}
	for (const [schema, words] of Object.entries(expected)) {
		it(`labels every ${schema} status as PqProject draws it, in English and Dutch`, () => {
			const property = schemas[schema].properties.status
			expect(property.enum).toEqual(Object.keys(words))
			const labels = property['x-enum-labels'] || {}
			for (const [value, dutch] of Object.entries(words)) {
				const label = labels[value]
				expect(label, `${schema}.${value}`).toBeTruthy()
				expect(en[label], label).toBe(label)
				expect(nl[label], label).toBe(dutch)
			}
		})
	}
})
