/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Every webpack entry that renders library components registers the library
 * catalogue itself. The admin page (src/settings.js) is its own entry: without
 * the call its version card and shell chrome read in English.
 *
 * @spec openspec/specs/admin-settings/spec.md
 */
import { readFileSync } from 'node:fs'
import { resolve } from 'node:path'
import { describe, expect, it } from 'vitest'

describe('the library catalogue', () => {
	it.each(['main.js', 'settings.js'])('is registered by src/%s', (entry) => {
		const source = readFileSync(resolve(__dirname, '../../src', entry), 'utf8')
		expect(source).toMatch(
			/import\s*\{[^}]*\bregisterTranslations\b[^}]*\}\s*from\s*'@conduction\/nextcloud-vue'/,
		)
		expect(source).toMatch(/^\s*registerTranslations\(\)/m)
	})
})
