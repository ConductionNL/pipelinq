/**
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * Boards PqTaak, PqZRapport and PqSocialBericht draw no follow row under the
 * header, as PqTicket does not. The simple profile turns it off on those
 * pages; the full profile keeps it.
 *
 * @spec openspec/changes/detail-follow-row-off/specs/detail-pages/spec.md#requirement-simple-detail-pages-draw-no-follow-row
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
const configOf = (manifest, id) => manifest.pages.find((page) => page.id === id).config

describe('detail pages without a follow row (PqTaak, PqZRapport, PqSocialBericht)', () => {
	it.each(['TaskDetail', 'ZReportDetail', 'SocialPostDetail', 'TicketDetail'])('%s draws no follow row in the simple profile', (id) => {
		expect(configOf(simple, id).follow).toBe(false)
	})

	it.each(['TaskDetail', 'ZReportDetail', 'SocialPostDetail'])('%s keeps its follow row in the full profile', (id) => {
		expect(configOf(full, id).follow).not.toBe(false)
	})
})
