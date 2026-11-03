/**
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * Boards PqKlant, PqOrganisatie and PqContactpersoon draw no follow row under
 * the header, as DqContact and DqOrganisatie. The simple profile turns it off
 * on the client and contact person pages; the full profile keeps it.
 *
 * @spec openspec/changes/client-contact-follow-row-off/specs/detail-pages/spec.md#requirement-simple-client-and-contact-pages-draw-no-follow-row
 */
import { buildManifest } from '@conduction/nextcloud-vue/src/utils/buildManifest.js'
import fs from 'fs'
import path from 'path'
import { describe, expect, it } from 'vitest'
import { applyMenuModules } from '../../src/utils/menuModules.js'
import { buildProfiledManifest } from '../../src/utils/structureProfile.js'

const ROOT = path.resolve(__dirname, '../..')
const readJson = (...parts) => JSON.parse(fs.readFileSync(path.join(ROOT, ...parts), 'utf8'))
const fragments = fs.readdirSync(path.join(ROOT, 'src', 'manifest.d'))
	.filter((name) => name.endsWith('.json')).sort()
	.map((name) => readJson('src', 'manifest.d', name))
const build = (layout) => buildProfiledManifest(buildManifest, readJson('src', 'manifest.json'), fragments, applyMenuModules(readJson('src', layout), []))
const simple = build('menu-layout.simple.json')
const full = build('menu-layout.json')
const pageOf = (manifest, id) => manifest.pages.find((page) => page.id === id)

describe('client and contact person pages without a follow row (PqKlant, PqOrganisatie, PqContactpersoon)', () => {
	it.each(['ClientDetail', 'ContactDetail'])('%s draws no follow row in the simple profile', (id) => {
		expect(pageOf(simple, id).config.follow).toBe(false)
	})

	it.each(['ClientDetail', 'ContactDetail'])('%s keeps its follow row in the full profile', (id) => {
		expect(pageOf(full, id).config.follow).not.toBe(false)
	})

	it.each(['ClientDetail', 'ContactDetail'])('%s keeps its own detail config in the simple profile', (id) => {
		expect(pageOf(simple, id).type).toBe('detail')
		expect(pageOf(simple, id).config.register).toBe(pageOf(full, id).config.register)
		expect(pageOf(simple, id).config.schema).toBe(pageOf(full, id).config.schema)
	})
})
