/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 * SPDX-License-Identifier: EUPL-1.2
 *
 * The journey page as the PqJourney board draws it: the details without
 * inline edit buttons, a history of what changed, and the runs card.
 *
 * @spec openspec/changes/marketing-integrated-campaigns/specs/marketing-integrated-campaigns/spec.md
 */

import { readFileSync } from 'node:fs'
import { resolve } from 'node:path'
import { describe, expect, it } from 'vitest'

const text = (path) => readFileSync(resolve(__dirname, '../..', path), 'utf8')
const read = (path) => JSON.parse(text(path))

const detail = read('src/manifest.d/79-marketing-journeys.json').pages.find((p) => p.id === 'JourneyDetail')
const widget = (id) => detail.config.widgets.find((w) => w.id === id)
const nl = read('l10n/nl.json').translations

describe('journey details', () => {
	it('offers no inline edit: a journey is written through Edit, which compiles it', () => {
		const { include, overrides } = widget('journey-data').content
		for (const field of include) {
			expect(overrides[field]).toMatchObject({ editable: false })
		}
	})
})

describe('journey history', () => {
	const labels = [
		{ field: 'status', to: 'active', label: 'Activated by {actor}' },
		{ field: 'flowStatus', to: 'compiled', label: 'Flow compiled' },
	]

	it('lists the last three changes in the side card, not who read the journey', () => {
		const side = widget('journey-side-history')
		expect(side.type).toBe('audit-trail')
		expect(side.content).toMatchObject({ excludeActions: ['read'], maxDisplay: 3, actionLabels: { create: 'Created' } })
		expect(side.content.changeLabels).toEqual(expect.arrayContaining(labels.map((l) => expect.objectContaining(l))))
	})

	it('lists every change in the History tab, in the same words', () => {
		const tab = widget('journey-history')
		expect(tab.type).toBe('audit-trail')
		expect(tab.content).toMatchObject({ excludeActions: ['read'], actionLabels: { create: 'Created' } })
		expect(tab.content.changeLabels).toEqual(widget('journey-side-history').content.changeLabels)
	})

	it('says it in Dutch as the board does', () => {
		expect(nl['Activated by {actor}']).toBe('Geactiveerd door {actor}')
		expect(nl['Flow compiled']).toBe('Flow samengesteld')
	})
})

describe('journey runs card', () => {
	const css = text('src/components/marketing/JourneyRunsSection.vue').split('<style')[1]

	it('is titled as the board titles it', () => {
		expect(nl['What this journey did']).toBe('Wat deze journey deed')
	})

	it('draws the runs table on white with a muted head, as the board does', () => {
		expect(css).toMatch(/\.journey-runs__frame\s*\{[^}]*background:\s*var\(--color-main-background\)/)
		expect(css).toMatch(/\.journey-runs__frame\s*\{[^}]*border-radius:\s*8px/)
		expect(css).toMatch(/th\s*\{[^}]*color:\s*var\(--color-text-maxcontrast\)/)
		expect(css).toMatch(/line-height:\s*1\.2/)
	})
})
