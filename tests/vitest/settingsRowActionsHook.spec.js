/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 * SPDX-License-Identifier: EUPL-1.2
 *
 * The admin managers' row buttons (Bewerken, Verwijderen) opt into the board
 * look's 34px row size with `cn-settings-row-actions` (nc-vue 24t2l); without
 * it they took the card body's 40px (pl2 PqBeheer). Read as text.
 */
import { readFileSync } from 'node:fs'
import { resolve } from 'node:path'
import { describe, expect, it } from 'vitest'

const read = (p) => readFileSync(resolve(__dirname, '../../src', p), 'utf8')

describe('admin manager row buttons', () => {
	it.each([
		['views/settings/ProductCategoryManager.vue', 'category-item__buttons'],
		['components/admin/AgentProfileSettings.vue', 'agent-item__actions'],
		['components/admin/SkillSettings.vue', 'skill-item__actions'],
	])('%s opts its row buttons into the row size', (file, cls) => {
		expect(read(file)).toContain(`<div class="${cls} cn-settings-row-actions">`)
	})
})
