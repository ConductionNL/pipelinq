/**
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * The queue's list as board PqWachtrij draws it: each row ends in "In
 * behandeling nemen" and an eye, with no menu; the list has no search field,
 * no add button and no column filters.
 *
 * @spec openspec/specs/queue/spec.md#requirement-the-queue-list-picks-rows-up-in-the-row
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

describe('the queue rows (PqWachtrij)', () => {
	it('shows Take it on and View in the row, View as an eye', () => {
		expect(simple.rowInlineActions).toBe(2)
		const view = simple.actions.find((action) => action.id === 'view')
		expect(view).toMatchObject({ icon: 'EyeOutline', iconOnly: true })
	})

	it('has no search field, no add button and no column filters', () => {
		expect(simple.search).toBe(false)
		expect(simple.showAdd).toBe(false)
		expect(simple.headerFilters).toBe(false)
	})
})
