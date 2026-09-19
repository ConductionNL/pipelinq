// @vitest-environment jsdom
/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Renders the comparison section of `FeaturesRoadmapView.vue` and checks that
 * the things the page is not allowed to drop are still on the screen.
 *
 * Why a render test and not a source grep. Every caveat is a plain `<p>`
 * inside a note card. Deleting one while editing the panel around it costs
 * nothing, breaks no build, and quietly turns a comparison that declares its
 * limits into one that does not. A grep would pass on a paragraph that is
 * present but unreachable because `v-if` moved; mounting will not.
 *
 * `CnFeaturesAndRoadmapPage` and the Nextcloud components are stubbed. They
 * belong to the product section rather than the comparison, they render none
 * of what this suite asserts, and importing them for real pulls a Nextcloud
 * runtime in behind them.
 *
 * @spec openspec/specs/features-roadmap/spec.md#requirement-the-comparison-must-state-its-own-limits
 * @spec openspec/specs/features-roadmap/spec.md#requirement-the-page-must-present-the-capability-comparison-by-area
 */

import { mount } from '@vue/test-utils'
import { describe, expect, it, vi } from 'vitest'
import { h } from 'vue'

// Both component libraries are replaced BEFORE the view's module graph loads.
// Neither renders any part of what this suite asserts: the caveats and the
// totals table are this view's own template.
vi.mock('@nextcloud/vue', () => ({
	NcButton: {
		name: 'NcButton',
		render() {
			return h('button', this.$slots.default?.())
		},
	},
	NcNoteCard: {
		name: 'NcNoteCard',
		props: ['type', 'heading'],
		render() {
			return h('div', this.$slots.default?.())
		},
	},
}))

vi.mock('@conduction/nextcloud-vue', () => ({
	CnFeaturesAndRoadmapPage: {
		name: 'CnFeaturesAndRoadmapPage',
		render() {
			return h('div')
		},
	},
}))

// A deterministic `t()`: the English source string with {placeholders}
// substituted. The real one needs a Nextcloud runtime, and stubbing it is what
// lets this suite assert the shipped English copy rather than a marker.
vi.mock('@nextcloud/l10n', () => ({
	getLanguage: () => 'en',
	translate: (app, text, vars) =>
		String(text).replace(/\{(\w+)\}/g, (whole, key) =>
			vars && key in vars ? String(vars[key]) : whole,
		),
}))

const { default: data } = await import('../../src/data/capabilityComparison.json')
const { default: FeaturesRoadmapView } =
	await import('../../src/views/FeaturesRoadmapView.vue')

/**
 * Mount the view and switch it to the comparison section.
 *
 * @return {Promise<object>} The mounted wrapper.
 */
async function mountComparison() {
	const wrapper = mount(FeaturesRoadmapView)
	await wrapper.setData({ section: 'comparison' })
	return wrapper
}

describe('FeaturesRoadmapView comparison caveats', () => {
	it('keeps all four mandatory caveats on the page', async () => {
		const text = (await mountComparison()).text()

		// 1. Only open source we could install and run ourselves.
		expect(text).toContain('open source software we could install and run')
		// 2. The reading date, and that it goes stale.
		expect(text).toContain('already out of date')
		// 3. A rating is our reading, not proof.
		expect(text).toContain('is not proof that a product does')
		// 4. Run your own evaluation.
		expect(text).toContain('run your own evaluation')
	})

	it('says that only our own column is ever corrected', async () => {
		const text = (await mountComparison()).text()
		expect(text).toContain('we correct our own column only')
	})

	it('declares that the list is written in our own shape', async () => {
		const text = (await mountComparison()).text()
		expect(text).toContain('that bias runs in our favour')
	})

	it('dates the reading in the reader-s own language', async () => {
		const text = (await mountComparison()).text()
		expect(text).toContain('September 9, 2026')
	})

	it('names both rivals and the real row count in the lead', async () => {
		const text = (await mountComparison()).text()
		expect(text).toContain(`on ${data.capabilities.length} capabilities`)
		expect(text).toContain('GLPI 11.0.8')
		expect(text).toContain('Zammad 7.1.3')
	})

	it('shows Unknown as a column in the totals, not as a silent gap', async () => {
		const wrapper = await mountComparison()
		const headers = wrapper
			.findAll('.features-roadmap__table thead th')
			.map((th) => th.text())

		expect(headers).toContain('Unknown')

		// Nothing is unrated in this round: both rivals were read against every
		// row. The column still renders, and it renders a zero, so the day a
		// later round adds a row without re-reading them the number moves in
		// public instead of hiding.
		const unrated = data.capabilities.filter((row) => row.addedOn).length
		const rows = wrapper.findAll('.features-roadmap__table tbody tr')
		const glpi = rows.find((row) => row.text().startsWith('GLPI'))
		expect(glpi.findAll('td').at(3).text()).toBe(String(unrated))
	})

	it('renders one open-able area per declared area, with its score', async () => {
		const wrapper = await mountComparison()
		const areas = wrapper.findAll('.features-roadmap__area')

		expect(areas).toHaveLength(data.areas.length)
		expect(areas[0].find('summary').text()).toContain('capabilities.')
	})
})
