// SPDX-License-Identifier: EUPL-1.2
// Copyright (C) 2026 Conduction B.V.

/**
 * Every tour step has a title.
 *
 * The cloud check of 9 October 2026 found steps 2 to 7 of the simple menu
 * tour without a title, so the dialog read "Step 2 of 8:" and nothing else.
 * The full menu tour had the same gap on steps 2 to 11. This walks every
 * tour the app ships (the manifest, its fragments and both menu layouts)
 * and asks each step for a title with an en and an nl catalogue entry.
 *
 * @spec openspec/changes/round4-readable-values-and-tour-titles/specs/navigation-ia/spec.md
 */

import fs from 'fs'
import path from 'path'
import { describe, expect, it } from 'vitest'

const ROOT = path.resolve(__dirname, '../..')
function readJson(...parts) {
	return JSON.parse(fs.readFileSync(path.join(ROOT, ...parts), 'utf8'))
}

/**
 * Collect every `tours[]` list anywhere in a JSON tree.
 *
 * @param {object} node The tree.
 * @param {Array} found The tours found so far.
 * @return {Array} Every tour.
 */
function collectTours(node, found = []) {
	if (Array.isArray(node)) {
		node.forEach((child) => collectTours(child, found))
	} else if (node && typeof node === 'object') {
		for (const [key, value] of Object.entries(node)) {
			if (key === 'tours' && Array.isArray(value)) {
				found.push(...value)
			}
			collectTours(value, found)
		}
	}
	return found
}

const sources = [
	['src', 'manifest.json'],
	['src', 'menu-layout.json'],
	['src', 'menu-layout.simple.json'],
	...fs
		.readdirSync(path.join(ROOT, 'src', 'manifest.d'))
		.filter((name) => name.endsWith('.json'))
		.map((name) => ['src', 'manifest.d', name]),
]
const tours = sources.flatMap((parts) =>
	collectTours(readJson(...parts)).map((tour) => ({
		file: parts.join('/'),
		tour,
	})),
)
const en = readJson('l10n', 'en.json').translations
const nl = readJson('l10n', 'nl.json').translations

describe('tour step titles', () => {
	it('finds the tours of both structures', () => {
		// The control: without these two the loop below proves nothing.
		const ids = tours.map(({ tour }) => tour.id)
		expect(ids).toContain('pipelinq:getting-started')
		expect(ids).toContain('pipelinq:contact-centre')
	})

	for (const { file, tour } of tours) {
		it(`gives every step of ${tour.id} (${file}) a title, in English and Dutch`, () => {
			expect(tour.steps.length).toBeGreaterThan(0)
			for (const [index, step] of tour.steps.entries()) {
				const where = `${tour.id} step ${index + 1} (${step.id})`
				expect(
					typeof step.title === 'string' && step.title.trim(),
					where,
				).toBeTruthy()
				expect(step.title, where).not.toMatch(/—/)
				expect(en[step.title], where).toBe(step.title)
				expect(nl[step.title], where).toBeTruthy()
			}
		})
	}
})
