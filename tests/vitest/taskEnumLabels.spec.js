// SPDX-License-Identifier: EUPL-1.2
// Copyright (C) 2026 Conduction B.V.

/**
 * Task values read as words.
 *
 * The cloud check of 8 October 2026 found the task Type list and the task
 * table showing the stored codes (`callbackRequest`, `in_progress`,
 * `normal`). nextcloud-vue renders a property's `x-enum-labels` in forms,
 * filters and table cells, through the app's translate. So every value of
 * the crmTask enums needs a label in the MERGED register (the monolith plus
 * every register.d fragment, deep-merged the way ConfigFileLoaderService
 * does), and every label needs an en and an nl catalogue entry.
 *
 * @spec openspec/changes/simple-tour-and-readable-labels/specs/callback-management/spec.md
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
const task = mergedRegister().components.schemas.crmTask

describe('crmTask values', () => {
	for (const key of ['type', 'status', 'priority']) {
		it(`labels every ${key} value, in English and Dutch`, () => {
			const property = task.properties[key]
			// The control: the property really is an enum of stored codes.
			expect(Array.isArray(property.enum) && property.enum.length).toBeTruthy()
			const labels = property['x-enum-labels'] || {}
			for (const value of property.enum) {
				const label = labels[value]
				expect(label, `${key}.${value}`).toBeTruthy()
				expect(label, `${key}.${value}`).not.toBe(value)
				expect(en[label], label).toBe(label)
				expect(nl[label], label).toBeTruthy()
			}
		})
	}

	it('reads the type a callback task has as words', () => {
		const labels = task.properties.type['x-enum-labels']
		expect(labels.callbackRequest).toBe('Callback request')
		expect(nl[labels.callbackRequest]).toBe('Terugbelverzoek')
	})
})
