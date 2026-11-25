// SPDX-License-Identifier: EUPL-1.2
// SPDX-FileCopyrightText: 2026 Conduction B.V.

/**
 * My work reads its lists again on its own (REQ-MW-190): every 5 minutes, in
 * place, with a refresh button in the header, and a warning with Retry after
 * 10 minutes of failed reads. The behaviour is the library's (nextcloud-vue
 * `autoRefresh`); this pins the page's settings and that the installed
 * library knows them.
 *
 * @spec openspec/changes/my-work-roadmap/specs/my-work/spec.md#requirement-auto-refresh-and-a-refresh-button-req-mw-190
 */
import { readFileSync } from 'node:fs'
import { resolve } from 'node:path'
import { describe, expect, it } from 'vitest'

const manifest = JSON.parse(readFileSync(resolve(__dirname, '../../src/manifest.json'), 'utf8'))
const page = manifest.pages.find((p) => p.id === 'MyWork')
const library = resolve(__dirname, '../../node_modules/@conduction/nextcloud-vue')

describe('My work: auto-refresh and a refresh button', () => {
	it('reads again every 5 minutes and warns after 10 minutes of failed reads', () => {
		expect(page.config.autoRefresh).toEqual({ intervalSeconds: 300, staleAfterSeconds: 600, button: true })
	})

	it('runs on a library that knows autoRefresh and its outdated-data warning', () => {
		const schema = JSON.parse(readFileSync(resolve(library, 'src/schemas/app-manifest-v2.schema.json'), 'utf8'))
		expect(schema.$defs.page.properties.config.properties.autoRefresh).toBeDefined()
		const nl = JSON.parse(readFileSync(resolve(library, 'l10n/nl.json'), 'utf8')).translations
		expect(nl['Data may be outdated. Last updated: {time}']).toBe('De gegevens zijn mogelijk verouderd. Laatst bijgewerkt: {time}')
	})

	it('keeps the overflow Refresh as well', () => {
		expect(page.config.showRefresh).toBe(true)
	})
})
