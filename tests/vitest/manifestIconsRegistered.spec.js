/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Every icon the manifest and its fragments name is registered in
 * src/icons.js. An unregistered name renders no icon at all, not a fallback
 * glyph (ADR-077 rule 3).
 *
 * @spec openspec/specs/pipelinq-navigation/spec.md
 */
import fs from 'fs'
import path from 'path'
import { describe, expect, it } from 'vitest'

const ROOT = path.resolve(__dirname, '../..')
const icons = fs.readFileSync(path.join(ROOT, 'src', 'icons.js'), 'utf8')
const fragmentDir = path.join(ROOT, 'src', 'manifest.d')
const manifests = [
	['src/manifest.json', path.join(ROOT, 'src', 'manifest.json')],
	...fs.readdirSync(fragmentDir).filter((name) => name.endsWith('.json')).sort()
		.map((name) => [`src/manifest.d/${name}`, path.join(fragmentDir, name)]),
]

function iconNames(node, out = new Set()) {
	if (Array.isArray(node)) {
		node.forEach((item) => iconNames(item, out))
	} else if (node && typeof node === 'object') {
		for (const [key, value] of Object.entries(node)) {
			if (key === 'icon' && typeof value === 'string' && /^[A-Z][A-Za-z0-9]+$/.test(value)) {
				out.add(value)
			} else if (!key.startsWith('_')) {
				iconNames(value, out)
			}
		}
	}
	return out
}

describe('manifest icons', () => {
	it.each(manifests)('%s only names icons pipelinq registers', (_label, file) => {
		const manifest = JSON.parse(fs.readFileSync(file, 'utf8'))
		for (const icon of iconNames(manifest)) {
			expect(icons, icon).toMatch(new RegExp(`^\\t${icon},$`, 'm'))
		}
	})
})
