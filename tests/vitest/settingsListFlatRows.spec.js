/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 * SPDX-License-Identifier: EUPL-1.2
 *
 * The admin managers' lists opt into the board look's flat list rows with
 * `cn-settings-list` and `cn-settings-row` (nc-vue board-settings-list-rows):
 * rows flush with the card title and a hairline below, not a box per row
 * (pl6 PqBeheer Vaardigheden, Medewerkerprofielen insets 38 vs 25). A row
 * being edited keeps its box. Read as text.
 */
import { readFileSync } from 'node:fs'
import { resolve } from 'node:path'
import { describe, expect, it } from 'vitest'

const read = (p) => readFileSync(resolve(__dirname, '../../src', p), 'utf8')

describe('admin manager lists', () => {
	it.each([
		['views/settings/ProductCategoryManager.vue', 'category-manager__list'],
		['components/admin/AgentProfileSettings.vue', 'agent-settings'],
		['components/admin/SkillSettings.vue', 'skill-settings'],
	])('%s marks its list as a settings list', (file, cls) => {
		expect(read(file)).toContain(`class="${cls} cn-settings-list"`)
	})

	it.each([
		['views/settings/ProductCategoryManager.vue', 'category-item', 'cat.id'],
		['components/admin/AgentProfileSettings.vue', 'agent-item', 'profile.id'],
		['components/admin/SkillSettings.vue', 'skill-item', 'skill.id'],
	])('%s marks a row as a flat settings row unless it is being edited', (file, cls, id) => {
		const source = read(file)
		const at = source.indexOf(`class="${cls}"`)
		const tag = source.slice(at, source.indexOf('>', at))

		expect(at).toBeGreaterThan(-1)
		expect(tag).toContain(`'cn-settings-row': editingId !== ${id}`)
	})
})
