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
import { readFileSync } from 'node:fs'
import { resolve } from 'node:path'
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
		props: { board: { type: Object, default: null }, compareUrl: { type: [String, Object], default: '' }, compareLabel: { type: String, default: '' } },
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

	it('hands the comparison on the public site to the library page, which draws the link', () => {
		const wrapper = mount(FeaturesRoadmapView, {
			props: { documentationUrl: 'https://pipelinq.conduction.nl/' },
		})
		const page = wrapper.findComponent({ name: 'CnFeaturesAndRoadmapPage' })

		expect(page.props('compareUrl')).toBe('https://pipelinq.conduction.nl/compare')
		expect(page.props('compareLabel')).toBe('How pipelinq compares to other help desks')
		// One link only: the page draws no copy of its own beside the library's.
		expect(wrapper.find('a.features-roadmap__compare-link').exists()).toBe(false)
	})

	it('falls back to the public site when the manifest names none', () => {
		const wrapper = mount(FeaturesRoadmapView)
		expect(wrapper.findComponent({ name: 'CnFeaturesAndRoadmapPage' }).props('compareUrl')).toBe('https://pipelinq.conduction.nl/compare')
	})

	it('sends a Dutch reader to the Dutch page', () => {
		language.value = 'nl'
		const wrapper = mount(FeaturesRoadmapView, {
			props: { documentationUrl: 'https://pipelinq.conduction.nl' },
		})
		expect(wrapper.findComponent({ name: 'CnFeaturesAndRoadmapPage' }).props('compareUrl')).toBe('https://pipelinq.conduction.nl/nl/compare')
	})

	it('hands the manifest board options to the library page (PqRoadmap subtitle)', () => {
		const board = { subtitle: 'What Pipelinq does now and what is coming, {count} features' }
		const wrapper = mount(FeaturesRoadmapView, { props: { board } })
		expect(wrapper.findComponent({ name: 'CnFeaturesAndRoadmapPage' }).props('board')).toEqual(board)
	})

	it('draws the board subtitle with the feature count, as PqRoadmap does', () => {
		const manifest = JSON.parse(readFileSync(resolve(__dirname, '../../src/manifest.json'), 'utf8'))
		const page = manifest.pages.find((p) => p.id === 'FeaturesRoadmap')
		expect(page.config.board.subtitle).toBe('What Pipelinq does now and what is coming, {count} features')
		const nl = JSON.parse(readFileSync(resolve(__dirname, '../../l10n/nl.json'), 'utf8'))
		expect(nl.translations[page.config.board.subtitle]).toBe('Wat Pipelinq nu doet en wat eraan komt, {count} functies')
	})
})
