/**
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * Board PqSocial draws the post title as the default underlined 15/600 row
 * link, so the social posts index keeps the default row title.
 *
 * @spec openspec/changes/social-posts-link-title/specs/index-pages/spec.md#requirement-the-social-posts-index-draws-its-titles-as-row-links
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

describe('social posts index row title (PqSocial)', () => {
	it.each(['menu-layout.simple.json', 'menu-layout.json'])('%s draws the default link title', (layout) => {
		const config = build(layout).pages.find((page) => page.id === 'SocialPosts').config
		expect(config.rowTitle ?? 'link').toBe('link')
	})
})
