/**
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * Board PqWachtrij: the queue is worked from the top, so its toolbar has no
 * Filter button and the card ends in its note, without "9 van 9" and
 * "1 Volgende" while the queue fits on one page. `pagerOnOnePage` needs
 * nc-vue feat/bpq3-index-footer-note-on-one-page; before it the key is
 * ignored and the footer is as today.
 *
 * @spec openspec/changes/queue-no-filter-no-pager/specs/queue/spec.md#requirement-the-queue-has-no-filter-and-no-pager-on-one-page
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
const built = buildProfiledManifest(buildManifest, readJson('src', 'manifest.json'), fragments, applyMenuModules(readJson('src', 'menu-layout.simple.json'), []))
const simple = built.pages.find((page) => page.id === 'Queue').config

describe('the queue toolbar and footer (PqWachtrij)', () => {
	it('has no filter sidebar, so the toolbar draws no Filter button', () => {
		expect(simple.sidebar).toEqual({ enabled: false })
	})

	it('ends the card in its note while the queue fits on one page', () => {
		expect(simple.pagerOnOnePage).toBe(false)
		expect(simple.footerNote).toBeTruthy()
	})
})
