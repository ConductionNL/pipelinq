/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Every page that renders library components gets the library CSS. The admin
 * page (src/settings.js) is its own entry and drew unstyled while only the
 * app page had it; both templates now link css/nextcloud-vue.css, which the
 * build copies from the library (see libraryStylesheet.spec.js).
 *
 * @spec openspec/specs/admin-settings/spec.md
 */
import { readFileSync } from 'node:fs'
import { resolve } from 'node:path'
import { describe, expect, it } from 'vitest'

describe('the library CSS', () => {
	it.each(['index.php', 'settings/admin.php'])('is linked by templates/%s', (template) => {
		const source = readFileSync(resolve(__dirname, '../../templates', template), 'utf8')
		expect(source).toMatch(/addStyle\(\$appId, 'nextcloud-vue'\)/)
	})
})
