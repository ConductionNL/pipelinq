/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Every webpack entry that renders library components imports the library
 * CSS itself. The admin page (src/settings.js) is its own entry and drew
 * unstyled while only main.js imported it.
 *
 * @spec openspec/specs/admin-settings/spec.md
 */
import { readFileSync } from 'node:fs'
import { resolve } from 'node:path'
import { describe, expect, it } from 'vitest'

describe('the library CSS', () => {
	it.each(['main.js', 'settings.js'])('is imported by src/%s', (entry) => {
		const source = readFileSync(resolve(__dirname, '../../src', entry), 'utf8')
		expect(source).toMatch(
			/import\s+'@conduction\/nextcloud-vue\/css\/index\.css'/,
		)
	})
})
