// @vitest-environment jsdom
/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Board PqJourney: "Wat deze journey deed" is a titled card with its line
 * under the title. The library draws the title row for an app widget whose
 * registry entry asks for the card (`chrome: "card"`); without it the runs
 * sat in a card with no title (seen on :8080, 30 Oct).
 *
 * @spec openspec/changes/journey-runs-card/specs/marketing-integrated-campaigns/spec.md#requirement-the-journey-runs-card-has-a-title-row
 */

import { readFileSync } from 'node:fs'
import { resolve } from 'node:path'
import { shallowMount } from '@vue/test-utils'
import { describe, expect, it, vi } from 'vitest'

vi.mock('@conduction/nextcloud-vue', () => ({ CnStatusBadge: {} }))
vi.mock('@nextcloud/vue', () => ({ NcEmptyContent: {}, NcLoadingIcon: {} }))
vi.mock('../../src/services/journeysApi.js', () => ({ fetchJourneyRuns: vi.fn(async () => []) }))

const read = (path) => JSON.parse(readFileSync(resolve(__dirname, '../..', path), 'utf8'))
const detail = read('src/manifest.d/79-marketing-journeys.json').pages.find((p) => p.id === 'JourneyDetail')
const runs = detail.config.widgets.find((w) => w.id === 'journey-runs')
const nl = read('l10n/nl.json').translations

describe('the journey runs card', () => {
	it('asks the library for the titled card and names its line under the title', () => {
		const registry = readFileSync(resolve(__dirname, '../../src/registry.js'), 'utf8')
		const entry = registry.slice(registry.indexOf('\tJourneyRunsWidget: {'), registry.indexOf('\tCampaignReportSection: {'))
		expect(entry).toContain("chrome: 'card'")
		expect(entry).toContain('showSubtitle: false')
		expect(nl[runs.title]).toBe('Wat deze journey deed')
		expect(nl[runs.subtitle]).toBe('Elk contact dat bereikt is en elk contact dat geweigerd is')
	})

	it('draws that line itself only outside the card', async () => {
		const { default: JourneyRunsSection } = await import('../../src/components/marketing/JourneyRunsSection.vue')
		const bare = shallowMount(JourneyRunsSection, { props: { journeyId: 'j-1' }, global: { mocks: { t: (_a, s) => s } } })
		expect(bare.find('.journey-runs__subtitle').exists()).toBe(true)
		const carded = shallowMount(JourneyRunsSection, { props: { journeyId: 'j-1', showSubtitle: false }, global: { mocks: { t: (_a, s) => s } } })
		expect(carded.find('.journey-runs__subtitle').exists()).toBe(false)
	})
})
