/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Board PqRoadmap: the page shows the curated feature list in five groups,
 * the group captions in the reader's language.
 *
 * @spec openspec/specs/roadmap-curated-list/spec.md#requirement-the-page-reads-the-curated-feature-list
 */

import { readFileSync } from 'node:fs'
import { resolve } from 'node:path'
import { describe, expect, it } from 'vitest'
import en from '../../l10n/en.json'
import nl from '../../l10n/nl.json'
import roadmap from '../../lib/Settings/roadmap.json'

const view = readFileSync(resolve(__dirname, '../../src/views/FeaturesRoadmapView.vue'), 'utf8')

describe('the curated roadmap (pl2 PqRoadmap)', () => {
	it('hands the groups to the library page from the initial state', () => {
		expect(view).toContain(":categories=\"categories\"")
		expect(view).toContain("loadState('pipelinq', 'features_roadmap_categories', null)")
	})

	it('names every group in English and Dutch', () => {
		expect(Object.values(roadmap.categories).map((caption) => nl.translations[caption])).toEqual([
			'Basis-CRM',
			'Klantcontact en overheid',
			'Analyse en inzicht',
			'Automatisering en organisatie',
			'Infrastructuur',
		])
		for (const caption of Object.values(roadmap.categories)) {
			expect(en.translations[caption], caption).toBe(caption)
		}
	})

	it('writes its English in sentence case without dashes, and has every Dutch text', () => {
		for (const feature of roadmap.features) {
			expect(`${feature.title} ${feature.summary}`, feature.slug).not.toMatch(/[—]|--/)
			expect(feature.title.slice(1), feature.slug).not.toMatch(/\s[A-Z][a-z]+\s[A-Z][a-z]+/)
			expect(feature.title_nl && feature.summary_nl, feature.slug).toBeTruthy()
		}
	})
})
