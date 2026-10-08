// SPDX-License-Identifier: EUPL-1.2
// Copyright (C) 2026 Conduction B.V.

/**
 * Notification preferences name each rule in words.
 *
 * The cloud check of 8 October 2026 found the notification preferences in
 * the user settings showing rule keys (`clientUpdated`, `newLead`). pipelinq
 * hands CnAppRoot a label per rule (`notificationLabels`, keyed
 * `<schema>.<rule>`). This spec reads the rules from the MERGED register, so
 * a rule added later without a label fails here, not in front of a user.
 *
 * @spec openspec/changes/simple-tour-and-readable-labels/specs/notifications/spec.md
 */

import fs from 'fs'
import path from 'path'
import { describe, expect, it } from 'vitest'
import { notificationLabels } from '../../src/services/notificationLabels.js'

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

/** Every `<schema>.<rule>` the merged register declares. */
function declaredRules() {
	const register = readJson('lib', 'Settings', 'pipelinq_register.json')
	const dir = path.join(ROOT, 'lib', 'Settings', 'register.d')
	fs.readdirSync(dir)
		.filter((name) => name.endsWith('.json'))
		.sort()
		.forEach((name) =>
			merge(register, readJson('lib', 'Settings', 'register.d', name)),
		)
	const rules = []
	for (const [key, schema] of Object.entries(register.components.schemas)) {
		const slug = schema.slug || key
		for (const rule of Object.keys(
			schema['x-openregister-notifications'] || {},
		)) {
			rules.push(`${slug}.${rule}`)
		}
	}
	return rules.sort()
}

const en = readJson('l10n', 'en.json').translations
const nl = readJson('l10n', 'nl.json').translations
const identity = (app, text) => text

describe('notification rule labels', () => {
	it('labels every rule the register declares, and no rule it does not', () => {
		const rules = declaredRules()
		// The control: the register really declares the rules the cloud
		// check saw as raw keys.
		expect(rules).toContain('lead.newLead')
		expect(rules).toContain('client.clientUpdated')
		expect(Object.keys(notificationLabels(identity)).sort()).toEqual(rules)
	})

	it('has an English and a Dutch entry for every label, and never the key', () => {
		for (const [key, label] of Object.entries(notificationLabels(identity))) {
			const rule = key.split('.')[1]
			expect(label, key).not.toBe(rule)
			expect(en[label], key).toBe(label)
			expect(nl[label], key).toBeTruthy()
			expect(nl[label], key).not.toBe(label)
			expect(label, key).not.toMatch(/—|--/)
		}
	})

	it('translates through the translator it is given, as pipelinq', () => {
		const calls = []
		const labels = notificationLabels((app, text) => {
			calls.push(app)
			return `<${text}>`
		})
		expect(labels['lead.newLead']).toBe('<A new lead comes in>')
		expect(new Set(calls)).toEqual(new Set(['pipelinq']))
	})

	it('is handed to CnAppRoot by App.vue', () => {
		const app = fs.readFileSync(path.join(ROOT, 'src', 'App.vue'), 'utf8')
		expect(app).toContain(':notificationLabels="notificationLabels"')
		expect(app).toMatch(
			/notificationLabels\(\) \{\s+return buildNotificationLabels\(ncT\)/,
		)
	})
})
