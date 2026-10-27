/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 * SPDX-License-Identifier: EUPL-1.2
 *
 * The admin add buttons carry the plus as an icon, not a typed "+", as the
 * board draws them ("Bron toevoegen" with the plus glyph; pl2 PqBeheer
 * 'icon true>false'). Read as text.
 */
import { readFileSync } from 'node:fs'
import { resolve } from 'node:path'
import { describe, expect, it } from 'vitest'

const read = (p) => readFileSync(resolve(__dirname, '../..', p), 'utf8')
const nl = JSON.parse(read('l10n/nl.json')).translations

describe('admin add buttons', () => {
	it.each([
		'src/views/settings/TagManager.vue',
		'src/views/settings/ProductCategoryManager.vue',
		'src/components/admin/SkillSettings.vue',
		'src/components/admin/AgentProfileSettings.vue',
	])('%s draws the plus icon', (file) => {
		expect(read(file)).toContain('<Plus :size="20" />')
	})

	it('reads without a typed plus, in Dutch', () => {
		const settings = read('src/views/settings/Settings.vue')
		expect(settings).toContain(`t('pipelinq', 'Add source')`)
		expect(settings).not.toContain(`'+ Add`)
		expect(nl['Add source']).toBe('Bron toevoegen')
		expect(nl['Add category']).toBe('Categorie toevoegen')
		expect(nl['Add agent profile']).toBe('Agentprofiel toevoegen')
	})
})
