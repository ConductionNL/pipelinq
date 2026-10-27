// SPDX-License-Identifier: EUPL-1.2
// Copyright (C) 2026 Conduction B.V.

/**
 * The ticket type "melding" (a report about the public space), next to
 * request, complaint and contact moment. The boards draw it (decision of
 * 27 October 2026), so the type is in the schema with a label, in the en
 * and nl catalogues, and in every ticket-type pill and badge the manifests
 * declare, all as configuration. The lists get no melding lens: the boards
 * draw none.
 *
 * @spec openspec/changes/unify-ticket-supertype/specs/unify-ticket-supertype/spec.md#requirement-ticket-supertype-schema
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
const ticket = mergedRegister().components.schemas.ticket
const manifest = readJson('src', 'manifest.json')
const simple = readJson('src', 'menu-layout.simple.json')

/**
 * Every object in a JSON tree that a predicate accepts.
 *
 * @param {unknown} node The tree.
 * @param {Function} accept The predicate.
 * @param {Array} out The collected objects.
 * @return {Array} The collected objects.
 */
function collect(node, accept, out = []) {
	if (Array.isArray(node)) {
		node.forEach((child) => collect(child, accept, out))
	} else if (node && typeof node === 'object') {
		if (accept(node)) {
			out.push(node)
		}
		Object.values(node).forEach((child) => collect(child, accept, out))
	}
	return out
}

describe('ticket type melding', () => {
	it('is a ticketType value with a label, in English and Dutch', () => {
		const property = ticket.properties.ticketType
		expect(property.enum).toContain('melding')
		const labels = property['x-enum-labels'] || {}
		for (const value of property.enum) {
			expect(labels[value], value).toBeTruthy()
			expect(en[labels[value]], labels[value]).toBe(labels[value])
			expect(nl[labels[value]], labels[value]).toBeTruthy()
		}
		expect(nl[labels.melding]).toBe('Melding')
		expect(nl.melding).toBe('Melding')
	})

	it('has a label and a colour wherever a ticket-type pill or badge maps the others', () => {
		const maps = collect([manifest, simple], (node) => (node.labels && node.labels.complaint) || (node.colorMap && node.colorMap.complaint && node.colorMap.request))
		expect(maps.length).toBeGreaterThan(0)
		for (const map of maps) {
			if (map.labels && map.labels.complaint) expect(map.labels.melding, JSON.stringify(map.labels)).toBeTruthy()
			if (map.colorMap && map.colorMap.complaint) expect(map.colorMap.melding, JSON.stringify(map.colorMap)).toBeTruthy()
		}
	})
})
