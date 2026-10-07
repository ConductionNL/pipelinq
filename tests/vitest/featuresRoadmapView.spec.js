// @vitest-environment jsdom
/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 * SPDX-License-Identifier: EUPL-1.2
 *
 * The Features & roadmap page no longer carries the capability comparison,
 * and it links to the page that does.
 *
 * The help desk comparison left the app on 2026-10-07 for pipelinq.conduction.nl/compare
 * (Ruben: how an app compares belongs on its public site, for every app). A
 * removed surface must stay reachable, so this suite pins both halves: the
 * section is gone, and the link to its new home is there, opens in a new tab and
 * lands on the reader's own language.
 *
 * `CnFeaturesAndRoadmapPage` is stubbed: it is the library's own component and
 * pulls a Nextcloud runtime in behind it.
 *
 * @spec openspec/specs/features-roadmap/spec.md#requirement-the-page-must-link-to-the-comparison-on-the-public-site
 */

import { mount } from '@vue/test-utils'
import { afterEach, describe, expect, it, vi } from 'vitest'
import { h } from 'vue'

const language = vi.hoisted(() => ({ value: 'en' }))

vi.mock('@nextcloud/l10n', () => ({
	getLanguage: () => language.value,
	translate: (app, text, vars) =>
		String(text).replace(/\{(\w+)\}/g, (whole, key) =>
			vars && key in vars ? String(vars[key]) : whole,
		),
}))

vi.mock('@conduction/nextcloud-vue', () => ({
	CnFeaturesAndRoadmapPage: {
		name: 'CnFeaturesAndRoadmapPage',
		render() {
			return h('div', { class: 'cn-features-and-roadmap-view' })
		},
	},
}))

const { default: FeaturesRoadmapView } =
	await import('../../src/views/FeaturesRoadmapView.vue')

afterEach(() => {
	language.value = 'en'
})

describe('FeaturesRoadmapView', () => {
	it('renders the library page and no comparison', () => {
		const wrapper = mount(FeaturesRoadmapView, {
			props: { documentationUrl: 'https://pipelinq.conduction.nl' },
		})

		expect(wrapper.find('.cn-features-and-roadmap-view').exists()).toBe(true)
		// The section toggle, the comparison section and its tables are all gone.
		expect(wrapper.find('[role="group"]').exists()).toBe(false)
		expect(wrapper.find('.features-roadmap__comparison').exists()).toBe(false)
		expect(wrapper.find('table').exists()).toBe(false)
		const text = wrapper.text()
		expect(text).not.toContain('Before you use this table')
		expect(text).not.toContain('Totals over all')
	})

	it('links to the comparison on the public site, in a new tab', () => {
		const wrapper = mount(FeaturesRoadmapView, {
			props: { documentationUrl: 'https://pipelinq.conduction.nl/' },
		})
		const link = wrapper.find('a.features-roadmap__compare-link')

		expect(link.exists()).toBe(true)
		expect(link.text()).toBe('How pipelinq compares to other help desks')
		expect(link.attributes('href')).toBe(
			'https://pipelinq.conduction.nl/compare',
		)
		expect(link.attributes('target')).toBe('_blank')
		expect(link.attributes('rel')).toContain('noopener')
		// The new-tab warning is tied to the link, so a screen reader hears it.
		const hint = wrapper.find(`#${link.attributes('aria-describedby')}`)
		expect(hint.text()).toBe('Opens pipelinq.conduction.nl in a new tab.')
	})

	it('falls back to the public site when the manifest names none', () => {
		const wrapper = mount(FeaturesRoadmapView)
		expect(
			wrapper.find('a.features-roadmap__compare-link').attributes('href'),
		).toBe('https://pipelinq.conduction.nl/compare')
	})

	it('sends a Dutch reader to the Dutch page', () => {
		language.value = 'nl'
		const wrapper = mount(FeaturesRoadmapView, {
			props: { documentationUrl: 'https://pipelinq.conduction.nl' },
		})
		expect(
			wrapper.find('a.features-roadmap__compare-link').attributes('href'),
		).toBe('https://pipelinq.conduction.nl/nl/compare')
	})
})
