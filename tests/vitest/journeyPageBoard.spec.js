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

describe('journey steps', () => {
	const steps = widget('journey-steps')
	const field = (label) => steps.content.fields.find((f) => f.label === label)

	it('sits in the details card, under the facts, with no rule between them', () => {
		const card = widget('journey-details')
		expect(card).toMatchObject({ type: 'sections', title: 'Journey details' })
		expect(card.content.dividers).toBe(false)
		expect(card.content.sections.map((s) => s.widgetId)).toEqual(['journey-data', 'journey-steps'])
		const overview = widget('journey-panels').content.tabs[0]
		expect(overview.widgetIds).toEqual(['journey-details', 'journey-runs'])
	})

	it('draws the steps as tiles', () => {
		expect(steps.content.layout).toBe('tiles')
		expect(steps.content.fields.map((f) => f.label)).toEqual(['Trigger', 'Wait', 'Condition', 'Action'])
	})

	it('names the trigger and the action in their own words, else by their kind', () => {
		for (const [label, path] of [['Trigger', 'trigger'], ['Action', 'action']]) {
			const [own, kind] = field(label).firstOf
			expect(own).toBe(`${path}.label`)
			expect(kind.path).toBe(`${path}.kind`)
			expect(Object.keys(kind.map).length).toBeGreaterThan(1)
		}
	})

	it('says the condition and the wait in words', () => {
		expect(field('Condition').map.marketingConsentEmail).toBe('Has consent for e-mail marketing')
		expect(nl['Has consent for e-mail marketing']).toBe('Heeft toestemming voor marketing per e-mail')
		expect(field('Wait').format).toBe('duration')
	})
})

describe('journey page parts', () => {
	it('lists the side history as dates beside what happened', () => {
		expect(widget('journey-side-history').content.layout).toBe('dated')
	})

	it('counts the changes on the History tab', () => {
		const history = widget('journey-panels').content.tabs[1]
		expect(history.countFrom).toEqual({ auditTrail: true, excludeActions: ['read'] })
	})

	it('puts the steps 14px under the facts', () => {
		expect(widget('journey-details').content.gap).toBe(14)
	})
})
