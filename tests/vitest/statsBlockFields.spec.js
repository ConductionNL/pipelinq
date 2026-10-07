/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Every stats-block entry sums a field its schema has.
 *
 * The contract Value card summed `variableConsideration` and
 * `fixedConsideration`, which the salesContract schema never had. The sum of a
 * missing field is zero, so the card drew two zeros on every contract and no
 * check noticed. This spec reads every stats-block in the manifest and its
 * fragments and looks each `field` and `currencyField` up in the register
 * schemas.
 */

import fs from 'fs'
import path from 'path'
import { describe, expect, it } from 'vitest'

const ROOT = path.resolve(__dirname, '../..')
function readJson(...parts) {
	return JSON.parse(fs.readFileSync(path.join(ROOT, ...parts), 'utf8'))
}

/** Schema slug to its property names, from the register and its fragments. */
function schemaProperties() {
	const files = [readJson('lib', 'Settings', 'pipelinq_register.json')]
	const dir = path.join(ROOT, 'lib', 'Settings', 'register.d')
	fs.readdirSync(dir)
		.filter((name) => name.endsWith('.json'))
		.forEach((name) =>
			files.push(readJson('lib', 'Settings', 'register.d', name)),
		)

	const props = {}
	for (const file of files) {
		const schemas = (file.components && file.components.schemas) || {}
		for (const [slug, schema] of Object.entries(schemas)) {
			props[slug] = new Set([
				...(props[slug] || []),
				...Object.keys(schema.properties || {}),
			])
		}
	}
	return props
}

/** Every stats-block entry anywhere in a manifest document. */
function statsEntries(node, found = []) {
	if (Array.isArray(node)) {
		node.forEach((child) => statsEntries(child, found))
	} else if (node && typeof node === 'object') {
		if (
			node.type === 'stats-block'
			&& node.content
			&& Array.isArray(node.content.entries)
		) {
			node.content.entries.forEach((entry) =>
				found.push({ block: node.id, entry }),
			)
		}
		Object.values(node).forEach((child) => statsEntries(child, found))
	}
	return found
}

const documents = [readJson('src', 'manifest.json')]
fs.readdirSync(path.join(ROOT, 'src', 'manifest.d'))
	.filter((name) => name.endsWith('.json'))
	.forEach((name) => documents.push(readJson('src', 'manifest.d', name)))

const props = schemaProperties()
const entries = documents
	.flatMap((doc) => statsEntries(doc))
	.filter(({ entry }) => entry.register === 'pipelinq' && entry.schema)

describe('stats-block entries read fields their schema has', () => {
	it('finds the entries it checks', () => {
		expect(entries.length).toBeGreaterThan(0)
	})

	it.each(entries.map(({ block, entry }) => [block, entry.title, entry]))(
		'%s / %s',
		(block, title, entry) => {
			const known = props[entry.schema]
			expect(known, `${block}: unknown schema ${entry.schema}`).toBeDefined()
			for (const key of ['field', 'currencyField']) {
				if (entry[key]) {
					expect(
						known.has(entry[key]),
						`${block}: ${entry.schema} has no ${entry[key]}`,
					).toBe(true)
				}
			}
		},
	)
})
