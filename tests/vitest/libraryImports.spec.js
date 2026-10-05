/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Every name the app imports from `@conduction/nextcloud-vue` is one the
 * library exports.
 *
 * An import of a name the library does not export is not an error anywhere.
 * webpack prints "export ... was not found" among its warnings and exits 0,
 * the import is `undefined`, and Vue renders an undefined component as
 * nothing. The Modules page shipped empty that way: it imported a page
 * component that exists in the library's source and is not in its entry.
 *
 * This reads the entry the bundler resolves (`dist/esm/index.js`) and every
 * import statement under `src/`.
 *
 * @spec openspec/changes/simple-structure-profile/specs/navigation-ia/spec.md#REQ-NIA-103
 */

import fs from 'fs'
import path from 'path'
import { describe, expect, it } from 'vitest'

const ROOT = path.resolve(__dirname, '../..')
const entry = fs.readFileSync(
	path.join(
		ROOT,
		'node_modules',
		'@conduction',
		'nextcloud-vue',
		'dist',
		'esm',
		'index.js',
	),
	'utf8',
)

/** The names the entry exports itself. `@nextcloud/vue` is re-exported whole. */
const exported = new Set()
for (const match of entry.matchAll(/export\s*\{([^}]*)\}/g)) {
	for (const part of match[1].split(',')) {
		const name = part
			.trim()
			.split(/\s+as\s+/)
			.pop()
		if (name) {
			exported.add(name)
		}
	}
}
const reexportsNextcloudVue = /export \* from '@nextcloud\/vue'/.test(entry)

/**
 * Every source file under a directory.
 *
 * @param {string} dir The directory.
 * @return {Array<string>} The `.js` and `.vue` files.
 */
function sources(dir) {
	return fs.readdirSync(dir, { withFileTypes: true }).flatMap((item) => {
		const full = path.join(dir, item.name)
		if (item.isDirectory()) {
			return sources(full)
		}
		return /\.(js|vue)$/.test(item.name) ? [full] : []
	})
}

describe('imports from @conduction/nextcloud-vue', () => {
	it('can be read from the library entry at all', () => {
		expect(exported.size).toBeGreaterThan(400)
		expect(exported.has('CnAppRoot')).toBe(true)
		expect(reexportsNextcloudVue).toBe(true)
		// The control: the name that shipped the empty page is really absent.
		expect(exported.has('CnReportsPage')).toBe(false)
	})

	it('name only what the library exports', () => {
		const missing = []
		let seen = 0
		for (const file of sources(path.join(ROOT, 'src'))) {
			const source = fs.readFileSync(file, 'utf8')
			const imports = source.matchAll(
				/import\s*\{([^}]*)\}\s*from\s*'@conduction\/nextcloud-vue'/g,
			)
			for (const match of imports) {
				for (const part of match[1].split(',')) {
					const name = part.trim().split(/\s+as\s+/)[0]
					if (!name) {
						continue
					}
					seen += 1
					// `Nc*` names come through the whole re-export of
					// @nextcloud/vue, which this file cannot enumerate.
					if (
						exported.has(name)
						|| (reexportsNextcloudVue && /^Nc[A-Z]/.test(name))
					) {
						continue
					}
					missing.push(`${path.relative(ROOT, file)}: ${name}`)
				}
			}
		}
		expect(seen).toBeGreaterThan(50)
		expect(missing).toEqual([])
	})
})
